<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for fetching images from external providers like Pexels and Unsplash.
 */
class ImageGeneratorService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected ClientFactory $httpClientFactory;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new ImageGeneratorService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientFactory $http_client_factory,
    KeyRepositoryInterface $key_repository,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClientFactory = $http_client_factory;
    $this->keyRepository = $key_repository;
    $this->logger = $logger_factory->get('drupalx_ai');
  }

  /**
   * Fetches an image based on the alt text/search query.
   *
   * @param string $alt_text
   *   The alt text to use as a search query.
   *
   * @return array|null
   *   Array with 'data' (image binary data) and 'extension' (file extension),
   *   or NULL if no image could be fetched.
   */
  public function fetchImage(string $alt_text): ?array {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $image_generator = $config->get('image_generator');

    if ($image_generator === 'pexels') {
      return $this->fetchFromPexels($alt_text);
    } elseif ($image_generator === 'unsplash') {
      return $this->fetchFromUnsplash($alt_text);
    }

    // Return NULL for 'placeholder' or any other setting
    return NULL;
  }

  /**
   * Fetches an image from Pexels API.
   *
   * @param string $query
   *   The search query.
   *
   * @return array|null
   *   Array with image data and extension, or NULL if failed.
   */
  protected function fetchFromPexels(string $query): ?array {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $api_key_id = $config->get('pexels_api_key');

    if (empty($api_key_id)) {
      $this->logger->warning('Pexels API key not configured');
      return NULL;
    }

    $key_entity = $this->keyRepository->getKey($api_key_id);
    if (!$key_entity) {
      $this->logger->error('Pexels API key entity not found: @key_id', ['@key_id' => $api_key_id]);
      return NULL;
    }

    $api_key = $key_entity->getKeyValue();
    if (empty($api_key)) {
      $this->logger->error('Pexels API key value is empty for key: @key_id', ['@key_id' => $api_key_id]);
      return NULL;
    }

    $client = $this->httpClientFactory->fromOptions();

    try {
      // Search for photos with randomized page and multiple results to vary images.
      $response = $client->request('GET', 'https://api.pexels.com/v1/search', [
        'headers' => [
          'Authorization' => $api_key,
        ],
        'query' => [
          'query' => $query,
          'per_page' => 10,
          'page' => random_int(1, 10),
          'orientation' => 'landscape',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['photos'])) {
        $this->logger->warning('No Pexels images found for query: @query', ['@query' => $query]);
        return NULL;
      }

      // Pick a random photo from the result set.
      $index = array_rand($data['photos']);
      $photo = $data['photos'][$index];
      $image_url = $photo['src']['medium'] ?? ($photo['src']['large'] ?? ($photo['src']['original'] ?? NULL));
      if (!$image_url) {
        $this->logger->warning('Pexels image lacked expected src sizes for query: @query', ['@query' => $query]);
        return NULL;
      }

      // Download the actual image
      $image_response = $client->request('GET', $image_url);
      $image_data = $image_response->getBody()->getContents();

      $this->logger->info('Successfully fetched random Pexels image (index @i) for query: @query', ['@query' => $query, '@i' => (string) $index]);

      return [
        'data' => $image_data,
        'extension' => 'jpg',
      ];

    } catch (RequestException $e) {
      $this->logger->error('Error fetching image from Pexels: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetches an image from Unsplash API.
   *
   * @param string $query
   *   The search query.
   *
   * @return array|null
   *   Array with image data and extension, or NULL if failed.
   */
  protected function fetchFromUnsplash(string $query): ?array {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $api_key_id = $config->get('unsplash_api_key');

    if (empty($api_key_id)) {
      $this->logger->warning('Unsplash API key not configured');
      return NULL;
    }

    $key_entity = $this->keyRepository->getKey($api_key_id);
    if (!$key_entity) {
      $this->logger->error('Unsplash API key entity not found: @key_id', ['@key_id' => $api_key_id]);
      return NULL;
    }

    $api_key = $key_entity->getKeyValue();
    if (empty($api_key)) {
      $this->logger->error('Unsplash API key value is empty for key: @key_id', ['@key_id' => $api_key_id]);
      return NULL;
    }

    $client = $this->httpClientFactory->fromOptions();

    try {
      // Search for photos with randomized page and multiple results to vary images.
      $response = $client->request('GET', 'https://api.unsplash.com/search/photos', [
        'headers' => [
          'Authorization' => 'Client-ID ' . $api_key,
        ],
        'query' => [
          'query' => $query,
          'per_page' => 10,
          'page' => random_int(1, 10),
          'orientation' => 'landscape',
          // Randomize ordering between relevant and latest to vary results.
          'order_by' => (random_int(0, 1) === 1) ? 'latest' : 'relevant',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['results'])) {
        $this->logger->warning('No Unsplash images found for query: @query', ['@query' => $query]);
        return NULL;
      }

      // Pick a random photo from the result set.
      $index = array_rand($data['results']);
      $photo = $data['results'][$index];
      $image_url = $photo['urls']['regular'] ?? ($photo['urls']['full'] ?? NULL);
      if (!$image_url) {
        $this->logger->warning('Unsplash image lacked expected url sizes for query: @query', ['@query' => $query]);
        return NULL;
      }

      // Download the actual image
      $image_response = $client->request('GET', $image_url);
      $image_data = $image_response->getBody()->getContents();

      $this->logger->info('Successfully fetched random Unsplash image (index @i) for query: @query', ['@query' => $query, '@i' => (string) $index]);

      return [
        'data' => $image_data,
        'extension' => 'jpg',
      ];

    } catch (RequestException $e) {
      $this->logger->error('Error fetching image from Unsplash: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
