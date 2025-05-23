<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for handling image media entities in the DrupalX AI module.
 */
class MediaService {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The bundle information service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The file service.
   *
   * @var \Drupal\drupalx_ai\Service\FileService
   */
  protected FileService $fileService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The Pexels API service.
   *
   * @var \Drupal\drupalx_ai\Service\PexelsApiService
   */
  protected PexelsApiService $pexelsApiService;

  /**
   * The Unsplash API service.
   *
   * @var \Drupal\drupalx_ai\Service\UnsplashApiService
   */
  protected UnsplashApiService $unsplashApiService;

  /**
   * Constructs a new MediaService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The bundle information service.
   * @param \Drupal\drupalx_ai\Service\FileService $file_service
   *   The file service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\drupalx_ai\Service\PexelsApiService $pexels_api_service
   *   The Pexels API service.
   * @param \Drupal\drupalx_ai\Service\UnsplashApiService $unsplash_api_service
   *   The Unsplash API service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    FileService $file_service,
    ConfigFactoryInterface $config_factory,
    PexelsApiService $pexels_api_service,
    UnsplashApiService $unsplash_api_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->fileService = $file_service;
    $this->configFactory = $config_factory;
    $this->pexelsApiService = $pexels_api_service;
    $this->unsplashApiService = $unsplash_api_service;
  }

  /**
   * Creates or loads a single image media item.
   *
   * @param array|string $media_data
   *   Array containing media information (e.g., url, alt) or a URL string.
   * @param int $owner_id
   *   The user ID to set as the owner of the media item.
   *
   * @return int|null
   *   The media ID or NULL on failure.
   */
  public function ensureMediaEntityExists($media_data, int $owner_id): ?array {
    $media_url = NULL;
    $alt_text = 'AI-generated image';
    $search_term = NULL;
    // For this service, we only handle 'image' bundle.
    $bundle = 'image';

    if (is_string($media_data)) {
      $media_url = $media_data;
    }
    elseif (is_array($media_data)) {
      $media_url = $media_data['media_url'] ?? ($media_data['url'] ?? NULL);
      $alt_text = $media_data['media_alt'] ?? ($media_data['alt'] ?? $alt_text);
      $search_term = $media_data['search_term'] ?? NULL;
      // Bundle is always 'image', ignore $media_data['bundle'].
    }

    $config = $this->configFactory->get('drupalx_ai.settings');
    $image_generator = $config->get('image_generator') ?: 'placeholder';

    // If no explicit search term provided, try to extract one from alt text.
    if (empty($search_term) && !empty($alt_text) && $alt_text !== 'AI-generated image') {
      $search_term = $this->extractSearchTermFromAlt($alt_text);
    }

    // If search term is provided and not using placeholder, try to fetch from API.
    if (!empty($search_term) && $image_generator !== 'placeholder') {
      $this->logger->info('🖼️ MediaService: Fetching image from @service with search term: "@term"', [
        '@service' => $image_generator,
        '@term' => $search_term,
      ]);

      $image_data = $this->fetchImageFromService($search_term, $image_generator);
      if ($image_data) {
        $media_url = $image_data['url'];
        // Keep the original alt text instead of using the one from API.
        $this->logger->info('🖼️ MediaService: Successfully fetched image from API: @url', [
          '@url' => $media_url,
        ]);
      }
      else {
        $this->logger->warning('🖼️ MediaService: Failed to fetch image from @service, falling back to placeholder', [
          '@service' => $image_generator,
        ]);
      }
    }

    if (empty($media_url)) {
      // If no URL is provided, create a placeholder image.
      return $this->createPlaceholderMediaItem($bundle, $alt_text, $owner_id);
    }

    // Download and create media entity from the provided URL.
    return $this->createMediaFromUrl($media_url, $bundle, $alt_text, $owner_id);
  }

  /**
   * Fetches an image from the configured image service.
   *
   * @param string $search_term
   *   The search term to use for fetching the image.
   * @param string $image_generator
   *   The image service to use (pexels, unsplash, placeholder).
   *
   * @return array|null
   *   Array with 'url' and 'alt' keys if successful, NULL otherwise.
   */
  protected function fetchImageFromService(string $search_term, string $image_generator): ?array {
    try {
      switch ($image_generator) {
        case 'pexels':
          return $this->pexelsApiService->fetchImageFromApi($search_term);

        case 'unsplash':
          return $this->unsplashApiService->fetchImageFromApi($search_term);

        default:
          $this->logger->warning('Unknown image generator: @generator', ['@generator' => $image_generator]);
          return NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching image from @service: @error', [
        '@service' => $image_generator,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a placeholder image media item.
   *
   * @param string $bundle
   *   The media bundle type, should always be 'image'.
   * @param string $alt_text
   *   The alt text for the image.
   * @param int $owner_id
   *   The owner user ID.
   *
   * @return int|null
   *   Media entity ID if successful, NULL otherwise.
   */
  public function createPlaceholderMediaItem(string $bundle, string $alt_text, int $owner_id): ?array {
    // Ensure the bundle is 'image' for this simplified service.
    if ($bundle !== 'image') {
      $this->logger->warning('MediaService is configured to only handle "image" bundle, but "@bundle" was requested. Attempting to create an image anyway.', [
        '@bundle' => $bundle,
      ]);
      $bundle = 'image';
    }

    try {
      $media_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('media');
      if (!isset($media_bundle_info[$bundle])) {
        $this->logger->error('Image media bundle "@bundle" does not exist.', [
          '@bundle' => $bundle,
        ]);
        return NULL;
      }

      $module_path = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('drupalx_ai');
      $placeholder_image_path = $module_path . '/files/card.png';
      // Default fallback file ID.
      $file_id = 1;

      if (!file_exists($placeholder_image_path)) {
        $this->logger->error('Placeholder image not found at @path. Using fallback file ID.', ['@path' => $placeholder_image_path]);
        // Attempt to load media 1 and get its revision if we are falling back.
        $fallback_media = $this->entityTypeManager->getStorage('media')->load(1);
        if ($fallback_media) {
          return [
            'id' => 1,
            'revision_id' => $fallback_media->getRevisionId(),
          ];
        }
        // Cannot provide a valid revision for fallback ID 1.
        return NULL;
      }
      else {
        $destination_filename = basename($placeholder_image_path);
        $destination_uri = 'public://' . $destination_filename;

        $file_uri = $this->fileSystem->copy($placeholder_image_path, $destination_uri, FileSystemInterface::EXISTS_REPLACE);

        if (!$file_uri) {
          $this->logger->error(
            'Failed to copy placeholder image to @destination. Using fallback file ID.',
            [
              '@destination' => $destination_uri,
            ]
          );
          $fallback_media = $this->entityTypeManager->getStorage('media')->load(1);
          if ($fallback_media) {
            return [
              'id' => 1,
              'revision_id' => $fallback_media->getRevisionId()
            ];
          }
          return NULL;
        }
        else {
          $file_entity = $this->fileService->createFileEntity($file_uri, $owner_id, $destination_filename);
          if ($file_entity) {
            $file_id = $file_entity->id();
          }
          else {
            $this->logger->error(
              'Failed to create file entity for placeholder image at @uri. Using fallback file ID.',
              [
                '@uri' => $file_uri,
              ]
            );
            $fallback_media = $this->entityTypeManager->getStorage('media')->load(1);
            if ($fallback_media) {
              return [
                'id' => 1,
                'revision_id' => $fallback_media->getRevisionId()
              ];
            }
            return NULL;
          }
        }
      }

      // Hardcoded for image media bundle.
      $source_field = 'field_image';
      $this->logger->debug('MediaService: Attempting to use source field: @source_field for bundle: @bundle', [
        '@source_field' => $source_field,
        '@bundle' => $bundle,
      ]);

      $bundle_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('media', $bundle);
      if (!isset($bundle_fields[$source_field])) {
        $this->logger->error('MediaService: Source field @source_field does NOT exist on image media bundle @bundle. Available fields: @fields', [
          '@source_field' => $source_field,
          '@bundle' => $bundle,
          '@fields' => implode(', ', array_keys($bundle_fields)),
        ]);
        // Let's try the common alternative if the current one fails.
        $alternative_source_field = ($source_field === 'field_image') ? 'field_media_image' : 'field_image';
        if (isset($bundle_fields[$alternative_source_field])) {
          $this->logger->warning('MediaService: Falling back to using @alt_field for bundle @bundle.', [
            '@alt_field' => $alternative_source_field,
            '@bundle' => $bundle,
          ]);
          $source_field = $alternative_source_field;
        }
        else {
          // This would be the original user-set one if fallback also failed.
          $this->logger->error('MediaService: Neither @source_field nor @alt_field found on bundle @bundle.', [
            '@source_field' => $source_field,
            '@alt_field' => $alternative_source_field,
            '@bundle' => $bundle,
          ]);
          return NULL;
        }
      }
      else {
        $this->logger->debug('MediaService: Successfully found source field @source_field on bundle @bundle.', [
          '@source_field' => $source_field,
          '@bundle' => $bundle,
        ]);
      }

      $media_values = [
        'bundle' => $bundle,
        'uid' => $owner_id,
        // Use TRUE for boolean values.
        'status' => TRUE,
        'name' => 'AI-generated ' . $bundle . ' from API',
        $source_field => [
          'target_id' => $file_id,
          'alt' => $alt_text,
        ],
      ];
      $this->logger->debug('MediaService: Creating media with values: @values', ['@values' => json_encode($media_values)]);

      $media = Media::create($media_values);
      $media->save();
      $media_id = $media->id();
      $media_revision_id = $media->getRevisionId();

      $color_blue = "\033[0;34m";
      $icon_media = "🖼️";
      $color_reset = "\033[0m";

      $this->logger->notice(
        // $color_blue . $icon_media . ' Successfully saved Media entity (bundle: "@bundle", ID: @id, alt: "@alt").' . $color_reset,
        'MediaService: Successfully saved Media entity (bundle: "@bundle", ID: @id, alt: "@alt").',
        [
          '@bundle' => $bundle,
          '@id' => $media_id,
          '@alt' => $alt_text,
        ]
      );

      return ['id' => $media_id, 'revision_id' => $media_revision_id];
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating placeholder media: @error', [
        '@error' => $e->getMessage(),
      ]);
      // Fall back to returning a fixed media ID in case of error, but try to get its revision.
      $fallback_media = $this->entityTypeManager->getStorage('media')->load(1);
      if ($fallback_media) {
        return [
          'id' => 1,
          'revision_id' => $fallback_media->getRevisionId(),
        ];
      }
      return NULL;
    }
  }

  /**
   * Extracts a simple search term from alt text.
   *
   * @param string $alt_text
   *   The alt text to extract search term from.
   *
   * @return string|null
   *   A cleaned search term or NULL if none found.
   */
  protected function extractSearchTermFromAlt(string $alt_text): ?string {
    // Clean up the alt text to extract the most relevant search term.
    $alt_text = strtolower(trim($alt_text));

    // Remove common articles, prepositions, and connecting words.
    $stopwords = [
      'a', 'an', 'the', 'of', 'in', 'on', 'at', 'by', 'for', 'with', 'and', 'or', 'but',
    ];

    // Split into words and filter.
    $words = preg_split('/\s+/', $alt_text);
    $meaningful_words = array_filter($words, function ($word) use ($stopwords) {
      // Remove stopwords, very short words, and words with special characters.
      return strlen($word) > 2 &&
             !in_array($word, $stopwords) &&
             preg_match('/^[a-z]+$/', $word);
    });

    // Return the first meaningful word as the search term.
    if (!empty($meaningful_words)) {
      $search_term = reset($meaningful_words);
      $this->logger->debug('🖼️ MediaService: Extracted search term "@term" from alt text "@alt"', [
        '@term' => $search_term,
        '@alt' => $alt_text,
      ]);
      return $search_term;
    }

    return NULL;
  }

  /**
   * Creates a media entity from a provided URL.
   *
   * @param string $media_url
   *   The URL of the image.
   * @param string $bundle
   *   The media bundle type, should always be 'image'.
   * @param string $alt_text
   *   The alt text for the image.
   * @param int $owner_id
   *   The owner user ID.
   *
   * @return int|null
   *   Media entity ID if successful, NULL otherwise.
   */
  protected function createMediaFromUrl(string $media_url, string $bundle, string $alt_text, int $owner_id): ?array {
    // Ensure the bundle is 'image' for this simplified service.
    if ($bundle !== 'image') {
      $this->logger->warning('MediaService is configured to only handle "image" bundle, but "@bundle" was requested. Attempting to create an image anyway.', [
        '@bundle' => $bundle,
      ]);
      $bundle = 'image';
    }

    try {
      $media_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('media');
      if (!isset($media_bundle_info[$bundle])) {
        $this->logger->error('Image media bundle "@bundle" does not exist.', [
          '@bundle' => $bundle,
        ]);
        return NULL;
      }

      $file_entity = $this->fileService->createFileEntityFromUrl($media_url, $owner_id);
      if (!$file_entity) {
        $this->logger->error('Failed to create file entity from URL: @url', ['@url' => $media_url]);
        return NULL;
      }

      $file_id = $file_entity->id();

      // Hardcoded for image media bundle.
      $source_field = 'field_image';
      $this->logger->debug('MediaService: Attempting to use source field: @source_field for bundle: @bundle', [
        '@source_field' => $source_field,
        '@bundle' => $bundle,
      ]);

      $bundle_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('media', $bundle);
      if (!isset($bundle_fields[$source_field])) {
        $this->logger->error('MediaService: Source field @source_field does NOT exist on image media bundle @bundle. Available fields: @fields', [
          '@source_field' => $source_field,
          '@bundle' => $bundle,
          '@fields' => implode(', ', array_keys($bundle_fields)),
        ]);
        // Let's try the common alternative if the current one fails.
        $alternative_source_field = ($source_field === 'field_image') ? 'field_media_image' : 'field_image';
        if (isset($bundle_fields[$alternative_source_field])) {
          $this->logger->warning('MediaService: Falling back to using @alt_field for bundle @bundle.', [
            '@alt_field' => $alternative_source_field,
            '@bundle' => $bundle,
          ]);
          $source_field = $alternative_source_field;
        }
        else {
          // This would be the original user-set one if fallback also failed.
          $this->logger->error('MediaService: Neither @source_field nor @alt_field found on bundle @bundle.', [
            '@source_field' => $source_field,
            '@alt_field' => $alternative_source_field,
            '@bundle' => $bundle,
          ]);
          return NULL;
        }
      }
      else {
        $this->logger->debug('MediaService: Successfully found source field @source_field on bundle @bundle.', [
          '@source_field' => $source_field,
          '@bundle' => $bundle,
        ]);
      }

      $media_values = [
        'bundle' => $bundle,
        'uid' => $owner_id,
        // Use TRUE for boolean values.
        'status' => TRUE,
        'name' => 'AI-generated ' . $bundle . ' from API',
        $source_field => [
          'target_id' => $file_id,
          'alt' => $alt_text,
        ],
      ];
      $this->logger->debug('MediaService: Creating media with values: @values', ['@values' => json_encode($media_values)]);

      $media = Media::create($media_values);
      $media->save();
      $media_id = $media->id();
      $media_revision_id = $media->getRevisionId();

      $color_blue = "\033[0;34m";
      $icon_media = "🖼️";
      $color_reset = "\033[0m";

      $this->logger->notice(
        // $color_blue . $icon_media . ' Successfully saved Media entity (bundle: "@bundle", ID: @id, alt: "@alt").' . $color_reset,
        'MediaService: Successfully saved Media entity (bundle: "@bundle", ID: @id, alt: "@alt").',
        [
          '@bundle' => $bundle,
          '@id' => $media_id,
          '@alt' => $alt_text,
        ]
      );

      return ['id' => $media_id, 'revision_id' => $media_revision_id];
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating media from URL: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
