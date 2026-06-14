<?php

declare(strict_types=1);

namespace Drupal\cinatra\Form;

use Drupal\cinatra\CinatraUrl;
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
    // The API key is a long-lived credential held server-side only — it is
    // NEVER sent to the browser (the browser receives short-lived tokens minted
    // by the cinatra.token broker). Render it as a password field and do not
    // echo the stored secret back into the form; an empty submit keeps the
    // current value. A placeholder signals when a key is already saved.
    $has_key = ((string) $config->get('api_key')) !== '';
    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Bearer key generated in Cinatra at /settings/connectors/drupal-widget. Held server-side and exchanged for short-lived tokens — it is never exposed to the browser. Leave blank to keep the current key.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'placeholder' => $has_key ? $this->t('•••••••• (a key is saved; leave blank to keep it)') : '',
      ],
      // Required only when no key is stored yet.
      '#required' => !$has_key,
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    // The Cinatra URL is the origin this module talks to server-to-server (the
    // long-lived API key is sent there as a Bearer header) and that the browser
    // loads the widget runtime from. Constrain it to a safe HTTPS origin (HTTP
    // only for loopback hosts in local dev) so it cannot be pointed at an
    // arbitrary scheme/host — an SSRF / credential-exfiltration control, not
    // mere cleanup. The '#type' => 'url' element only checks URL syntax.
    $url = (string) $form_state->getValue('cinatra_url');
    if ($url !== '' && !CinatraUrl::isValid($url)) {
      $form_state->setErrorByName('cinatra_url', $this->t('The Cinatra URL must be an HTTPS origin (for example https://app.example.com). Plain HTTP is accepted only for local hosts such as http://localhost:3000. Remove any path, query, credentials, or fragment.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('cinatra.settings');
    // Persist the normalized origin (scheme://host[:port], no trailing slash)
    // so downstream callers do not each have to re-normalize.
    $normalized_url = CinatraUrl::normalize((string) $form_state->getValue('cinatra_url'));
    $config
      ->set('cinatra_url', $normalized_url ?? '')
      ->set('instance_id', $form_state->getValue('instance_id'));
    // The API key is a password field that is never pre-filled with the stored
    // secret; an empty submission means "keep the current key" rather than
    // "clear it". Only overwrite when a non-empty value was entered.
    $submitted_key = (string) $form_state->getValue('api_key');
    if ($submitted_key !== '') {
      $config->set('api_key', $submitted_key);
    }
    $config->save();
    // The widget bundle now ships locally (cinatra/bundle references
    // js/cinatra-widget.js); there is no longer a runtime library rewrite to
    // invalidate, so no library.discovery cache clear is needed here.
    parent::submitForm($form, $form_state);
  }

}
