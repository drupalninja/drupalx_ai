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
 * Service for interacting with the Pexels API.
 */
class PexelsApiService implements ImageApiServiceInterface {

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
   * The Pexels API key.
   *
   * @var string|null
   */
  private ?string $pexelsApiKey = NULL;

  /**
   * Constructs a PexelsApiService object.
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
    $this->loadPexelsApiKey();
  }

  /**
   * Loads the Pexels API key from the Key module.
   */
  private function loadPexelsApiKey(): void {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $pexels_api_key_id = $config->get('pexels_api_key');

    if (!empty($pexels_api_key_id)) {
      $pexelsKeyEntity = $this->keyRepository->getKey($pexels_api_key_id);
      if ($pexelsKeyEntity instanceof KeyInterface) {
        $this->pexelsApiKey = $pexelsKeyEntity->getKeyValue();
      }
    }

    // Don't log errors during initialization - only when actually trying to use the service.
  }

  /**
   * {@inheritdoc}
   */
  public function fetchImageFromApi(string $searchTerm): ?array {
    if (empty($this->pexelsApiKey)) {
      $this->logger->error("🔑 Pexels API key not found or is empty in PexelsApiService. Please configure it in the Key module and select it in the AI settings.");
      return NULL;
    }
    if (empty($searchTerm)) {
      $this->logger->warning("🖼️ Pexels: Search term is empty. Cannot fetch image.");
      return NULL;
    }

    // Fetch multiple images (10) and randomize the page to reduce repetition.
    $page = random_int(1, 10);
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($searchTerm) .
      "&per_page=10&orientation=landscape&page=" . $page;

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => $this->pexelsApiKey,
        ],
        // Timeout in seconds.
        'timeout' => 10,
      ]);

      $statusCode = $response->getStatusCode();
      $responseBody = $response->getBody()->getContents();

      if ($statusCode === 200) {
        $data = json_decode($responseBody, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $this->logger->error("🖼️ Pexels: JSON Decode Error for term ':term'. Error: :msg. Response: :body", [
            ':term' => $searchTerm,
            ':msg' => json_last_error_msg(),
            ':body' => $responseBody,
          ]);
          return NULL;
        }

        if (!empty($data['photos']) && count($data['photos']) > 0) {
          // Select a random photo from the available results.
          $randomIndex = array_rand($data['photos']);
          $photo = $data['photos'][$randomIndex];

          if (!empty($photo['src']['original']) && isset($photo['alt'])) {
            $this->logger->info("🖼️ Pexels: Successfully fetched random image ({$randomIndex} of " . count($data['photos']) . ") for term ':term'. URL: :url, Pexels Alt: :pexels_alt", [
              ':term' => $searchTerm,
              ':url' => $photo['src']['original'],
              ':pexels_alt' => $photo['alt'],
            ]);
            return [
              'url' => $photo['src']['original'],
              'alt' => $photo['alt'],
            ];
          }
          else {
            $this->logger->warning("🖼️ Pexels: Image data for ':term' is incomplete in API response. Missing src.original or alt. Photo data: :photo_data", [
              ':term' => $searchTerm,
              ':photo_data' => json_encode($photo),
            ]);
          }
        }
        else {
          $this->logger->warning("🖼️ Pexels: No usable photos found for search term ':term'. Response may be missing required fields.", [
            ':term' => $searchTerm,
          ]);
        }
      }
      elseif (empty($data['photos'])) {
        $this->logger->warning("🖼️ Pexels: No photos found for search term ':term'. Response: :body", [
          ':term' => $searchTerm,
          ':body' => $responseBody,
        ]);
      }
      elseif ($statusCode === 401 || $statusCode === 403) {
        $this->logger->error("🖼️ Pexels: Invalid API Key or Authentication Failed for term ':term' (HTTP Code: :status). Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
      elseif ($statusCode === 429) {
        $this->logger->error("🖼️ Pexels: Rate Limit Exceeded for term ':term' (HTTP Code: :status). Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
      else {
        $this->logger->error("🖼️ Pexels: API request failed for term ':term' with status code :status. Response: :body", [
          ':term' => $searchTerm,
          ':status' => $statusCode,
          ':body' => $responseBody,
        ]);
      }
    }
    catch (RequestException $e) {
      $this->logger->error("🖼️ Pexels: API request exception for term ':term'. Message: :message", [
        ':term' => $searchTerm,
        ':message' => $e->getMessage(),
      ]);
      if ($e->hasResponse()) {
        $this->logger->error("🖼️ Pexels: Exception response: :response", [':response' => $e->getResponse()->getBody()->getContents()]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error("🖼️ Pexels: An unexpected error occurred while fetching image for term ':term'. Message: :message", [
        ':term' => $searchTerm,
        ':message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

}
