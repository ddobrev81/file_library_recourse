<?php

namespace Drupal\ksp_filelibrary\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class RedirectServiceConfigForm.
 */
class RedirectServiceConfigForm extends ConfigFormBase {

  public const CONFIG_NAME = 'ksp_filelibrary.redirect_service';

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ksp_redirect_service_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $form['prod_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect service prod url'),
      '#default_value' => $config->get('prod_url'),
    ];
    $form['stage_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect service stage url'),
      '#default_value' => $config->get('stage_url'),
    ];
    $form['service_name'] = [
      '#type' => 'textfield',
      '#title' => t('Service name'),
      '#default_value' => $config->get('service_name'),
    ];
    $form['hash'] = [
      '#type' => 'textfield',
      '#title' => t('Hash'),
      '#default_value' => $config->get('hash'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $values = $form_state->getValues();

    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set('prod_url', $values['prod_url'])
      ->set('stage_url', $values['stage_url'])
      ->set('service_name', $values['service_name'])
      ->set('hash', $values['hash'])
      ->save();
  }

}
