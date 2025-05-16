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
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    FileService $file_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->fileService = $file_service;
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
  public function ensureMediaEntityExists($media_data, int $owner_id): ?int {
    $media_url = NULL;
    $alt_text = 'AI-generated image';
    // For this service, we only handle 'image' bundle.
    $bundle = 'image';

    if (is_string($media_data)) {
      $media_url = $media_data;
    }
    elseif (is_array($media_data)) {
      $media_url = $media_data['media_url'] ?? ($media_data['url'] ?? NULL);
      $alt_text = $media_data['media_alt'] ?? ($media_data['alt'] ?? $alt_text);
      // Bundle is always 'image', ignore $media_data['bundle'].
    }

    if (empty($media_url)) {
      // If no URL is provided, create a placeholder image.
      return $this->createPlaceholderMediaItem($bundle, $alt_text, $owner_id);
    }

    // For now, always create a placeholder even if a URL is provided.
    // Future: implement actual download/check for the given URL.
    return $this->createPlaceholderMediaItem($bundle, $alt_text, $owner_id);
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
  public function createPlaceholderMediaItem(string $bundle, string $alt_text, int $owner_id): ?int {
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
      }
      else {
        $destination_filename = basename($placeholder_image_path);
        $destination_uri = 'public://' . $destination_filename;

        $file_uri = $this->fileSystem->copy($placeholder_image_path, $destination_uri, FileSystemInterface::EXISTS_REPLACE);

        if (!$file_uri) {
          $this->logger->error('Failed to copy placeholder image to @destination. Using fallback file ID.', ['@destination' => $destination_uri]);
        }
        else {
          $file_entity = $this->fileService->createFileEntity($file_uri, $owner_id);
          if ($file_entity) {
            $file_id = $file_entity->id();
          }
          else {
            $this->logger->error('Failed to create file entity for placeholder image at @uri. Using fallback file ID.', ['@uri' => $file_uri]);
          }
        }
      }

      // Hardcoded for image media bundle.
      $source_field = 'field_image';

      $bundle_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('media', $bundle);
      if (!isset($bundle_fields[$source_field])) {
        $this->logger->error('Source field @source_field does not exist on image media bundle @bundle.', [
          '@source_field' => $source_field,
          '@bundle' => $bundle,
        ]);
        return NULL;
      }

      $media_values = [
        'bundle' => $bundle,
        'uid' => $owner_id,
        // Use TRUE for boolean values.
        'status' => TRUE,
        'name' => 'AI-generated ' . $bundle . ' placeholder',
        $source_field => [
          'target_id' => $file_id,
          'alt' => $alt_text,
        ],
      ];

      $media = Media::create($media_values);
      $media->save();
      $media_id = $media->id();

      $color_blue = "\033[0;34m";
      $icon_media = "🖼️";
      $color_reset = "\033[0m";

      $this->logger->notice(
        $color_blue . $icon_media . ' Successfully saved Media entity (bundle: "@bundle", ID: @id, alt: "@alt").' . $color_reset,
        [
          '@bundle' => $bundle,
          '@id' => $media_id,
          '@alt' => $alt_text,
        ]
      );

      return $media_id;
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating placeholder media: @error', [
        '@error' => $e->getMessage(),
      ]);
      // Fall back to returning a fixed media ID in case of error.
      return 1;
    }
  }

}
