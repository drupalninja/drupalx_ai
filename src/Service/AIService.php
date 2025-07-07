<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Component\Serialization\Json;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Service for interacting with AI providers through the Drupal AI module.
 */
class AIService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The DrupalX AI Validation service.
   *
   * @var \Drupal\drupalx_ai\Service\ValidationService
   */
  protected ValidationService $validationService;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * Constructs a new AIService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\ValidationService $validation_service
   *   The DrupalX AI Validation service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\ai\AiProviderPluginManager $ai_provider_manager
   *   The AI provider plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ValidationService $validation_service,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    AiProviderPluginManager $ai_provider_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->validationService = $validation_service;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->aiProviderManager = $ai_provider_manager;
  }

  /**
   * Extracts JSON from a string, potentially wrapped in markdown.
   *
   * @param string $string
   *   The string to extract JSON from.
   *
   * @return string|null
   *   The JSON string, or NULL if not found.
   */
  private function extractJsonFromString(string $string): ?string {
    if (preg_match('/```json\n(.*?)\n```/s', $string, $matches)) {
      return $matches[1];
    }
    // Check if the string itself is likely JSON (starts with [ or {).
    $trimmed_string = trim($string);
    if (str_starts_with($trimmed_string, '[') || str_starts_with($trimmed_string, '{')) {
      return $trimmed_string;
    }
    return NULL;
  }

  /**
   * Normalizes the AI response to ensure it's an array of components.
   *
   * Checks for common wrapper keys if the top level isn't a simple array.
   *
   * @param mixed $data
   *   The decoded JSON data from the AI response.
   *
   * @return array
   *   An array of components. Returns empty array if input is not array
   *   or object, or if no components could be extracted.
   */
  private function normalizeAiJsonResponse($data): array {
    if (!is_array($data) && !is_object($data)) {
      return [];
    }
    // If $data is an object, convert to array for consistent processing.
    if (is_object($data)) {
      $data = (array) $data;
    }

    // Case 1: Direct array of components (or empty array).
    // Check if the first element looks like a component (has 'id' or 'type').
    if (empty($data) ||
        (isset($data[0]) &&
         is_array($data[0]) &&
         (isset($data[0]['id']) || isset($data[0]['type'])))
        ) {
      return $data;
    }

    // Case 2: Components are wrapped in a key (e.g., "components": [...]).
    $wrapper_keys = [
      'components',
      'selected_components',
      'result',
      'data',
      'items',
    ];
    foreach ($wrapper_keys as $key) {
      if (isset($data[$key]) && is_array($data[$key])) {
        $potential_components = $data[$key];
        // Check if this wrapped array looks like a list of components.
        if (empty($potential_components) ||
            (isset($potential_components[0]) &&
             is_array($potential_components[0]) &&
            (isset($potential_components[0]['id']) || isset($potential_components[0]['type'])))
           ) {
          return $potential_components;
        }
      }
    }
    // If no typical structure is found, return an empty array.
    return [];
  }

  /**
   * Gets the configured AI provider for DrupalX operations.
   *
   * @return array|null
   *   Array containing 'provider_id' and 'model_id', or NULL if not configured.
   */
  public function getAiProviderConfiguration(): ?array {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $ai_provider_model = $config->get('ai_provider_model');

    if (empty($ai_provider_model)) {
      return NULL;
    }

    [$provider_id, $model_id] = explode(':', $ai_provider_model, 2);

    // Handle default model selection.
    if ($model_id === 'default' || $model_id === NULL) {
      // Try to get the provider's configured default model.
      try {
        $provider_config = \Drupal::config('ai.provider.' . $provider_id);
        $configured_model = $provider_config->get('model');
        if ($configured_model) {
          $model_id = $configured_model;
        }
        else {
          // No fallback models - report error if provider has no configured model.
          return NULL;
        }
      }
      catch (\Exception $e) {
        // No fallback models - report error if config is not available.
        return NULL;
      }
    }

    return [
      'provider_id' => $provider_id,
      'model_id' => $model_id,
    ];
  }

  /**
   * Gets components and a title from the AI based on a user description.
   *
   * @param string $user_description
   *   The user's description of what they want to build.
   *
   * @return array
   *   An array containing 'title', 'components', and 'validation_data'.
   *   On error, 'error' key will be set.
   */
  public function getComponents(string $user_description): array {
    // Get the configured AI provider for DrupalX operations.
    $provider_config = $this->getAiProviderConfiguration();

    if (!$provider_config) {
      return [
        'title' => 'Generated Page (Error)',
        'components' => [],
        'validation_data' => [
          'status' => 'error',
          'message' => 'AI provider not configured properly. Please ensure the selected provider has a model configured in the AI module settings.',
        ],
        'error' => 'AI provider not configured properly. Please ensure the selected provider has a model configured in the AI module settings.',
        'raw_response' => '',
      ];
    }

    $provider_id = $provider_config['provider_id'];
    $model_id = $provider_config['model_id'];

    // Get config for system prompt.
    $config = $this->configFactory->get('drupalx_ai.settings');

    try {
      $provider = $this->aiProviderManager->createInstance($provider_id);

      // Load sample components using the ValidationService.
      $samples_result = $this->validationService->loadSampleComponents();
      if ($samples_result['status'] !== 'success') {
        return [
          'title' => 'Generated Page (Error)',
          'components' => [],
          'validation_data' => [
            'status' => 'error',
            'message' => $samples_result['message'],
          ],
          'error' => 'Sample components file not loaded.',
          'raw_response' => '',
        ];
      }

      // Validate sample components against paragraph bundles.
      $validation_result = $this->validationService->validateAgainstParagraphBundles($samples_result['data']);
      if ($validation_result['status'] !== 'success') {
        return [
          'title' => 'Generated Page (Error)',
          'components' => [],
          'validation_data' => [
            'status' => 'error',
            'message' => $validation_result['message'],
          ],
          'error' => 'No valid sample components to guide the AI.',
          'raw_response' => '',
        ];
      }

      $valid_sample_components_for_prompt = $validation_result['valid_components'];
      $allowed_component_types = $validation_result['allowed_types'];

      $json_data_for_prompt = Json::encode($valid_sample_components_for_prompt);
      $unique_allowed_types = array_unique($allowed_component_types);
      $allowed_types_string = '"' . implode('", "', $unique_allowed_types) . '"';

      // Get the configurable system prompt from settings.
      $system_prompt_template = $config->get('system_prompt');
      if (empty($system_prompt_template)) {
        $system_prompt_template = $this->getDefaultSystemPrompt();
      }

      // Replace placeholders in the prompt template.
      $system_prompt = str_replace(
        ['{allowed_types}', '{components_json}'],
        [$allowed_types_string, $json_data_for_prompt],
        $system_prompt_template
      );

      $messages = new ChatInput([
        new ChatMessage('system', $system_prompt),
        new ChatMessage('user', "User's page goal: \"" . $user_description . "\""),
      ]);

      $response = $provider->chat($messages, $model_id);
      $ai_content = $response->getNormalized()->getText();

      return $this->processAiResponse($ai_content);

    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error using AI module provider: @message',
        ['@message' => $e->getMessage()]
      );

      // Generic error message for all connection/API issues.
      $user_error = 'Could not connect to the AI service. Please check your configuration and try again.';

      return [
        'title' => 'Generated Page (Error)',
        'components' => [],
        'validation_data' => [
          'status' => 'error',
          'message' => $user_error,
        ],
        'error' => $user_error,
        'raw_response' => '',
      ];
    }
  }

  /**
   * Processes the AI response and extracts components.
   *
   * @param string $ai_content
   *   The AI response content.
   *
   * @return array
   *   An array containing 'title', 'components', and 'validation_data'.
   */
  protected function processAiResponse(string $ai_content): array {
    $this->logger->debug('AIService: Full AI response content: @content', ['@content' => $ai_content]);

    $page_title = 'Generated Page';

    // Extract Page Title.
    $title_match = [];
    if (preg_match('/PAGE_TITLE:(.*)/i', $ai_content, $title_match)) {
      $page_title = trim($title_match[1]);
      // Remove the title line from ai_content before JSON extraction.
      $ai_content = preg_replace('/PAGE_TITLE:.*(\\r\\n|\\r|\\n)/i', '', $ai_content, 1);
    }

    $json_string_from_ai = $this->extractJsonFromString(trim($ai_content));

    if ($json_string_from_ai) {
      $this->logger->debug(
        'AIService: Raw JSON string extracted from AI: @json',
        ['@json' => $json_string_from_ai]
      );

      $decoded_json = Json::decode($json_string_from_ai);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error(
          'Failed to decode JSON from AI: @error. JSON: @json',
          [
            '@error' => json_last_error_msg(),
            '@json' => $json_string_from_ai,
          ]
        );
        return [
          'error' => 'Failed to decode JSON from AI: ' . json_last_error_msg(),
          'title' => $page_title,
          'components' => [],
          'validation_data' => [
            'status' => 'error',
            'message' => 'Failed to decode JSON from AI: ' . json_last_error_msg(),
          ],
          'raw_response' => $ai_content,
        ];
      }

      $extracted_ai_components = $this->normalizeAiJsonResponse($decoded_json);
    }
    else {
      $this->logger->error(
        "No JSON in AI response. Raw: @content",
        [
          '@content' => $ai_content,
        ]
      );
      return [
        'error' => 'No JSON data found in AI response.',
        'title' => $page_title,
        'components' => [],
        'validation_data' => [
          'status' => 'error',
          'message' => 'No JSON data found in AI response.',
        ],
        'raw_response' => $ai_content,
      ];
    }

    // Perform validation using the injected ValidationService.
    $validation_data = $this->validationService->performFullValidation($extracted_ai_components);

    // Log validation results.
    if (($validation_data['status'] ?? 'error') !== 'success') {
      $this->logger->error(
        "Validation Service issues: Status - @status. Message - @message. Details - @details",
        [
          '@status' => $validation_data['status'] ?? 'unknown',
          '@message' => $validation_data['message'] ?? 'N/A',
          '@details' => Json::encode($validation_data['results'] ?? []),
        ]
      );
    }

    return [
      'title' => $page_title,
      'components' => $extracted_ai_components,
      'validation_data' => $validation_data,
      'raw_response' => $ai_content,
    ];
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
      else {
        $this->logger->error('Failed to read default prompt file: @path', [
          '@path' => $prompt_file_path,
        ]);
      }
    }
    else {
      $this->logger->error('Default prompt file not found: @path', [
        '@path' => $prompt_file_path,
      ]);
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
