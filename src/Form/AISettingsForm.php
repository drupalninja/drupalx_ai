<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
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
      '#title' => $this->t('API Chat Completions URL / Base URL'),
      '#default_value' => $config->get('api_endpoint'),
      '#description' => $this->t('Enter the full URL for the chat completions endpoint (e.g., <code>https://api.example.com/v1/chat/completions</code>) or just the base URL (e.g., <code>https://api.example.com/v1</code>). If the full path is not provided, <code>/chat/completions</code> will be assumed by the client.'),
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

    // Image service settings.
    $form['image_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Image Service Settings'),
      '#open' => TRUE,
    ];

    $form['image_settings']['image_generator'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Service'),
      '#options' => [
        'placeholder' => $this->t('Placeholder Images'),
        'pexels' => $this->t('Pexels'),
        'unsplash' => $this->t('Unsplash'),
      ],
      '#default_value' => $config->get('image_generator') ?: 'placeholder',
      '#description' => $this->t('Select the image service to use for generating images. Placeholder will use static placeholder images.'),
      '#required' => TRUE,
    ];

    $form['image_settings']['pexels_api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Pexels API Key'),
      '#options' => $key_options,
      '#default_value' => $config->get('pexels_api_key'),
      '#description' => $this->t('Select the Pexels API key configured in the Key module. Only the key machine name is stored here - actual API keys are securely managed by the Key module. Required when using Pexels as the image service.'),
      '#empty_option' => $this->t('- Select a key -'),
      '#states' => [
        'visible' => [
          ':input[name="image_generator"]' => ['value' => 'pexels'],
        ],
        'required' => [
          ':input[name="image_generator"]' => ['value' => 'pexels'],
        ],
      ],
    ];

    $form['image_settings']['unsplash_api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Unsplash API Key'),
      '#options' => $key_options,
      '#default_value' => $config->get('unsplash_api_key'),
      '#description' => $this->t('Select the Unsplash API key configured in the Key module. Only the key machine name is stored here - actual API keys are securely managed by the Key module. Required when using Unsplash as the image service.'),
      '#empty_option' => $this->t('- Select a key -'),
      '#states' => [
        'visible' => [
          ':input[name="image_generator"]' => ['value' => 'unsplash'],
        ],
        'required' => [
          ':input[name="image_generator"]' => ['value' => 'unsplash'],
        ],
      ],
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
      ->set('image_generator', $form_state->getValue('image_generator'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
