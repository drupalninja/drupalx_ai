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
 * Service for handling media entities in the DrupalX AI module.
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
   * Creates or loads a media item.
   *
   * @param array $media_data
   *   Array containing media information.
   *   Expected keys:
   *     'media_url' (string): The URL of the media.
   *     'media_alt' (string): The alt text for the media.
   *     'bundle' (string): The media bundle type.
   * @param int $owner_id
   *   The user ID to set as the owner of the media item.
   *
   * @return int|null
   *   The media ID or NULL on failure.
   */
  public function createOrLoadMediaItem(array $media_data, int $owner_id): ?int {
    // Log the request.
    $this->logger->notice('Media data received: @data', [
      '@data' => json_encode($media_data),
    ]);

    // Extract values from media data.
    $media_url = $media_data['media_url'] ?? ($media_data['url'] ?? NULL);
    $alt_text = $media_data['media_alt'] ?? ($media_data['alt'] ?? 'AI-generated media');
    $bundle = $media_data['bundle'] ?? 'image';

    // If we don't have a URL, use a placeholder.
    if (empty($media_url)) {
      $this->logger->notice('No media URL provided, using placeholder media.');
      return $this->createPlaceholderMediaItem($bundle, $alt_text, $owner_id);
    }

    // In a prototype/demo environment, we'll use placeholder media instead of
    // actually downloading and creating new media entities.
    return $this->createPlaceholderMediaItem($bundle, $alt_text, $owner_id);
  }

  /**
   * Creates a placeholder media item for use in AI-generated content.
   *
   * @param string $bundle
   *   The media bundle type (e.g., 'image', 'video', etc.).
   * @param string $alt_text
   *   The alt text for the media.
   * @param int $owner_id
   *   The owner user ID.
   *
   * @return int|null
   *   Media entity ID if successful, NULL otherwise.
   */
  public function createPlaceholderMediaItem(string $bundle, string $alt_text, int $owner_id): ?int {
    try {
      // Check if the media bundle exists.
      $media_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('media');
      if (!isset($media_bundle_info[$bundle])) {
        $this->logger->error('Media bundle @bundle does not exist.', [
          '@bundle' => $bundle,
        ]);
        return NULL;
      }

      // Create a placeholder file (in a real implementation, we would use a default placeholder file).
      // For now, we'll use file ID 1 as a placeholder.
      $file_id = 1;

      // Use field_image as the source field for image media.
      $source_field = 'field_image';

      // Log media creation for debugging.
      $this->logger->notice('Creating @bundle media with source field @field and alt text: @alt', [
        '@bundle' => $bundle,
        '@field' => $source_field,
        '@alt' => $alt_text,
      ]);

      // Create the media entity.
      $media = Media::create([
        'bundle' => $bundle,
        'uid' => $owner_id,
        'status' => 1,
        'name' => 'AI-generated ' . $bundle . ' placeholder',
        $source_field => [
          'target_id' => $file_id,
          'alt' => $alt_text,
        ],
      ]);

      $media->save();
      return $media->id();
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
