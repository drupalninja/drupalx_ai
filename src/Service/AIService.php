<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Factory;
use OpenAI\Client as OpenAIClient;
use Drupal\drupalx_ai\Service\ValidationService;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Component\Serialization\Json;

// Alias OpenAI client to avoid class name collision.
use OpenAI\OpenAI as OpenAIAPI;

/**
 * Service for interacting with an OpenAI-compatible AI.
 */
class AIService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

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
   * The OpenAI API client.
   *
   * @var \OpenAI\Client|null
   */
  protected ?OpenAIClient $client = NULL;

  /**
   * The API key value.
   *
   * @var string
   */
  protected string $apiKey;

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
   * Constructs a new AIService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\ValidationService $validation_service
   *   The DrupalX AI Validation service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ValidationService $validation_service,
    EntityTypeBundleInfoInterface $entity_type_bundle_info
  ) {
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->validationService = $validation_service;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * Initializes the OpenAI client.
   *
   * @return bool
   *   TRUE if the client was initialized successfully, FALSE otherwise.
   */
  protected function initializeClient(): bool {
    if ($this->client) {
      return TRUE;
    }

    $config = $this->configFactory->get('drupalx_ai.settings');
    $api_key_id = $config->get('api_key_id');

    if (empty($api_key_id)) {
      $this->logger->error('OpenAI API Key ID not configured.');
      return FALSE;
    }

    $key_entity = $this->keyRepository->getKey($api_key_id);
    if (!$key_entity || !$key_entity->getKeyValue()) {
      $this->logger->error('Failed to load OpenAI API Key: @key_id', ['@key_id' => $api_key_id]);
      return FALSE;
    }
    $this->apiKey = $key_entity->getKeyValue();
    $configured_url = $config->get('api_endpoint');

    try {
      $base_uri_to_use = $configured_url;
      $chat_completions_suffix = '/chat/completions';

      if (is_string($configured_url) && str_ends_with($configured_url, $chat_completions_suffix)) {
        $base_uri_to_use = substr($configured_url, 0, -strlen($chat_completions_suffix));
        if (empty($base_uri_to_use)) {
          $base_uri_to_use = $configured_url;
        }
      }

      // Mitigate SSL verification issues often encountered in local dev.
      // Consider making this configurable or removing for production.
      $guzzleClient = new GuzzleClient(['verify' => FALSE]);
      $factory = (new Factory())
        ->withApiKey($this->apiKey)
        ->withHttpClient($guzzleClient);

      if (!empty($base_uri_to_use) && $base_uri_to_use !== 'https://api.openai.com/v1') {
        $factory = $factory->withBaseUri($base_uri_to_use);
      }

      $this->client = $factory->make();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to initialize OpenAI client: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    return TRUE;
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
    $default_return_on_error = [
      'title' => 'Generated Page (Error)',
      'components' => [],
      'validation_data' => [
        'status' => 'error',
        'message' => 'Initialization or pre-flight check failed.',
      ],
      'error' => 'Initialization or pre-flight check failed.',
      'raw_response' => '',
    ];

    if (!$this->initializeClient()) {
      $default_return_on_error['error'] = 'Failed to initialize AI client.';
      $default_return_on_error['validation_data']['message'] = 'Failed to initialize AI client.';
      return $default_return_on_error;
    }

    $config = $this->configFactory->get('drupalx_ai.settings');
    $model_name = $config->get('model_name') ?: 'gpt-3.5-turbo';

    // Load sample components using the ValidationService.
    $samples_result = $this->validationService->loadSampleComponents();
    if ($samples_result['status'] !== 'success') {
      $this->logger->error(
        'Sample components file not loaded: @message',
        [
          '@message' => $samples_result['message'],
        ]
      );
      $default_return_on_error['error'] = 'Sample components file not loaded.';
      $default_return_on_error['validation_data']['message'] = $samples_result['message'];
      return $default_return_on_error;
    }

    // Validate sample components against paragraph bundles.
    $validation_result = $this->validationService->validateAgainstParagraphBundles($samples_result['data']);
    if ($validation_result['status'] !== 'success') {
      $this->logger->error(
        'No valid sample components: @message',
        [
          '@message' => $validation_result['message'],
        ]
      );
      $default_return_on_error['error'] = 'No valid sample components to guide the AI.';
      $default_return_on_error['validation_data']['message'] = $validation_result['message'];
      return $default_return_on_error;
    }
    $valid_sample_components_for_prompt = $validation_result['valid_components'];
    $allowed_component_types = $validation_result['allowed_types'];

    $json_data_for_prompt = Json::encode($valid_sample_components_for_prompt);
    $unique_allowed_types = array_unique($allowed_component_types);
    $allowed_types_string = '"' . implode('", "', $unique_allowed_types) . '"';

    $system_prompt = <<<EOT
You are an AI assistant helping to build a webpage using predefined UI components.
Your primary goal is to select appropriate components based on the user's description and the provided library of components.

INSTRUCTIONS:
1.  First, on a line by itself, suggest a clear and compelling title for this landing page. Format it exactly as: "PAGE_TITLE: Your Suggested Page Title Here".
2.  Then, on subsequent lines, provide the JSON data for the recommended UI components. This JSON should be enclosed in a standard markdown code block (```json ... ```).
3.  The JSON must be an array of component objects.
4.  Each component object in your response MUST include a `type` field.
5.  IMPORTANT: The value of the `type` field for each component MUST be one of the following allowed Drupal Paragraph bundle machine names: {$allowed_types_string}.
    Do not invent new `type` values. Only use types from this list.
6.  Each component in your response must match the structure and fields shown in the example components provided below (respecting the `type`). Do not change other field names (keys).
7.  For any fields representing images (e.g., fields with "image" or "media" in their name), the 'alt' text MUST be a brief, thematic, and descriptive phrase for the image. Avoid generic placeholders.
8.  CRITICAL: Cards must only be included inside a 'card_group' component. Never provide a standalone 'card' component at the top level.

Here is the library of available Drupal UI components (use their `type` field and structure):
```json
{$json_data_for_prompt}
```
EOT;

    $page_title = 'Generated Page';
    $extracted_ai_components = [];
    $ai_content = '';

    try {
      $response = $this->client->chat()->create([
        'model' => $model_name,
        'messages' => [
          ['role' => 'system', 'content' => $system_prompt],
          [
            'role' => 'user',
            'content' => "User's page goal: \"" . $user_description . "\"",
          ],
        ],
        'temperature' => 0.5,
        'max_tokens' => 4000,
      ]);

      if (empty($response->choices[0]->message->content)) {
        $this->logger->error(
          'AI response empty. Response: @response',
          [
            '@response' => Json::encode($response->toArray()),
          ]
        );
        return [
          'error' => 'AI response was empty.',
          'title' => $page_title,
          'components' => [],
          'validation_data' => [
            'status' => 'error',
            'message' => 'AI response was empty.',
          ],
          'raw_response' => Json::encode($response->toArray()),
        ];
      }

      $ai_content = $response->choices[0]->message->content;

      // Extract Page Title.
      $title_match = [];
      if (preg_match('/PAGE_TITLE:(.*)/i', $ai_content, $title_match)) {
        $page_title = trim($title_match[1]);
        // Remove the title line from ai_content before JSON extraction.
        $ai_content = preg_replace('/PAGE_TITLE:.*(\\r\\n|\\r|\\n)/i', '', $ai_content, 1);
      }

      $json_string_from_ai = $this->extractJsonFromString(trim($ai_content));

      if ($json_string_from_ai) {
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
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error communicating with AI: @message. Model: @model',
        [
          '@message' => $e->getMessage(),
          '@model' => $model_name,
        ]
      );
      return [
        'error' => 'Error communicating with AI: ' . $e->getMessage(),
        'title' => $page_title,
        'components' => [],
        'validation_data' => [
          'status' => 'error',
          'message' => 'Error communicating with AI: ' . $e->getMessage(),
        ],
        'raw_response' => $ai_content,
        // May be empty if exception before API call.
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

}

