<?php

declare(strict_types=1);

namespace Drupal\cinatra\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Cinatra settings form — Cinatra URL + API key.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cinatra_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['cinatra.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('cinatra.settings');

    $form['cinatra_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Cinatra URL'),
      '#description' => $this->t('Base URL of your Cinatra instance (e.g. http://localhost:3000). The widget bundle is fetched from {URL}/api/drupal/bundle.js.'),
      '#default_value' => $config->get('cinatra_url') ?? '',
      '#required' => TRUE,
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Bearer key generated in Cinatra at /settings/connectors/drupal-widget. Used as Authorization: Bearer <key> by the widget.'),
      '#default_value' => $config->get('api_key') ?? '',
      '#required' => TRUE,
    ];
    $form['instance_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cinatra instance ID (optional)'),
      '#description' => $this->t('Paste the instance ID shown in Cinatra at /settings/connectors/drupal-widget. Used to scope agent operations to this site.'),
      '#default_value' => $config->get('instance_id') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('cinatra.settings')
      ->set('cinatra_url', $form_state->getValue('cinatra_url'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('instance_id', $form_state->getValue('instance_id'))
      ->save();
    // The bundle URL is derived from cinatra_url in hook_library_info_alter(),
    // so the cached library definitions must be rebuilt after a settings change.
    \Drupal::service('library.discovery')->clearCachedDefinitions();
    parent::submitForm($form, $form_state);
  }

}
