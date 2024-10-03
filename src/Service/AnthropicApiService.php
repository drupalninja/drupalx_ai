<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for making calls to the Anthropic API.
 */
class AnthropicApiService
{
  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructor for AnthropicApiService.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientFactory $http_client_factory, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->initializeHttpClient($http_client_factory);
  }

  /**
   * Initialize the HTTP client with the API key from configuration.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   */
  protected function initializeHttpClient(ClientFactory $http_client_factory)
  {
    $api_key = $this->configFactory->get('drupalx_ai.settings')->get('api_key');

    if (empty($api_key)) {
      $this->loggerFactory->get('drupalx_ai')->warning('Anthropic API key is not set. Please configure it in the DrupalX AI Settings.');
    }

    $this->httpClient = $http_client_factory->fromOptions(
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
          'anthropic-version' => '2023-06-01',
        ],
      ]
    );
  }

  /**
   * Make a call to the Anthropic API.
   *
   * @param string $prompt
   *   The prompt to send to the API.
   * @param array $tools
   *   The tools configuration for the API call.
   * @param string $expectedFunctionName
   *   The name of the function we expect to be called.
   *
   * @return mixed
   *   The result of the API call, or FALSE on failure.
   */
  public function callAnthropic($prompt, array $tools, $expectedFunctionName)
  {
    $api_key = $this->configFactory->get('drupalx_ai.settings')->get('api_key');
    if (empty($api_key)) {
      $this->loggerFactory->get('drupalx_ai')->error('Anthropic API key is not set. Please configure it in the DrupalX AI Settings.');
      return FALSE;
    }

    $url = 'https://api.anthropic.com/v1/messages';
    $data = [
      'model' => 'claude-3-haiku-20240307',
      'max_tokens' => 4096,
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
      'tools' => $tools,
    ];

    try {
      $this->loggerFactory->get('drupalx_ai')->notice('Sending request to Claude API');
      $response = $this->httpClient->request('POST', $url, ['json' => $data]);
      $this->loggerFactory->get('drupalx_ai')->notice('Received response from Claude API');

      $responseData = json_decode($response->getBody(), TRUE);
      $this->loggerFactory->get('drupalx_ai')->notice('Response data: @data', ['@data' => print_r($responseData, TRUE)]);

      // Attempt to extract content using the expected function call
      $extractedContent = $this->extractContentFromResponse($responseData, $expectedFunctionName);
      if ($extractedContent !== false) {
        return $extractedContent;
      }

      // If we couldn't find the expected function call, try to extract useful content
      $fallbackContent = $this->extractFallbackContent($responseData);
      if ($fallbackContent !== false) {
        $this->loggerFactory->get('drupalx_ai')->notice('Using fallback content extraction method');
        return ['story_content' => $fallbackContent];
      }

      $this->loggerFactory->get('drupalx_ai')->warning("Function call '{$expectedFunctionName}' not found in API response and fallback extraction failed");
      return FALSE;
    } catch (RequestException $e) {
      $this->loggerFactory->get('drupalx_ai')->error('API request failed: ' . $e->getMessage());
      $this->loggerFactory->get('drupalx_ai')->error('Request details: ' . print_r($data, TRUE));
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Error processing API response: ' . $e->getMessage());
    }

    return FALSE;
  }

  /**
   * Extract content from the API response using the expected function call.
   *
   * @param array $responseData
   *   The API response data.
   * @param string $expectedFunctionName
   *   The name of the function we expect to be called.
   *
   * @return mixed
   *   The extracted content, or FALSE if not found.
   */
  protected function extractContentFromResponse($responseData, $expectedFunctionName)
  {
    if (!isset($responseData['content']) || !is_array($responseData['content'])) {
      $this->loggerFactory->get('drupalx_ai')->warning('Unexpected API response format: content array not found');
      return false;
    }

    foreach ($responseData['content'] as $content) {
      if (isset($content['type']) && $content['type'] === 'tool_use' && isset($content['input'])) {
        $arguments = $content['input'];
        if (is_array($arguments) && isset($arguments['story_content'])) {
          $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments');
          return $arguments;
        }
      }
    }

    return false;
  }

  /**
   * Extract fallback content from the API response.
   *
   * @param array $responseData
   *   The API response data.
   *
   * @return string|false
   *   The extracted fallback content, or FALSE if not found.
   */
  protected function extractFallbackContent($responseData)
  {
    if (isset($responseData['content']) && is_array($responseData['content'])) {
      foreach ($responseData['content'] as $content) {
        if (isset($content['type']) && $content['type'] === 'text' && isset($content['text'])) {
          // Extract content that looks like a Storybook story
          if (strpos($content['text'], 'import type { Meta, StoryObj }') !== false) {
            return $content['text'];
          }
        }
      }
    }

    return false;
  }
}
