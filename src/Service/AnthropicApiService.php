<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for making calls to the Anthropic API.
 */
class AnthropicApiService {

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
  public function __construct(ClientFactory $http_client_factory, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
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
  protected function initializeHttpClient(ClientFactory $http_client_factory) {
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
   * Make a call to the Anthropic API with retry functionality.
   *
   * @param string $prompt
   *   The prompt to send to the API.
   * @param array $tools
   *   The tools configuration for the API call.
   * @param string $expectedFunctionName
   *   The name of the function we expect to be called.
   * @param int $maxRetries
   *   Maximum number of retries (default: 3).
   * @param int $initialRetryDelay
   *   Initial delay between retries in seconds (default: 1).
   *
   * @return mixed
   *   The result of the API call, or FALSE on failure.
   */
  public function callAnthropic($prompt, array $tools, $expectedFunctionName, $maxRetries = 3, $initialRetryDelay = 1)
  {
    $api_key = $this->configFactory->get('drupalx_ai.settings')->get('api_key');
    if (empty($api_key)) {
      $this->loggerFactory->get('drupalx_ai')->error('Anthropic API key is not set. Please configure it in the DrupalX AI Settings.');
      return FALSE;
    }

    $url = 'https://api.anthropic.com/v1/messages';
    $data = [
      'model' => 'claude-3-haiku-20240307',
      'max_tokens' => 2048,
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
      'tools' => $tools,
    ];

    $retryCount = 0;
    $retryDelay = $initialRetryDelay;

    while ($retryCount <= $maxRetries) {
      try {
        $this->loggerFactory->get('drupalx_ai')->notice('Sending request to Claude API (Attempt: @attempt)', ['@attempt' => $retryCount + 1]);
        $response = $this->httpClient->request('POST', $url, ['json' => $data]);
        $this->loggerFactory->get('drupalx_ai')->notice('Received response from Claude API');

        $responseData = json_decode($response->getBody(), TRUE);
        $this->loggerFactory->get('drupalx_ai')->notice('Response data: @data', ['@data' => print_r($responseData, TRUE)]);

        if (!isset($responseData['content']) || !is_array($responseData['content'])) {
          throw new \RuntimeException('Unexpected API response format: content array not found');
        }

        foreach ($responseData['content'] as $content) {
          $this->loggerFactory->get('drupalx_ai')->notice('Processing content: @content', ['@content' => print_r($content, TRUE)]);
          if (isset($content['type']) && $content['type'] === 'tool_use' && isset($content['input'])) {
            $arguments = $content['input'];
            if (is_array($arguments)) {
              $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments');
              return $arguments;
            } else {
              throw new \RuntimeException('Failed to parse function call arguments: invalid format');
            }
          }
        }

        if ($retryCount < $maxRetries) {
          $this->loggerFactory->get('drupalx_ai')->notice("Function call '{$expectedFunctionName}' not found. Retrying...");
          $data['messages'][] = [
            'role' => 'assistant',
            'content' => $responseData['content'][0]['text'],
          ];
          $data['messages'][] = [
            'role' => 'user',
            'content' => "Please continue with the function call for {$expectedFunctionName}.",
          ];
          $retryCount++;
        } else {
          throw new \RuntimeException("Function call '{$expectedFunctionName}' not found in API response after {$maxRetries} attempts");
        }
      } catch (RequestException $e) {
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
        $errorData = json_decode($responseBody, TRUE);

        if (
          isset($errorData['type']) && $errorData['type'] === 'error' &&
          isset($errorData['error']['type']) && $errorData['error']['type'] === 'overloaded_error'
        ) {
          $this->loggerFactory->get('drupalx_ai')->warning('Anthropic API overloaded. Retrying in @seconds seconds...', ['@seconds' => $retryDelay]);

          if ($retryCount < $maxRetries) {
            sleep($retryDelay);
            $retryCount++;
            $retryDelay *= 2; // Exponential backoff
            continue;
          }
        }

        $this->loggerFactory->get('drupalx_ai')->error('API request failed: @message', ['@message' => $e->getMessage()]);
        $this->loggerFactory->get('drupalx_ai')->error('Request details: @details', ['@details' => print_r($data, TRUE)]);
        return FALSE;
      } catch (\Exception $e) {
        $this->loggerFactory->get('drupalx_ai')->error('Error processing API response: @message', ['@message' => $e->getMessage()]);
        return FALSE;
      }
    }

    $this->loggerFactory->get('drupalx_ai')->error('Max retries reached. Unable to get a successful response from the Anthropic API.');
    return FALSE;
  }

}
