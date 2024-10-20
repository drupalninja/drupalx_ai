<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for making calls to various AI APIs (Anthropic, OpenAI).
 */
class AiModelApiService {

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

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
   * Constructor for AiModelApiService.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientFactory $http_client_factory, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClientFactory = $http_client_factory;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Make a call to the selected AI API with retry functionality.
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
  public function callAiApi($prompt, array $tools, $expectedFunctionName, $maxRetries = 3, $initialRetryDelay = 1) {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $api_provider = $config->get('ai_provider') ?: 'anthropic';
    $api_key = $config->get('api_key');

    if (empty($api_key)) {
      $this->loggerFactory->get('drupalx_ai')->error('AI API key is not set. Please configure it in the DrupalX AI Settings.');
      return FALSE;
    }

    $this->loggerFactory->get('drupalx_ai')->notice('Using AI provider: @provider', ['@provider' => $api_provider]);

    if ($api_provider === 'anthropic') {
      return $this->callAnthropicApi($prompt, $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay);
    }
    elseif ($api_provider === 'openai') {
      return $this->callOpenAiApi($prompt, $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay);
    }
    else {
      $this->loggerFactory->get('drupalx_ai')->error('Invalid AI provider selected. Defaulting to Anthropic.');
      return $this->callAnthropicApi($prompt, $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay);
    }
  }

  /**
   * Make a call to the Anthropic API.
   */
  protected function callAnthropicApi($prompt, array $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay) {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $claude_model = $config->get('claude_model') ?: 'claude-3-haiku-20240307';
    $api_key = $config->get('api_key');

    $url = 'https://api.anthropic.com/v1/messages';
    $data = [
      'model' => $claude_model,
      'max_tokens' => 2048,
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
      'tools' => $tools,
    ];

    $headers = [
      'Content-Type' => 'application/json',
      'x-api-key' => $api_key,
      'anthropic-version' => '2023-06-01',
    ];

    $this->loggerFactory->get('drupalx_ai')->notice('Calling Anthropic API with model: @model', ['@model' => $claude_model]);

    return $this->makeApiCallWithRetry($url, $data, $headers, $expectedFunctionName, $maxRetries, $initialRetryDelay);
  }

  /**
   * Make a call to the OpenAI API.
   */
  protected function callOpenAiApi($prompt, array $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay) {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $openai_model = $config->get('openai_model') ?: 'gpt-4o-mini';
    $api_key = $config->get('api_key');

    $url = 'https://api.openai.com/v1/chat/completions';
    $data = [
      'model' => $openai_model,
      'messages' => [
        [
          'role' => 'system',
          'content' => 'You are a helpful assistant. Use the supplied tools to assist the user.',
        ],
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
      'tools' => $this->convertToolsToOpenAiFormat($tools),
    ];

    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $api_key,
    ];

    $this->loggerFactory->get('drupalx_ai')->notice('Calling OpenAI API with model: @model', ['@model' => $openai_model]);

    return $this->makeApiCallWithRetry($url, $data, $headers, $expectedFunctionName, $maxRetries, $initialRetryDelay);
  }

  /**
   * Convert Anthropic-style tools to OpenAI-style functions.
   *
   * @param array $tools
   *   The Anthropic-style tools.
   *
   * @return array
   *   The OpenAI-style functions.
   */
  protected function convertToolsToOpenAiFormat(array $tools) {
    $openAiTools = [];

    foreach ($tools as $tool) {
      $openAiTool = [
        'type' => 'function',
        'function' => [
          'name' => $tool['name'],
          'description' => $tool['description'],
          'parameters' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
          ],
        ],
      ];

      if (isset($tool['input_schema']['properties'])) {
        foreach ($tool['input_schema']['properties'] as $propName => $propSchema) {
          $openAiTool['function']['parameters']['properties'][$propName] = $propSchema;
        }
      }

      if (isset($tool['input_schema']['required'])) {
        $openAiTool['function']['parameters']['required'] = $tool['input_schema']['required'];
      }

      $openAiTools[] = $openAiTool;
    }

    return $openAiTools;
  }

  /**
   * Make an API call with retry functionality.
   */
  protected function makeApiCallWithRetry($url, $data, $headers, $expectedFunctionName, $maxRetries, $initialRetryDelay) {
    $retryCount = 0;
    $retryDelay = $initialRetryDelay;

    while ($retryCount <= $maxRetries) {
      try {
        $this->loggerFactory->get('drupalx_ai')->notice('Sending request to AI API (Attempt: @attempt)', ['@attempt' => $retryCount + 1]);
        $client = $this->httpClientFactory->fromOptions(['headers' => $headers]);
        $response = $client->request('POST', $url, ['json' => $data]);
        $this->loggerFactory->get('drupalx_ai')->notice('Received response from AI API');

        $responseData = json_decode($response->getBody(), TRUE);
        $this->loggerFactory->get('drupalx_ai')->notice('Response data: @data', ['@data' => print_r($responseData, TRUE)]);

        $result = $this->parseApiResponse($responseData, $expectedFunctionName);
        if ($result !== FALSE) {
          return $result;
        }

        if ($retryCount < $maxRetries) {
          $this->loggerFactory->get('drupalx_ai')->notice("Function call '{$expectedFunctionName}' not found. Retrying...");
          $data['messages'][] = [
            'role' => 'assistant',
            'content' => $responseData['content'][0]['text'] ?? json_encode($responseData),
          ];
          $data['messages'][] = [
            'role' => 'user',
            'content' => "Please continue with the function call for {$expectedFunctionName}.",
          ];
          $retryCount++;
        }
        else {
          throw new \RuntimeException("Function call '{$expectedFunctionName}' not found in API response after {$maxRetries} attempts");
        }
      }
      catch (RequestException $e) {
        // Handle request exceptions (e.g., network errors, API overload).
        if ($this->handleRequestException($e, $retryCount, $maxRetries, $retryDelay)) {
          $retryCount++;
          $retryDelay *= 2;
          continue;
        }
        return FALSE;
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('drupalx_ai')->error('Error processing API response: @message', ['@message' => $e->getMessage()]);
        return FALSE;
      }
    }

    $this->loggerFactory->get('drupalx_ai')->error('Max retries reached. Unable to get a successful response from the AI API.');
    return FALSE;
  }

  /**
   * Parse the API response based on the provider.
   */
  protected function parseApiResponse($responseData, $expectedFunctionName) {
    $api_provider = $this->configFactory->get('drupalx_ai.settings')->get('ai_provider') ?: 'anthropic';

    if ($api_provider === 'anthropic') {
      return $this->parseAnthropicResponse($responseData, $expectedFunctionName);
    }
    elseif ($api_provider === 'openai') {
      return $this->parseOpenAiResponse($responseData, $expectedFunctionName);
    }

    return FALSE;
  }

  /**
   * Parse Anthropic API response.
   */
  protected function parseAnthropicResponse($responseData, $expectedFunctionName) {
    if (!isset($responseData['content']) || !is_array($responseData['content'])) {
      throw new \RuntimeException('Unexpected API response format: content array not found');
    }

    foreach ($responseData['content'] as $content) {
      if (isset($content['type']) && $content['type'] === 'tool_use' && isset($content['input'])) {
        $arguments = $content['input'];
        if (is_array($arguments)) {
          $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments');
          return $arguments;
        }
      }
    }

    return FALSE;
  }

  /**
   * Parse OpenAI API response.
   */
  protected function parseOpenAiResponse($responseData, $expectedFunctionName) {
    if (isset($responseData['choices'][0]['message']['tool_calls'])) {
      foreach ($responseData['choices'][0]['message']['tool_calls'] as $toolCall) {
        if ($toolCall['function']['name'] === $expectedFunctionName) {
          $arguments = json_decode($toolCall['function']['arguments'], TRUE);
          if (is_array($arguments)) {
            $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments');
            return $arguments;
          }
        }
      }
    }

    // Fallback to the old format for backward compatibility.
    if (isset($responseData['choices'][0]['message']['function_call'])) {
      $functionCall = $responseData['choices'][0]['message']['function_call'];
      if ($functionCall['name'] === $expectedFunctionName) {
        $arguments = json_decode($functionCall['arguments'], TRUE);
        if (is_array($arguments)) {
          $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments (old format)');
          return $arguments;
        }
      }
    }

    return FALSE;
  }

  /**
   * Handle request exceptions.
   */
  protected function handleRequestException($e, $retryCount, $maxRetries, $retryDelay) {
    $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
    $errorData = json_decode($responseBody, TRUE);

    $api_provider = $this->configFactory->get('drupalx_ai.settings')->get('ai_provider') ?: 'anthropic';

    if (
      $api_provider === 'anthropic' && isset($errorData['type']) && $errorData['type'] === 'error' &&
      isset($errorData['error']['type']) && $errorData['error']['type'] === 'overloaded_error'
    ) {
      $this->loggerFactory->get('drupalx_ai')->warning('Anthropic API overloaded. Retrying in @seconds seconds...', ['@seconds' => $retryDelay]);
      if ($retryCount < $maxRetries) {
        sleep($retryDelay);
        return TRUE;
      }
    }
    elseif ($api_provider === 'openai' && isset($errorData['error']['type']) && $errorData['error']['type'] === 'rate_limit_exceeded') {
      $this->loggerFactory->get('drupalx_ai')->warning('OpenAI API rate limit exceeded. Retrying in @seconds seconds...', ['@seconds' => $retryDelay]);
      if ($retryCount < $maxRetries) {
        sleep($retryDelay);
        return TRUE;
      }
    }

    $this->loggerFactory->get('drupalx_ai')->error('API request failed: @message', ['@message' => $e->getMessage()]);
    return FALSE;
  }

}
