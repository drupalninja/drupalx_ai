<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form for configuring AI settings.
 */
class AISettingsForm extends ConfigFormBase {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs an AISettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository) {
    parent::__construct($config_factory);
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('key.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupalx_ai_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['drupalx_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('drupalx_ai.settings');

    $form['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Endpoint URL'),
      '#default_value' => $config->get('api_endpoint'),
      '#description' => $this->t('The base URL for the OpenAI compatible API. E.g., https://api.openai.com/v1'),
      '#required' => TRUE,
    ];

    $form['model_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name'),
      '#default_value' => $config->get('model_name'),
      '#description' => $this->t('The name of the AI model to use. E.g., gpt-3.5-turbo'),
      '#required' => TRUE,
    ];

    $key_options = [];
    $keys = $this->keyRepository->getKeys();
    foreach ($keys as $key) {
      $key_options[$key->id()] = $key->label() . ' (' . $key->id() . ')';
    }

    $form['api_key_id'] = [
      '#type' => 'select',
      '#title' => $this->t('API Key'),
      '#options' => $key_options,
      '#default_value' => $config->get('api_key_id'),
      '#description' => $this->t('Select the API key configured in the Key module. Ensure the key type is appropriate (e.g., Authentication).'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a key -'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('drupalx_ai.settings')
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('model_name', $form_state->getValue('model_name'))
      ->set('api_key_id', $form_state->getValue('api_key_id'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
