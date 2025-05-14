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
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('drupalx_ai');
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
      $this->logger->error('OpenAI API Key ID is not configured in DrupalX AI settings.');
      return FALSE;
    }

    $key_entity = $this->keyRepository->getKey($api_key_id);
    if (!$key_entity || !$key_entity->getKeyValue()) {
      $this->logger->error('Failed to load the OpenAI API Key from key module using ID: @key_id', ['@key_id' => $api_key_id]);
      return FALSE;
    }
    $this->apiKey = $key_entity->getKeyValue();
    $configured_url = $config->get('api_endpoint');

    try {
      $base_uri_to_use = $configured_url;
      $chat_completions_suffix = '/chat/completions';

      // If the configured URL ends with the chat completions suffix, strip it to get the base URI.
      // This allows users to paste full chat completion URLs (e.g., from Groq docs)
      // and the service will correctly determine the base for the OpenAI client.
      if (is_string($configured_url) && str_ends_with($configured_url, $chat_completions_suffix)) {
        $base_uri_to_use = substr($configured_url, 0, -strlen($chat_completions_suffix));
        // Ensure we don't have an empty base URI if the suffix was the entire string (unlikely).
        if (empty($base_uri_to_use)) {
          // Revert if stripping made it empty.
          $base_uri_to_use = $configured_url;
          $this->logger->warning('Configured API endpoint was just the suffix "@suffix". Using it as is, which might be incorrect.', ['@suffix' => $chat_completions_suffix]);
        }
      }

      $guzzleClient = new GuzzleClient(['verify' => FALSE]);
      $factory = (new Factory())
        ->withApiKey($this->apiKey)
        ->withHttpClient($guzzleClient);

      // Use the processed base_uri_to_use.
      // If it's empty or different from the default OpenAI, set it.
      // The OpenAI client defaults to 'https://api.openai.com/v1' if no base URI is set via factory.
      if (!empty($base_uri_to_use) && $base_uri_to_use !== 'https://api.openai.com/v1') {
        $factory = $factory->withBaseUri($base_uri_to_use);
        $this->logger->info('Using API base URI: @uri (derived from configured: @configured)', [
          '@uri' => $base_uri_to_use,
          '@configured' => $configured_url,
        ]);
      }
      // If $base_uri_to_use is 'https://api.openai.com/v1' or empty (after potential stripping issues),
      // let the client use its default, or explicitly set it if you want to be sure.
      // For clarity, if it ended up as the default, we can still log what was configured.
      elseif ($configured_url !== 'https://api.openai.com/v1') {
        $this->logger->info('Configured API endpoint "@configured" results in using the default OpenAI base URI.', [
          '@configured' => $configured_url,
        ]);
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
   * Gets components from the AI based on a user description.
   *
   * @param string $user_description
   *   The user's description of what they want to build.
   *
   * @return array|null
   *   An array of components or NULL on failure.
   */
  public function getComponents(string $user_description): ?array {
    if (!$this->initializeClient()) {
      return NULL;
    }

    $config = $this->configFactory->get('drupalx_ai.settings');
    $model_name = $config->get('model_name');
    // Ensure the path uses DIRECTORY_SEPARATOR for cross-platform compatibility,
    // and is relative to the Drupal root.
    $sample_json_path_relative = 'modules/contrib/drupalx_ai/files/sample-components.json';
    $sample_json_path = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $sample_json_path_relative;

    if (!file_exists($sample_json_path)) {
      // Use realpath for the log message if you want the canonicalized absolute path.
      $this->logger->error('Sample components JSON file not found at @path', ['@path' => $sample_json_path]);
      return NULL;
    }
    $sample_json = file_get_contents($sample_json_path);

    $prompt = <<<PROMPT
Given the following user description, select the most appropriate UI components from the provided JSON.
User Description: "{$user_description}"

Available components (JSON format):
{$sample_json}

Return ONLY the JSON array of selected components that best fit the user's description.
Each component in the array should be exactly as it appears in the input JSON.
If multiple components are suitable, include them all.
If no components are suitable, return an empty JSON array [].
PROMPT;

    try {
      $response = $this->client->chat()->create([
        'model' => $model_name,
        'messages' => [
          [
            'role' => 'system',
            'content' => 'You are an AI assistant that helps select UI components based on a JSON list and a user description. You only return JSON.',
          ],
          ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.7,
        'max_tokens' => 4000,
      ]);

      // Check if choices exist and are not empty before proceeding.
      if (empty($response->choices)) {
        $this->logger->error('AI response is missing or has empty "choices". Full response: @response', [
          '@response' => json_encode($response->toArray()),
        ]);
        return NULL;
      }

      $content = $response->choices[0]->message->content;
      // The AI might return plain JSON string or a JSON string wrapped in markdown code block.
      // Try to extract JSON from potential markdown code block.
      if (preg_match('/```json\n(.*?)\n```/s', $content, $matches)) {
        $content = $matches[1];
      }

      $decoded_components = json_decode($content, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Failed to decode JSON response from AI: @error. Response: @response', [
          '@error' => json_last_error_msg(),
          '@response' => $content,
        ]);
        return NULL;
      }

      $this->logger->info('AI Response JSON: @json', ['@json' => json_encode($decoded_components)]);

      // Determine the actual list of components from the AI response.
      $extracted_ai_components = [];
      if (is_array($decoded_components)) {
        // Case 1: Direct array of components.
        if (empty($decoded_components) ||
            (isset($decoded_components[0]) && is_array($decoded_components[0]) &&
             (isset($decoded_components[0]['id']) || isset($decoded_components[0]['type'])))
           ) {
          $extracted_ai_components = $decoded_components;
        }
        // Case 2: Wrapped array.
        else {
          foreach (['components', 'selected_components', 'result', 'data'] as $key_to_check) {
            if (isset($decoded_components[$key_to_check]) && is_array($decoded_components[$key_to_check])) {
              $potential_components_array = $decoded_components[$key_to_check];
              if (empty($potential_components_array) ||
                  (isset($potential_components_array[0]) && is_array($potential_components_array[0]) &&
                   (isset($potential_components_array[0]['id']) || isset($potential_components_array[0]['type'])))
                 ) {
                $extracted_ai_components = $potential_components_array;
                // Found components in a wrapper.
                break;
              }
            }
          }
        }
      }

      // Log if AI response was JSON but not the expected component structure.
      if (empty($extracted_ai_components) && $decoded_components !== NULL && json_last_error() === JSON_ERROR_NONE) {
        $this->logger->warning('AI response was valid JSON but not in the expected array format or known wrapped format. Raw response: @response', ['@response' => $content]);
        // $extracted_ai_components remains empty and will be returned as such.
      }

      // Perform validation using the facade function from validation.inc.
      $overall_validation_status = NULL;

      // Ensure validation.inc is loaded.
      $module_path = NULL;
      try {
        $module = \Drupal::moduleHandler()->getModule('drupalx_ai');
        if ($module) {
          $module_path = $module->getPath();
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to get drupalx_ai module path via ModuleHandler: @message', ['@message' => $e->getMessage()]);
        $overall_validation_status = 'error_module_path_critical';
      }

      if ($overall_validation_status === 'error_module_path_critical') {
        $this->logger->error("Critical: Could not determine module path for drupalx_ai. AI Component validation cannot proceed.");
      }
      elseif (!$module_path) {
        // This case should ideally be caught by the previous exception/check but as a fallback.
        $this->logger->error("Could not determine module path for drupalx_ai; AI component validation cannot proceed.");
        $overall_validation_status = 'error_module_path_missing';
      }
      else {
        $validation_inc_path = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $module_path . '/inc/validation.inc';
        if (file_exists($validation_inc_path)) {
          require_once $validation_inc_path;
          if (function_exists('drupalx_ai_perform_full_validation')) {
            if (empty($extracted_ai_components)) {
              $this->logger->info("No components were extracted from AI response; skipping full validation call.");
              // Even if no components, we can consider validation 'successful' in terms of process, with no errors/warnings.
              $overall_validation_status = 'success_no_components_to_validate';
              $validation_data = ['status' => $overall_validation_status, 'message' => 'No AI components to validate.', 'results' => ['errors' => [], 'warnings' => []]];
            }
            else {
              $this->logger->info('Calling drupalx_ai_perform_full_validation for AI components.');
              $validation_data = drupalx_ai_perform_full_validation($extracted_ai_components);
              $overall_validation_status = $validation_data['status'] ?? 'unknown_error_during_validation';
            }

            // Log based on the status and results from the facade function.
            if ($overall_validation_status !== 'success' && $overall_validation_status !== 'success_no_components_to_validate') {
              $this->logger->error(
                "Validation Orchestration Status: @status. Message: @message. Details: @details",
                [
                  '@status' => $overall_validation_status,
                  '@message' => $validation_data['message'] ?? 'No specific message.',
                  '@details' => json_encode($validation_data['results'] ?? []),
                ]
              );
            }
            elseif (!empty($validation_data['results']['errors'])) {
              $this->logger->error(
                "AI Components Validation completed with Errors: @errors. Warnings: @warnings",
                [
                  '@errors' => json_encode($validation_data['results']['errors']),
                  '@warnings' => json_encode($validation_data['results']['warnings'] ?? []),
                ]
              );
            }
            elseif (!empty($validation_data['results']['warnings'])) {
              $this->logger->warning("AI Components Validation completed with Warnings: @warnings", [
                '@warnings' => json_encode($validation_data['results']['warnings']),
              ]);
            }
            else {
              $this->logger->info("AI Components Validation completed successfully with no errors or warnings. Status: @status", ['@status' => $overall_validation_status]);
            }
          }
          else {
            $this->logger->error("Facade validation function 'drupalx_ai_perform_full_validation' not found in @path", ['@path' => $validation_inc_path]);
            $overall_validation_status = 'error_facade_function_missing';
          }
        }
        else {
          $this->logger->error("Validation script 'validation.inc' not found at computed path: @path", ['@path' => $validation_inc_path]);
          $overall_validation_status = 'error_validation_script_missing';
        }
      }
      // The AIService continues to return the extracted components, regardless of validation outcome for now.
      // Validation is primarily for logging and future stricter handling if needed.
      return $extracted_ai_components;
    }
    catch (\Exception $e) {
      $this->logger->error('Error communicating with AI: @message. Request details: Model - @model', [
        '@message' => $e->getMessage(),
        '@model' => $model_name,
      ]);
      return NULL;
    }
  }

}

