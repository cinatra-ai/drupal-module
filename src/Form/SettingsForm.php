<?php

declare(strict_types=1);

namespace Drupal\cinatra\Form;

use Drupal\cinatra\CinatraUrl;
use Drupal\cinatra\Controller\ConnectController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cinatra settings form.
 *
 * The primary onboarding path is one-click Connect (cinatra#221):
 * the admin enters only the instance URL, approves a consent screen on Cinatra,
 * and the integration credential is provisioned server-side automatically. No
 * key is ever copied or pasted. A connection-string fallback covers the
 * environments where the browser redirect is not viable. The manual fields
 * remain available (under "Manual configuration") for advanced setups.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ConnectController $connectController,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      ConnectController::create($container),
    );
  }

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
    $has_key = ((string) $config->get('api_key')) !== '';
    $current_url = (string) ($config->get('cinatra_url') ?? '');
    $is_connected = $current_url !== '' && $has_key;

    // --- One-click Connect (primary path) ---------------------------------
    $form['connect'] = [
      '#type' => 'details',
      '#title' => $this->t('Connect to Cinatra'),
      '#open' => TRUE,
    ];
    $form['connect']['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('Enter your Cinatra instance URL and click Connect. You will be sent to Cinatra to approve the connection; the integration credential is then provisioned automatically and stored on this server. You never copy or paste a key.'),
    ];
    if ($is_connected) {
      $form['connect']['status'] = [
        '#type' => 'item',
        '#markup' => $this->t('Currently connected to <strong>@url</strong>. Reconnecting replaces the stored credential.', ['@url' => $current_url]),
      ];
    }
    $form['connect']['connect_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Cinatra instance URL'),
      '#description' => $this->t('Base URL of your Cinatra instance (for example https://app.example.com, or http://localhost:3000 for local development).'),
      '#default_value' => $current_url,
    ];
    $form['connect']['connect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect with Cinatra'),
      '#submit' => ['::submitConnect'],
      // The Connect path provisions the credential itself, so it must NOT run
      // the manual api_key/url validation (which would block first-time setup).
      '#limit_validation_errors' => [['connect_url']],
    ];

    // --- Connection-string fallback ---------------------------------------
    $form['connect']['fallback'] = [
      '#type' => 'details',
      '#title' => $this->t('No browser redirect? Use a connection string instead'),
      '#open' => FALSE,
    ];
    $form['connect']['fallback']['connection_string'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Connection string from Cinatra'),
      '#description' => $this->t('Paste the one-line connection string generated in Cinatra. It encodes the instance URL and a one-time install code.'),
      '#rows' => 2,
    ];
    $form['connect']['fallback']['connect_install_code'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect with code'),
      '#submit' => ['::submitInstallCode'],
      '#limit_validation_errors' => [['connection_string']],
    ];

    // --- Manual configuration (advanced) ----------------------------------
    // Open by default so the canonical connection fields (Cinatra URL + API
    // key) are always rendered and visible — they are the documented, stable
    // configuration surface (and what automated config UATs assert against). A
    // collapsed <details> hides its children from a real browser even when the
    // site is already connected, which would make the URL/key fields appear
    // "missing"; keeping it open avoids that while the primary one-click
    // Connect path remains the first, prominent section above.
    $form['manual'] = [
      '#type' => 'details',
      '#title' => $this->t('Manual configuration (advanced)'),
      '#open' => TRUE,
      '#description' => $this->t('Most sites should use Connect above. These fields let you set or override the connection manually.'),
    ];
    $form['manual']['cinatra_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Cinatra URL'),
      '#description' => $this->t('Base URL of your Cinatra instance (e.g. http://localhost:3000). The widget JavaScript is shipped locally inside this module (js/cinatra-widget.js) and is never remote-loaded from the Cinatra instance; this URL is used for the same-origin token broker and the versioned data API only.'),
      '#default_value' => $current_url,
    ];
    // The API key is a long-lived credential held server-side only — it is
    // NEVER sent to the browser (the browser receives short-lived tokens minted
    // by the cinatra.token broker). Render it as a password field and do not
    // echo the stored secret back into the form; an empty submit keeps the
    // current value. A placeholder signals when a key is already saved.
    $form['manual']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Bearer key generated in Cinatra at /settings/connectors/drupal-widget. Held server-side and exchanged for short-lived tokens — it is never exposed to the browser. Leave blank to keep the current key.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'placeholder' => $has_key ? $this->t('•••••••• (a key is saved; leave blank to keep it)') : '',
      ],
    ];
    $form['manual']['instance_id'] = [
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
    // mere cleanup. The '#type' => 'url' element only checks URL syntax. This
    // runs only on the main Save path (the Connect/install-code buttons scope
    // their validation to their own field via '#limit_validation_errors').
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
    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the "Connect with Cinatra" button.
   *
   * Starts the redirect handshake via the connect controller; the response is a
   * redirect to the Cinatra consent page (or back here on a bad URL).
   */
  public function submitConnect(array &$form, FormStateInterface $form_state): void {
    $url = (string) $form_state->getValue('connect_url');
    $form_state->setResponse($this->connectController->start($url));
  }

  /**
   * Submit handler for the connection-string ("Connect with code") fallback.
   */
  public function submitInstallCode(array &$form, FormStateInterface $form_state): void {
    $string = (string) $form_state->getValue('connection_string');
    $form_state->setResponse($this->connectController->installCode($string));
  }

}
