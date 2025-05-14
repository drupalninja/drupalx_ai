<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;
use OpenAI;
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
    $api_endpoint = $config->get('api_endpoint');

    try {
      // The PHP OpenAI client v0.7.0+ allows setting a custom base URI via a factory.
      // The `OpenAI::client($apiKey)` is a shorthand for the default setup.
      // To use a custom endpoint, we need to use the factory pattern.
      $factory = OpenAI::factory()
        ->withApiKey($this->apiKey);

      if ($api_endpoint && $api_endpoint !== 'https://api.openai.com/v1') {
        $factory = $factory->withBaseUri($api_endpoint);
        $this->logger->info('Using custom API endpoint: @endpoint', ['@endpoint' => $api_endpoint]);
      }
      else {
        // Default OpenAI API endpoint.
        $factory = $factory->withBaseUri('https://api.openai.com/v1');
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
        // 'response_format' => ['type' => 'json_object'], // This ensures the AI *tries* to send JSON.
      ]);

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

      // The prompt asks for an array of components.
      // Handle cases where AI might wrap it, e.g., {"components": [...] } or just return the array.
      if (is_array($decoded_components)) {
        // Case 1: Direct array of components (or empty array).
        if (empty($decoded_components) || (isset($decoded_components[0]) && isset($decoded_components[0]['id']))) {
          return $decoded_components;
        }
        // Case 2: Wrapped array. Check common keys.
        foreach (['components', 'selected_components', 'result', 'data'] as $key) {
          if (isset($decoded_components[$key]) && is_array($decoded_components[$key])) {
            // Further check if the sub-array contains component-like structures or is empty.
            if (empty($decoded_components[$key]) || (isset($decoded_components[$key][0]) && isset($decoded_components[$key][0]['id']))) {
              return $decoded_components[$key];
            }
          }
        }
      }

      $this->logger->warning('AI response was valid JSON but not in the expected array format or known wrapped format. Response: @response', ['@response' => $content]);
      // Return empty array if the structure is not what we expect but is valid JSON.
      return [];
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
