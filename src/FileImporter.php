<?php

namespace Drupal\ksp_filelibrary;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use EasyRdf\Graph as EasyRdf_Graph;
use EasyRdf\Parser\Turtle as EasyRdf_Parser_Turtle;
use ZipArchive;
use Drupal\Component\Utility\UrlHelper;

/**
 * Class FileImporter.
 */
class FileImporter {

  /**
   * @var \Drupal\file\Entity\File
   */
  protected $archive;

  /**
   * @var string
   */
  protected $archiveDirectory;

  /**
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fs;

  /**
   * @var EasyRdf_Graph
   */
  protected $graph;

  /**
   * @var array
   */
  protected $graphIndex = [];

  /**
   * @var array
   */
  protected $mainProperties = [];

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var array
   */
  public $errorsProperties = [];

  /**
   * FileImporter constructor.
   *
   * @param \Drupal\file\Entity\File $file
   */
  public function __construct(File $file) {
    $this->archive = $file;
    $this->fs = \Drupal::service('file_system');
    $this->logger = \Drupal::logger('ksp_file_importer');
  }

  /**
   * Imports all objects from RDF.
   *
   * @throws \Exception
   */
  public function importLinks() {
    $this->logger->info('Import started.');
    $this->extractArchive();

    $this->importFiles();
    $this->importLocations();
  }

  /**
   * Main function of files import.
   *
   * @throws
   */
  public function importFiles() {
    $this->logger->info('Import files start.');
    $importedCount = 0;
    $failedCount = 0;
    $queue = \Drupal::queue('cron_redirects_processor');
    $links = $this->getGraph()->allOfType('dcterms:BibliographicResource');

    foreach ($links as $link) {
      $filePath = $link->get('<https://www.w3.org/Addressing/url>')->getValue();
      $fileLocation = str_replace('file://', $this->archiveDirectory . '/', $filePath);

      if (!file_exists($fileLocation)) {
        $this->logger->error("Reference file '@reference' doesn't exist.", ['@reference' => $filePath]);

        throw new FileException("Referenced file '{$filePath}' not found. Make sure file exists in archive and the name is correct.");
      }

      $referenceUrl = $link->getUri();

      if (UrlHelper::isValid($referenceUrl, TRUE)) {
        // Save file.
        [$fid, $redirectUrl] = $this->saveFile($fileLocation, 1, $referenceUrl);

        if (empty($fid)) {
          $this->logger->error("File '@file' save failed.", ['@file' => $referenceUrl]);
          $failedCount++;
        } else {
          $referencedItems = $this->graph->resourcesMatching('dcterms:references', [
            'value' => $referenceUrl,
            'type' => 'uri',
          ]);

          if (!empty($referencedItems)) {
            foreach ($referencedItems as $refItem) {
              $importedCount++;
              $integration = $this->getIntegrationByUuid($refItem->getUri());
              if (!empty($integration)) {
                $integrationFiles = $integration->get('field_files')->getValue();
                $integrationFiles[] = [
                  'target_id' => $fid,
                ];
                $integration->set('field_files', $integrationFiles);
                $integration->save();
              }
            }
          }
        }

        $queue->createItem([
          'RealUrl' => $referenceUrl,
          'RedirectUrl' => $redirectUrl,
          'MediaId' => $fid,
        ]);
      }
      else {
        $this->errorsProperties[] = $referenceUrl;
      }
    }

    $this->logger->info('Import of files finished. Imported @importedCount items, @failedCount items', [
      '@importedCount' => $importedCount,
      '@failedCount' => $failedCount,
    ]);
  }

  function importLocations() {
    $this->logger->info('Import locations start.');
    $links = $this->getGraph()->allOfType('https://www.w3.org/TR/prov-o/#Location');
    $queue = \Drupal::queue('cron_redirects_processor');
    $importedCount = $failedCount = 0;
    foreach ($links as $link) {
      try {
        $referenceUrl = $link->getUri();

        $redirectUrl = $link->get('<https://www.w3.org/Addressing/url>')
          ->getValue();

        if (empty($redirectUrl)) {
          throw new \Exception('Redirect url missing');
        }

        if (UrlHelper::isValid($referenceUrl, TRUE)) {
          $queue->createItem([
            'RealUrl' => $referenceUrl,
            'RedirectUrl' => $redirectUrl,
          ]);
        }
        else {
          $this->errorsProperties[] = $referenceUrl;
        }

        $importedCount++;
      } catch (\Exception $e) {
        $this->logger->error('Filed to import message: @message,  link: @link',
          [
            '@link' => var_export($link, TRUE),
            '@message' => $e->getMessage(),
          ]);
        $failedCount++;
      }
    }

    $this->logger->info('Import of links finished. Imported @importedCount items, @failedCount items', [
      '@importedCount' => $importedCount,
      '@failedCount' => $failedCount,
    ]);

  }

  /**
   * Extract archive to directory and store the path in variable.
   *
   * @throws \Exception
   */
  protected function extractArchive() {
    $this->logger->info('Extracting archive.');
    $zipArchive = new ZipArchive();

    $path = $this->fs->realpath($this->archive->getFileUri());

    $this->logger->info('just about to open archive. path: ' . var_export($path, TRUE));
    $this->logger->info('file size of archive is: ' . var_export($this->archive->getSize(), TRUE));

    $result = $zipArchive->open($path);

    $this->logger->info('Tried to open. result: ' . var_export($result, TRUE));

    if ($result === TRUE) {
      $dir = \Drupal::root() . '/' . \Drupal::service('file_system')->getTempDirectory() . '/' . date('dmYHi');
      $zipArchive->extractTo($dir);

      $this->archiveDirectory = $dir;
    } else {
      $this->logger->error('Failed extracting archive.');

      throw new \Exception('Failed extracting archive.');
    }

    $zipArchive->close();
  }

  /**
   * Saves main properties in $this->mainProperties.
   */
  protected function findMainProperties() {
    $regExp = '/^http:\/\/www\.qualiware\.com\/guid-/';

    foreach ($this->graphIndex as $key => $item) {
      if (preg_match($regExp, $key)) {
        $this->mainProperties[] = $key;
      }
    }
  }

  /**
   * Return integration by $uuid.
   *
   * @param $uuid
   *
   * @return \Drupal\node\Entity\Node|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getIntegrationByUuid($uuid) {
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();

    $nids = $query->condition('type', ['integration', 'integration_version'], 'IN')
      ->condition('field_uuid', $uuid)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return (!empty($nids) ? Node::load(reset($nids)) : NULL);
  }

  /**
   * @param $fileLocation
   * @param $uid
   * @param $referenceUrl
   *
   * @return int|string|null
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveFile($fileLocation, $uid, $referenceUrl) {
    $destination = 'public://integration-files/' . date('dmYHi') . '/';
    $fileData = file_get_contents($fileLocation);
    $this->fs->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
    $filename = $this->fs->basename($fileLocation);
    $file = \Drupal::service('file.repository')->writeData($fileData, $destination . $filename);

    if ($file) {
      $file->setPermanent();
      $file->save();
      $media = Media::create([
        'bundle' => 'file',
        'uid' => $uid,
        'field_media_file' => [
          'target_id' => $file->id(),
        ],
      ]);
      $media->set('name', $filename)
        ->set('field_reference_url', $referenceUrl)
        ->setUnpublished()
        ->save();

      $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());

      return [$media->id(), $url];
    }

    return NULL;
  }

  protected function getGraph() {
    if (empty($this->graph)) {
      $manifestFileMask = '/.*\\.n3$/';
      $files = \Drupal::service('file_system')->scanDirectory($this->archiveDirectory, $manifestFileMask);

      if (empty($files)) {
        $this->logger->error("Manifest file doesn't exist.");

        throw new FileException("Couldn't find manifest file in archive.");
      }

      $manifestFile = reset($files);

      $parser = new EasyRdf_Parser_Turtle();
      $this->graph = new EasyRdf_Graph();
      $data = file_get_contents($manifestFile->uri);
      $parser->parse($this->graph, $data, 'turtle', $manifestFile->uri);
    }

    return $this->graph;
  }

}
