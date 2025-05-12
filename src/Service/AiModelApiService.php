<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for making calls to various AI APIs (Anthropic, OpenAI, Groq, Fireworks).
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
    // Directly call the Groq API method.
    // The API key check is handled within callGroqApi.
    $this->loggerFactory->get('drupalx_ai')->notice('Using Groq AI provider.');
    return $this->callGroqApi($prompt, $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay);
  }

  /**
   * Makes an API call to Groq's completion endpoint with retry functionality.
   *
   * @param string $prompt
   *   The user prompt/question to send to the Groq API.
   * @param array $tools
   *   Array of tools/functions that the model can use to respond.
   * @param string $expectedFunctionName
   *   The name of the function that is expected to be called by the model.
   * @param int $maxRetries
   *   Maximum number of retry attempts for failed API calls.
   * @param int $initialRetryDelay
   *   Initial delay in seconds between retry attempts. May increase with backoff.
   *
   * @return array
   *   The decoded JSON response from the Groq API.
   */
  protected function callGroqApi($prompt, array $tools, $expectedFunctionName, $maxRetries, $initialRetryDelay) {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $groq_model = $config->get('groq_model') ?: 'mixtral-8x7b-32768';
    $api_key = $config->get('api_key');

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $data = [
      'model' => $groq_model,
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

    $this->loggerFactory->get('drupalx_ai')->notice('Calling Groq API with model: @model', ['@model' => $groq_model]);

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
   *
   * @param string $url
   *   The URL to call.
   * @param mixed $data
   *   The data to send with the request.
   * @param array $headers
   *   The headers to send with the request.
   * @param string $expectedFunctionName
   *   The expected function name.
   * @param int $maxRetries
   *   The maximum number of retries.
   * @param int $initialRetryDelay
   *   The delay between retries in seconds.
   *
   * @return mixed
   *   The result of the API call, or FALSE on failure.
   */
  protected function makeApiCallWithRetry($url, $data, array $headers, $expectedFunctionName, $maxRetries, $initialRetryDelay) {
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
   *
   * @param mixed $responseData
   *   The API response data.
   * @param string $expectedFunctionName
   *   The expected function name.
   *
   * @return mixed
   *   The parsed function call arguments or FALSE on failure.
   */
  protected function parseApiResponse($responseData, $expectedFunctionName) {
    // Groq uses the OpenAI response format.
    return $this->parseOpenAiResponse($responseData, $expectedFunctionName);
  }

  /**
   * Parse OpenAI API response with better handling of escaped content.
   *
   * @param mixed $responseData
   *   The API response data.
   * @param string $expectedFunctionName
   *   Expected function name.
   *
   * @return mixed
   *   The parsed function call arguments or FALSE on failure.
   */
  protected function parseOpenAiResponse($responseData, $expectedFunctionName) {
    try {
      // Case 1: Standard tool_calls format.
      if (isset($responseData['choices'][0]['message']['tool_calls'])) {
        foreach ($responseData['choices'][0]['message']['tool_calls'] as $toolCall) {
          if ($toolCall['function']['name'] === $expectedFunctionName) {
            $arguments = json_decode($toolCall['function']['arguments'], TRUE);
            if (is_array($arguments)) {
              $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments from tool_calls');
              return $arguments;
            }
          }
        }
      }

      // Case 2: Content field with embedded function call.
      if (isset($responseData['choices'][0]['message']['content'])) {
        $content = $responseData['choices'][0]['message']['content'];

        // Try to decode the content if it's a JSON string.
        if (is_string($content)) {
          $decodedContent = json_decode($content, TRUE);

          // Check if it's a function call structure.
          if (is_array($decodedContent) &&
              isset($decodedContent['type']) &&
              $decodedContent['type'] === 'function' &&
              isset($decodedContent['name']) &&
              $decodedContent['name'] === $expectedFunctionName &&
              isset($decodedContent['parameters'])) {

            // If parameters contains escaped JSON strings, decode them.
            $parameters = $decodedContent['parameters'];
            foreach ($parameters as $key => $value) {
              if (is_string($value) && strpos($value, '\\\"') !== FALSE) {
                $parameters[$key] = json_decode($value, TRUE);
              }
            }

            $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments from content');
            return $parameters;
          }
        }
      }

      // Case 3: Old format function_call.
      if (isset($responseData['choices'][0]['message']['function_call'])) {
        $functionCall = $responseData['choices'][0]['message']['function_call'];
        if ($functionCall['name'] === $expectedFunctionName) {
          $arguments = json_decode($functionCall['arguments'], TRUE);
          if (is_array($arguments)) {
            $this->loggerFactory->get('drupalx_ai')->notice('Successfully parsed function call arguments from function_call');
            return $arguments;
          }
        }
      }

      // Log the structure if we couldn't parse it.
      if (isset($responseData['choices'][0]['message'])) {
        $this->loggerFactory->get('drupalx_ai')->warning(
          'Could not parse response structure: @structure',
          ['@structure' => json_encode($responseData['choices'][0]['message'])]
        );
      }

      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error(
        'Error parsing response: @message',
        ['@message' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Handle request exceptions.
   *
   * @param mixed $e
   *   The request exception.
   * @param int $retryCount
   *   The current retry count.
   * @param int $maxRetries
   *   The maximum number of retries.
   * @param int $retryDelay
   *   The delay between retries in seconds.
   *
   * @return bool
   *   TRUE if the request should be retried, FALSE otherwise.
   */
  protected function handleRequestException($e, $retryCount, $maxRetries, $retryDelay) {
    $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
    $errorData = json_decode($responseBody, TRUE);

    // Generic rate limit check (Groq uses OpenAI format).
    if (isset($errorData['error']['type']) && $errorData['error']['type'] === 'rate_limit_exceeded') {
      $this->loggerFactory->get('drupalx_ai')->warning(
        'Groq API rate limit exceeded. Retrying in @seconds seconds...',
        ['@seconds' => $retryDelay]
      );
      if ($retryCount < $maxRetries) {
        sleep($retryDelay);
        return TRUE;
      }
    }

    $this->loggerFactory->get('drupalx_ai')->error('API request failed: @message', ['@message' => $e->getMessage()]);
    return FALSE;
  }

}
