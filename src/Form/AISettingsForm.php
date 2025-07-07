<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai\AiProviderPluginManager;

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
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager|null
   */
  protected ?AiProviderPluginManager $aiProviderManager;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * Constructs an AISettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\ai\AiProviderPluginManager|null $ai_provider_manager
   *   The AI provider plugin manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, KeyRepositoryInterface $key_repository, ?AiProviderPluginManager $ai_provider_manager = NULL) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->typedConfigManager = $typed_config_manager;
    $this->keyRepository = $key_repository;
    $this->aiProviderManager = $ai_provider_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $ai_provider_manager = NULL;
    if ($container->has('ai.provider')) {
      $ai_provider_manager = $container->get('ai.provider');
    }
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('key.repository'),
      $ai_provider_manager
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

    // AI Provider Selection
    $form['ai_provider_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Provider Settings'),
      '#open' => TRUE,
    ];

    $ai_module_available = $this->aiProviderManager !== NULL;
    if (!$ai_module_available) {
      $form['ai_provider_settings']['ai_module_warning'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('The AI module is not available. Please install and enable the AI module to configure AI providers.') . '</div>',
      ];
    }

    // Get available AI provider/model configurations from the AI module
    $provider_model_options = [];
    if ($this->aiProviderManager) {
      try {
        // Get all configured provider instances with their available models
        $providers = $this->aiProviderManager->getDefinitions();
        foreach ($providers as $provider_id => $provider_definition) {
          try {
            // Try to get a configured instance
            $provider_instance = $this->aiProviderManager->createInstance($provider_id);
            
            // Add provider with default model option
            $provider_model_options[$provider_id . ':default'] = $provider_definition['label'] . ' - ' . $this->t('Default Model');
            
            // TODO: In future versions, could enumerate specific models if provider supports it
            // For now, each provider gets a "default" option that uses the provider's default model
            
          } catch (\Exception $e) {
            // Provider not configured, skip
          }
        }
      } catch (\Exception $e) {
        // AI module not available
      }
    }

    $form['ai_provider_settings']['ai_provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider Configuration'),
      '#options' => $provider_model_options,
      '#default_value' => $config->get('ai_provider_model'),
      '#description' => $ai_module_available ? 
        $this->t('Select which Drupal AI module provider configuration to use for DrupalX AI operations. This will use the provider\'s configuration as set up in the <a href="@ai_settings">main AI module settings</a>.', [
          '@ai_settings' => \Drupal\Core\Url::fromRoute('ai.admin_providers')->toString(),
        ]) :
        $this->t('AI module not available. Install the AI module to see available providers.'),
      '#empty_option' => $this->t('- Select AI provider configuration -'),
      '#disabled' => !$ai_module_available,
      '#required' => $ai_module_available,
    ];

    // Get key options for image service API keys
    $key_options = [];
    $keys = $this->keyRepository->getKeys();
    foreach ($keys as $key) {
      $key_options[$key->id()] = $key->label() . ' (' . $key->id() . ')';
    }

    // AI prompt settings.
    $form['prompt_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Prompt Settings'),
      '#open' => FALSE,
    ];

    $default_prompt = $this->getDefaultSystemPrompt();
    $form['prompt_settings']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt'),
      '#default_value' => $config->get('system_prompt') ?: $default_prompt,
      '#description' => $this->t('The system prompt used to instruct the AI on how to generate components. Use {allowed_types} as a placeholder for the list of allowed component types and {components_json} as a placeholder for the JSON data of sample components. The default prompt is loaded from files/default-system-prompt.txt in the module directory.'),
      '#rows' => 20,
      '#cols' => 80,
      '#required' => TRUE,
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
      ->set('ai_provider_model', $form_state->getValue('ai_provider_model'))
      ->set('system_prompt', $form_state->getValue('system_prompt'))
      ->set('image_generator', $form_state->getValue('image_generator'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the default system prompt.
   *
   * @return string
   *   The default system prompt template.
   */
  protected function getDefaultSystemPrompt(): string {
    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');
    $prompt_file_path = DRUPAL_ROOT . '/' . $module_path . '/files/default-system-prompt.txt';

    if (file_exists($prompt_file_path)) {
      $prompt_content = file_get_contents($prompt_file_path);
      if ($prompt_content !== FALSE) {
        return $prompt_content;
      }
    }

    // Fallback to hardcoded prompt if file cannot be read.
    return <<<EOT
You are an AI assistant helping to build a webpage using predefined UI components.
Your primary goal is to select appropriate components based on the user's description and the provided library of components.

INSTRUCTIONS:
1.  First, on a line by itself, suggest a clear and compelling title for this landing page. Format it exactly as: "PAGE_TITLE: Your Suggested Page Title Here".
2.  Then, on subsequent lines, provide the JSON data for the recommended UI components. This JSON should be enclosed in a standard markdown code block (```json ... ```).
3.  The JSON must be an array of component objects.
4.  Aim to provide between 5 and 6 components in total to construct the page.
5.  Each component object in your response MUST include a `type` field.
6.  IMPORTANT: The value of the `type` field for each component MUST be one of the following allowed Drupal Paragraph bundle machine names: {allowed_types}.
    Do not invent new `type` values. Only use types from this list.
7.  Each component in your response must match the structure and fields shown in the example components provided below (respecting the `type`). Do not change other field names (keys).
8.  For any fields representing images (e.g., fields with "image" or "media" in their name), the 'alt' text MUST be a brief, thematic, and descriptive phrase for the image. Avoid generic placeholders.
9.  CRITICAL IMAGE ALT TEXT INSTRUCTIONS: When creating alt text for images, use simple, descriptive words that work well as search terms. Prefer single words or simple phrases like "mountains", "cityscape", "office", "technology", "nature", "people", "business", "food", etc. NEVER use proper names, brand names, or specific person names. Focus on general, descriptive terms that would return good stock photos.
10. CRITICAL: Cards must only be included inside a 'card_group' component. Never provide a standalone 'card' component at the top level.

Here is the library of available Drupal UI components (use their `type` field and structure):
```json
{components_json}
```
EOT;
  }

}
