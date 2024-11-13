<?php

namespace Drupal\ksp_filelibrary\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\ksp_filelibrary\Form\RedirectServiceConfigForm;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media\Entity\Media;

/**
 * @QueueWorker(
 *   id = "cron_redirects_processor",
 *   title = @Translation("Cron Redirects processor"),
 *   cron = {"time" = 30}
 * )
 */
class RedirectsProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /** @var \GuzzleHttp\Client */
  protected $client;

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected $configFactory;

  /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface */
  protected $logger;

  /**
   * {@inheritdoc}
   *
   * @param \GuzzleHttp\Client $httpClient
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $httpClient, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->client = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger->get('ksp_redirect_service');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (empty($data['RealUrl']) || empty($data['RedirectUrl'])) {
      $date = date('Y-m-d H:i:s');
      $res = var_export($data, TRUE);
      $this->logger->error("Missing Real and Redirect url in sent data. Data: {$res}, Date: {$date}");

      throw new \Exception('Missing Real and Redirect url in sent data.');
    }

    $config = $this->configFactory->getEditable(RedirectServiceConfigForm::CONFIG_NAME);

    if (getenv('ENVIRONMENT') == 'prod') {
      $url = $config->get('prod_url');
    }
    else {
      $url = $config->get('stage_url');
    }

    $url .= ((mb_substr($url, -1) == '/') ? 'redirect' : '/redirect');

    try {
      $request = $this->client->request(
        'POST',
        $url,
        [
          'query' => [
            'RealUrl' => $data['RealUrl'],
            'RedirectUrl' => $data['RedirectUrl'],
            'status' => TRUE,
          ],
          'auth' => [
            $config->get('service_name'),
            $config->get('hash'),
          ],
          'http_errors' => FALSE,
        ]
      );

      if ($request->getStatusCode() == 200) {
        // Publish media.
        if (isset($data['MediaId']) && !empty($data['MediaId'])) {
          $media = Media::load($data['MediaId']);
          if ($media) {
            $media->setPublished()->save();
          }
        }
        return TRUE;
      }

      $response = $request->getBody()->getContents();
    } catch (\Exception $e) {
      $response = '';
    }

    $date = date('Y-m-d H:i:s');
    $this->logger->error("Request to redirect service failed. RealUrl: {$data['RealUrl']}, RedirectUrl: {$data['RedirectUrl']}, Response: {$response}, Date: {$date}");

    throw new \Exception('Request to redirect service failed.');
  }

}
