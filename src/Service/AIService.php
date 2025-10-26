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
use Drupal\json_import\Service\DrupalContentImporter;
use Drupal\json_import\Service\JsonSchemaValidator;

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
   * The JSON import importer service.
   *
   * @var \Drupal\json_import\Service\DrupalContentImporter
   */
  protected DrupalContentImporter $jsonImporter;

  /**
   * The JSON schema validator service.
   *
   * @var \Drupal\json_import\Service\JsonSchemaValidator
   */
  protected JsonSchemaValidator $jsonSchemaValidator;

  /**
   * Image generator used to fetch and persist images.
   *
   * @var \Drupal\drupalx_ai\Service\ImageGeneratorService|null
   */
  protected ?ImageGeneratorService $imageGenerator = NULL;

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
   * @param \Drupal\json_import\Service\DrupalContentImporter $json_importer
   *   The JSON import importer service.
   * @param \Drupal\json_import\Service\JsonSchemaValidator $json_schema_validator
   *   The JSON schema validator service.
   * @param \Drupal\drupalx_ai\Service\ImageGeneratorService|null $image_generator
   *   (optional) The image generator service used to fetch/persist images.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ValidationService $validation_service,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    AiProviderPluginManager $ai_provider_manager,
    DrupalContentImporter $json_importer,
    JsonSchemaValidator $json_schema_validator,
    ?ImageGeneratorService $image_generator = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->validationService = $validation_service;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->aiProviderManager = $ai_provider_manager;
    $this->jsonImporter = $json_importer;
    $this->jsonSchemaValidator = $json_schema_validator;
    // Allow optional injection to avoid container mismatch during updates.
    $this->imageGenerator = $image_generator ?? (\Drupal::hasService('drupalx_ai.image_generator') ? \Drupal::service('drupalx_ai.image_generator') : NULL);
  }

  /**
   * Builds a json_import content structure for a landing page node.
   *
   * @param string $page_title
   *   The page title for the node.
   * @param array $components
   *   Array of AI-generated components (paragraphs/media entries).
   * @param int $uid
   *   The owner user ID.
   *
   * @return array
   *   The combined content structure (components + landing node) for
   *   json_import.
   */
  public function createNodeWithComponents(string $page_title, array $components, int $uid): array {
    // Create paragraph references from the component IDs (only top-level
    // paragraphs, not embedded ones).
    $paragraph_refs = [];

    // Define sub-component types that should never be top-level (same as
    // json_import filtering).
    $sub_component_types = [
      'paragraph.card',
      'paragraph.accordion_item',
      'paragraph.carousel_item',
      'paragraph.bullet',
      'paragraph.pricing_card',
    ];

    foreach ($components as $component) {
      if (isset($component['id']) && isset($component['type']) && str_starts_with($component['type'], 'paragraph.')) {
        // Only include top-level paragraphs, not sub-components.
        if (!in_array($component['type'], $sub_component_types)) {
          $paragraph_refs[] = '@' . $component['id'];
        }
      }
    }

    // Create the node structure.
    $node_structure = [
      'id' => 'main_page_node',
      'type' => 'node.landing',
      'values' => [
        'title' => $page_title,
        'uid' => $uid,
        'status' => 1,
        'field_hide_page_title' => TRUE,
        'field_content' => $paragraph_refs,
      ],
    ];

    // Debug logging.
    $this->logger->debug('AIService: Node structure field_content references: @refs', [
      '@refs' => json_encode($paragraph_refs),
    ]);

    // Combine components first, then node last (following json_import
    // sample.json pattern).
    $full_structure = array_merge($components, [$node_structure]);

    return $full_structure;
  }

  /**
   * Import components using the json_import service.
   *
   * @param array $components
   *   Array of components to import.
   * @param bool $preview_mode
   *   Whether to run in preview mode.
   *
   * @return array
   *   The import result.
   */
  public function importComponentsAsJsonImport(array $components, bool $preview_mode = FALSE): array {
    // Create the proper json_import structure following the schema exactly.
    $json_import_data = [
      'content' => $components,
    ];

    // Debug: Log the full JSON structure being passed to json_import.
    $this->logger->debug('AIService: Full JSON structure being passed to json_import: @json', [
      '@json' => json_encode($json_import_data, JSON_PRETTY_PRINT),
    ]);

    // Use json_import service to process the data.
    try {
      $result = $this->jsonImporter->import($json_import_data, $preview_mode);

      // Debug: Log the import result.
      $this->logger->debug('AIService: JSON import result: @result', [
        '@result' => json_encode($result, JSON_PRETTY_PRINT),
      ]);

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('AIService: JSON import failed with exception: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'summary' => [],
        'warnings' => ['Failed to import components: ' . $e->getMessage()],
        'error' => $e->getMessage(),
      ];
    }
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
    // 1) Prefer fenced code blocks. Accept unlabeled or any case of
    // json/jsonc/etc.
    if (preg_match_all('/```([a-z0-9_-]*)?\s*\n([\s\S]*?)\n```/i', $string, $all, PREG_SET_ORDER)) {
      foreach ($all as $block) {
        $lang = isset($block[1]) ? strtolower($block[1]) : '';
        if ($lang === '' || str_contains($lang, 'json')) {
          $candidate = $this->attemptJsonFix(trim($block[2]));
          try {
            Json::decode($candidate);
            $this->logger->debug('AIService: JSON extracted via fenced code block.');
            return $candidate;
          }
          catch (\InvalidArgumentException $e) {
            // Continue searching other fenced blocks.
          }
        }
      }
      // As a fallback, if a JSON-like fenced block exists, return the first
      // such candidate.
      foreach ($all as $block) {
        $lang = isset($block[1]) ? strtolower($block[1]) : '';
        if ($lang === '' || str_contains($lang, 'json')) {
          $candidate = $this->attemptJsonFix(trim($block[2]));
          $this->logger->debug('AIService: Using first JSON-like fenced block as fallback.');
          return $candidate;
        }
      }
    }

    // 2) If the entire string looks like JSON, use it.
    $trimmed_string = trim($string);
    if ($trimmed_string !== '' && (str_starts_with($trimmed_string, '[') || str_starts_with($trimmed_string, '{'))) {
      $json_string = $this->attemptJsonFix($trimmed_string);
      $this->logger->debug('AIService: JSON extracted from whole-string body.');
      return $json_string;
    }

    // 3) As a robust fallback, try to locate the first valid JSON object/array
    // within noisy text (e.g. models that prepend/append tokens).
    $candidate = $this->findJsonSubstring($string);
    if ($candidate !== NULL) {
      $candidate = $this->attemptJsonFix($candidate);
      $this->logger->debug('AIService: JSON extracted from substring scan fallback.');
      return $candidate;
    }

    return NULL;
  }

  /**
   * Locates the first JSON object or array substring within a larger string.
   *
   * Scans for the first '{' or '[' and then walks forward, tracking string
   * state and nested depth until a matching closing '}' or ']' is found.
   * Returns the substring including the matching closing bracket, or NULL if
   * no plausible JSON block is found.
   */
  private function findJsonSubstring(string $text): ?string {
    $len = strlen($text);
    $firstCurly = strpos($text, '{');
    $firstSquare = strpos($text, '[');

    if ($firstCurly === FALSE && $firstSquare === FALSE) {
      return NULL;
    }

    // Choose the earliest JSON-like opener.
    if ($firstCurly === FALSE || ($firstSquare !== FALSE && $firstSquare < $firstCurly)) {
      $start = (int) $firstSquare;
      $rootOpen = '[';
      $rootClose = ']';
    }
    else {
      $start = (int) $firstCurly;
      $rootOpen = '{';
      $rootClose = '}';
    }

    $depth = 0;
    $inString = FALSE;
    $escape = FALSE;
    for ($i = $start; $i < $len; $i++) {
      $ch = $text[$i];

      if ($inString) {
        if ($escape) {
          $escape = FALSE;
        }
        else {
          if ($ch === '\\') {
            $escape = TRUE;
          }
          elseif ($ch === '"') {
            $inString = FALSE;
          }
        }
        continue;
      }

      if ($ch === '"') {
        $inString = TRUE;
        continue;
      }

      if ($ch === $rootOpen) {
        $depth++;
      }
      elseif ($ch === $rootClose) {
        $depth--;
        if ($depth === 0) {
          $candidate = substr($text, $start, $i - $start + 1);
          // Quick sanity check; if it decodes, it's very likely valid JSON.
          try {
            Json::decode($candidate);
            if (json_last_error() === JSON_ERROR_NONE) {
              return $candidate;
            }
          }
          catch (\InvalidArgumentException $e) {
            // Ignore; we'll still return the candidate for upstream handling.
          }
          // Even if decode fails here, return the candidate and allow
          // upstream fixers/decoders to attempt recovery.
          return $candidate;
        }
      }
    }

    return NULL;
  }

  /**
   * Attempts to fix common JSON formatting issues.
   *
   * @param string $json_string
   *   The potentially malformed JSON string.
   *
   * @return string
   *   The potentially fixed JSON string.
   */
  private function attemptJsonFix(string $json_string): string {
    // Log the original JSON for debugging.
    $this->logger->debug('AIService: Original JSON from AI: @json', ['@json' => $json_string]);

    // Don't attempt automatic fixes as they can make things worse.
    // Focus on improving the AI prompt instead.
    return $json_string;
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
      // Try to get the default model from AI settings.
      try {
        $ai_config = \Drupal::config('ai.settings');
        $configured_model = $ai_config->get('default_model');
        if ($configured_model) {
          $model_id = $configured_model;
        }
        else {
          // Fallback to a reasonable default for Groq.
          if ($provider_id === 'groq') {
            $model_id = 'llama-3.3-70b-versatile';
          }
          else {
            return NULL;
          }
        }
      }
      catch (\Exception $e) {
        // Fallback to a reasonable default for Groq.
        if ($provider_id === 'groq') {
          $model_id = 'llama-3.3-70b-versatile';
        }
        else {
          return NULL;
        }
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

      // Load JSON import schema components using the ValidationService.
      $samples_result = $this->validationService->loadJsonImportSchema();
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

      // Load Lucide icon names for the AI to use.
      $lucide_icons = $this->getLucideIconNames();

      // Replace placeholders in the prompt template.
      $system_prompt = str_replace(
        ['{allowed_types}', '{components_json}', '{lucide_icons}'],
        [$allowed_types_string, $json_data_for_prompt, $lucide_icons],
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
      $ai_content = preg_replace('/PAGE_TITLE:.*(\r\n|\r|\n)/i', '', $ai_content, 1);
    }

    $json_string_from_ai = $this->extractJsonFromString(trim($ai_content));

    if ($json_string_from_ai) {
      $this->logger->debug(
        'AIService: Raw JSON string extracted from AI: @json',
        ['@json' => $json_string_from_ai]
      );

      try {
        $decoded_json = Json::decode($json_string_from_ai);
      }
      catch (\InvalidArgumentException $e) {
        $this->logger->error(
          'Failed to decode JSON from AI (exception): @error. JSON: @json',
          [
            '@error' => $e->getMessage(),
            '@json' => $json_string_from_ai,
          ]
        );
        return [
          'error' => 'Failed to decode JSON from AI (exception).',
          'title' => $page_title,
          'components' => [],
          'validation_data' => [
            'status' => 'error',
            'message' => 'Failed to decode JSON from AI (exception).',
          ],
          'raw_response' => $ai_content,
        ];
      }

      // Allow title extraction from common wrapper keys before normalizing.
      $title_from_json = $this->extractTitleFromData($decoded_json);
      if (!empty($title_from_json)) {
        $page_title = $title_from_json;
      }

      // Normalize to component list and ensure media.
      $extracted_ai_components = $this->normalizeAiJsonResponse($decoded_json);
      $extracted_ai_components = $this->ensureSideBySideHasMedia($extracted_ai_components);
    }
    else {
      $this->logger->error(
        'No JSON in AI response. Raw: @content',
        ['@content' => $ai_content]
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
        'Validation Service issues: Status - @status. Message - @message. Details - @details',
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
   * Ensures every paragraph.sidebyside component has a media image.
   *
   * - If a sidebyside component lacks a media reference, this will
   *   generate an image via the configured image provider, save it to
   *   public://drupalx_ai, create a media.image JSON entry, and attach it.
   * - Falls back to a placeholder if no image provider is configured.
   *
   * @param array $components
   *   The list of AI-provided components.
   *
   * @return array
   *   The augmented components array with media entries added as needed.
   */
  private function ensureSideBySideHasMedia(array $components): array {
    if (empty($components)) {
      return $components;
    }

    // Collect existing IDs to avoid collisions.
    $existing_ids = [];
    foreach ($components as $item) {
      if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
        $existing_ids[$item['id']] = TRUE;
      }
    }

    $ensureDirectory = function (): void {
      try {
        $dir = 'public://drupalx_ai';
        \Drupal::service('file_system')->prepareDirectory(
          $dir,
          FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );
      }
      catch (\Exception $e) {
        // Log but continue; importer may still work with absolute URLs if
        // provided.
        $this->logger->warning(
          'AIService: Could not prepare directory public://drupalx_ai. Error: @e',
          ['@e' => $e->getMessage()]
        );
      }
    };

    $ensureDirectory();

    foreach ($components as $idx => &$component) {
      if (!is_array($component)) {
        continue;
      }
      $type = $component['type'] ?? '';
      if (!is_string($type)) {
        continue;
      }

      // Normalize possible forms like 'sidebyside' or 'paragraph.sidebyside'.
      $bundle = $type;
      if (str_contains($bundle, '.')) {
        $parts = explode('.', $bundle, 2);
        $bundle = $parts[1];
      }

      if ($bundle !== 'sidebyside') {
        continue;
      }

      $values = $component['values'] ?? [];
      $has_media_ref = isset($values['media']) && is_string($values['media']) && trim($values['media']) !== '';
      if ($has_media_ref) {
        continue;
      }

      // Derive a simple search term/alt from title or summary.
      $alt_source = '';
      if (!empty($values['title']) && is_string($values['title'])) {
        $alt_source = $values['title'];
      }
      elseif (!empty($values['summary']) && is_string($values['summary'])) {
        $alt_source = strip_tags($values['summary']);
      }
      $alt_source = trim($alt_source);
      if ($alt_source === '') {
        $alt_source = 'people';
      }

      // Fetch image via image generator if available. On failure, fall back to
      // placeholder.
      $image = NULL;
      if ($this->imageGenerator) {
        try {
          $image = $this->imageGenerator->fetchImage($alt_source);
        }
        catch (\Throwable $e) {
          $this->logger->warning(
            'AIService: Image generator error for sidebyside media. Error: @e',
            ['@e' => $e->getMessage()]
          );
        }
      }

      $media_values = [];
      if (is_array($image) && !empty($image['data']) && !empty($image['extension'])) {
        $filename = 'public://drupalx_ai/sbs_' . uniqid('', TRUE) . '.' .
          preg_replace('/[^a-z0-9]+/i', '', $image['extension']);
        try {
          \Drupal::service('file_system')->saveData(
            $image['data'],
            $filename,
            FileSystemInterface::EXISTS_RENAME
          );
          $media_values = [
            'field_image' => [
              'uri' => $filename,
              'alt' => $alt_source,
            ],
          ];
        }
        catch (\Throwable $e) {
          $this->logger->warning(
            'AIService: Failed to save generated sidebyside image to @file. Error: @e',
            ['@file' => $filename, '@e' => $e->getMessage()]
          );
        }
      }

      // If saving binary failed, attempt to use remote URL from providers.
      if (empty($media_values)) {
        // Prefer URL fields supported by importer.
        // Use a simple built-in placeholder as absolute fallback.
        $placeholder = '/modules/contrib/json_import/resources/placeholder.png';
        $media_values = [
          'field_image' => [
            'uri' => $placeholder,
            'alt' => $alt_source,
          ],
        ];
      }

      // Create a unique media id and append media.image entry.
      $base_id = 'auto_sidebyside_media_' . ($idx + 1);
      $media_id = $base_id;
      $suffix = 1;
      while (isset($existing_ids[$media_id])) {
        $media_id = $base_id . '_' . $suffix++;
      }
      $existing_ids[$media_id] = TRUE;

      $media_entry = [
        'id' => $media_id,
        'type' => 'media.image',
        'values' => $media_values,
      ];

      // Attach reference to sidebyside component.
      $component['values']['media'] = '@' . $media_id;
      $components[] = $media_entry;

      $this->logger->info(
        'AIService: Attached auto-added media @id to sidebyside component at index @i.',
        ['@id' => $media_id, '@i' => (string) $idx]
      );
    }
    unset($component);

    return $components;
  }

  /**
   * Attempts to extract a page title from decoded JSON structures.
   *
   * Accepts either an array (list or associative) or stdClass/object and checks
   * a few common places for a title-like value: 'title', 'page_title', or
   * nested under wrapper keys such as 'result', 'data', or 'meta'.
   *
   * @param mixed $data
   *   The decoded JSON.
   *
   * @return string|null
   *   The extracted title or NULL if not found.
   */
  private function extractTitleFromData($data): ?string {
    if ($data === NULL) {
      return NULL;
    }

    // Normalize object to array for easier handling.
    if (is_object($data)) {
      $data = (array) $data;
    }

    if (!is_array($data)) {
      return NULL;
    }

    // Helper to validate a title value.
    $pick = function ($value): ?string {
      if (is_string($value)) {
        $title = trim($value);
        if ($title !== '') {
          // Basic sanity: avoid overly long titles.
          return mb_substr($title, 0, 140);
        }
      }
      return NULL;
    };

    // Direct keys on the top-level object.
    foreach (['title', 'page_title'] as $key) {
      if (isset($data[$key])) {
        $candidate = $pick($data[$key]);
        if ($candidate) {
          return $candidate;
        }
      }
    }

    // Common wrappers that may contain a title.
    foreach (['result', 'data', 'meta'] as $wrapper) {
      if (isset($data[$wrapper])) {
        $wrapped = $data[$wrapper];
        if (is_object($wrapped)) {
          $wrapped = (array) $wrapped;
        }
        if (is_array($wrapped)) {
          foreach (['title', 'page_title'] as $key) {
            if (isset($wrapped[$key])) {
              $candidate = $pick($wrapped[$key]);
              if ($candidate) {
                return $candidate;
              }
            }
          }
        }
      }
    }

    return NULL;
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

  /**
   * Loads Lucide icon names from the file.
   *
   * @return string
   *   A comma-separated list of available Lucide icon names.
   */
  protected function getLucideIconNames(): string {
    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');
    $icons_file_path = DRUPAL_ROOT . '/' . $module_path . '/files/lucide-icon-names.txt';

    if (file_exists($icons_file_path)) {
      $icons_content = file_get_contents($icons_file_path);
      if ($icons_content !== FALSE) {
        // Parse the file and extract icon names (skip the header line).
        $lines = explode("\n", trim($icons_content));
        $icon_names = [];

        foreach ($lines as $line) {
          $line = trim($line);
          // Skip empty lines and the header line.
          if (!empty($line) && !str_contains($line, 'Here are the Lucide icons')) {
            $icon_names[] = $line;
          }
        }

        // Return as a readable list for the AI.
        return implode(', ', $icon_names);
      }
      else {
        $this->logger->error('Failed to read Lucide icons file: @path', [
          '@path' => $icons_file_path,
        ]);
      }
    }
    else {
      $this->logger->error('Lucide icons file not found: @path', [
        '@path' => $icons_file_path,
      ]);
    }

    // Fallback to common icons if file can't be read.
    return 'heart, star, home, user, search, menu, settings, arrow-right, check, plus, minus, edit, trash, download, upload';
  }

}
