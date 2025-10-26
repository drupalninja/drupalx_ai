<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\key\KeyRepositoryInterface;
use Drupal\key\KeyInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for interacting with the Unsplash API.
 */
class UnsplashApiService implements ImageApiServiceInterface {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The Unsplash API key.
   *
   * @var string|null
   */
  private ?string $unsplashApiKey = NULL;

  /**
   * Constructs an UnsplashApiService object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    KeyRepositoryInterface $key_repository,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->keyRepository = $key_repository;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->configFactory = $config_factory;
    $this->loadUnsplashApiKey();
  }

  /**
   * Loads the Unsplash API key from the Key module.
   */
  private function loadUnsplashApiKey(): void {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $unsplash_api_key_id = $config->get('unsplash_api_key');

    if (!empty($unsplash_api_key_id)) {
      $unsplashKeyEntity = $this->keyRepository->getKey($unsplash_api_key_id);
      if ($unsplashKeyEntity instanceof KeyInterface) {
        $this->unsplashApiKey = $unsplashKeyEntity->getKeyValue();
      }
    }

    // Don't log errors during initialization - only when actually trying to use the service.
  }

  /**
   * {@inheritdoc}
   */
  public function fetchImageFromApi(string $searchTerm): ?array {
    if (empty($this->unsplashApiKey)) {
      $this->logger->error("🔑 Unsplash API key not found or is empty in UnsplashApiService. Please configure it in the Key module and select it in the AI settings.");
      return NULL;
    }
    if (empty($searchTerm)) {
      $this->logger->warning("🖼️ Unsplash: Search term is empty. Cannot fetch image.");
      return NULL;
    }

    // Fetch multiple images (10) and randomize the page/order to reduce repetition.
    $page = random_int(1, 10);
    $order = (random_int(0, 1) === 1) ? 'latest' : 'relevant';
    $url = "https://api.unsplash.com/search/photos?query=" . urlencode($searchTerm) .
      "&per_page=10&orientation=landscape&page=" . $page . "&order_by=" . $order;

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Client-ID ' . $this->unsplashApiKey,
        ],
        'timeout' => 10,
      ]);

      $statusCode = $response->getStatusCode();
      $responseBody = $response->getBody()->getContents();

      if ($statusCode === 200) {
        $data = json_decode($responseBody, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $this->logger->error("🖼️ Unsplash: JSON Decode Error for term ':term'. Error: :msg. Response: :body", [
            ':term' => $searchTerm,
            ':msg' => json_last_error_msg(),
            ':body' => $responseBody,
          ]);
          return NULL;
        }

        if (!empty($data['results']) && count($data['results']) > 0) {
          // Select a random photo from the available results.
          $randomIndex = array_rand($data['results']);
          $photo = $data['results'][$randomIndex];

          // Only require urls.full - description can be null.
          if (!empty($photo['urls']['full'])) {
            // Use description if available, alt_description as fallback, or
            // finally the search term.
            $alt = !empty($photo['description']) ? $photo['description'] :
                  (!empty($photo['alt_description']) ? $photo['alt_description'] : $searchTerm);

            $this->logger->info("🖼️ Unsplash: Successfully fetched random image ({$randomIndex} of " . count($data['results']) . ") for term ':term'. URL: :url", [
              ':term' => $searchTerm,
              ':url' => $photo['urls']['full'],
            ]);

            return [
              'url' => $photo['urls']['full'],
              'alt' => $alt,
            ];
          }
          else {
            $this->logger->warning("🖼️ Unsplash: Image data for ':term' is incomplete in API response. Missing urls.full. Photo data: :photo_data", [
              ':term' => $searchTerm,
              ':photo_data' => json_encode($photo),
            ]);
          }
        }
        else {
          $this->logger->warning("🖼️ Unsplash: No usable photos found for search term ':term'. Response may be missing required fields.", [
            ':term' => $searchTerm,
          ]);
        }
      }
      elseif (empty($data['results'])) {
        $this->logger->warning("🖼️ Unsplash: No photos found for search term ':term'. Response: :body", [
          ':term' => $searchTerm,
          ':body' => $responseBody,
        ]);
      }
      elseif ($statusCode === 401 || $statusCode === 403) {
        $this->logger->error("🖼️ Unsplash: Invalid API Key or Authentication Failed for term ':term' (HTTP Code: :status). Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
      elseif ($statusCode === 429) {
        $this->logger->error("🖼️ Unsplash: Rate Limit Exceeded for term ':term' (HTTP Code: :status). Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
      else {
        $this->logger->error("🖼️ Unsplash: API request failed for term ':term' with status code :status. Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
    }
    catch (RequestException $e) {
      $this->logger->error("🖼️ Unsplash: API request exception for term ':term'. Message: :message", [
        ':term' => $searchTerm,
        ':message' => $e->getMessage(),
      ]);
      if ($e->hasResponse()) {
        $this->logger->error("🖼️ Unsplash: Exception response: :response", [':response' => $e->getResponse()->getBody()->getContents()]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error("🖼️ Unsplash: An unexpected error occurred while fetching image for term ':term'. Message: :message", [
        ':term' => $searchTerm,
        ':message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

}
