<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;

/**
 * Service for handling files in the DrupalX AI module.
 */
class FileService {
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
   * Constructs a new FileService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    AccountInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
  }

  /**
   * Sanitizes a filename by removing potentially problematic characters.
   *
   * @param string $filename
   *   The original filename.
   *
   * @return string
   *   The sanitized filename.
   */
  public function sanitizeFilename(string $filename): string {
    // Remove anything which isn't a word, whitespace, number
    // or any of the following characters -_.
    $filename = preg_replace('/[^\pL\pN\s\d\-_\.]/u', '', $filename);
    // Remove any runs of periods.
    $filename = preg_replace('/\.(?=\.)/', '', $filename);
    // Replace known delimiters with a single hyphen.
    $filename = preg_replace('/[\s\-_]+/', '-', $filename);
    // Remove leading and trailing hyphens.
    $filename = trim($filename, '-');
    if (empty($filename)) {
      return 'unnamed_file';
    }
    // Ensure filename is not too long (e.g. less than 200 chars after
    // sanitization).
    $filename = mb_substr(mb_strtolower($filename), 0, 200);
    // Remove trailing period if any.
    $filename = rtrim($filename, '.');
    if (empty($filename)) {
      // Re-check after trimming and substr.
      return 'unnamed_file';
    }
    return $filename;
  }

  /**
   * Creates a Drupal file entity from a given URI.
   *
   * @param string $file_uri
   *   The URI of the file (e.g., 'public://image.png').
   * @param int $owner_id
   *   The user ID to set as the owner of the file.
   * @param string|null $filename_override
   *   Optional. If provided, this filename will be used directly.
   *   Otherwise, the filename is derived from $file_uri.
   *
   * @return \Drupal\file\FileInterface|null
   *   The created file entity, or NULL on failure.
   */
  public function createFileEntity(string $file_uri, int $owner_id, ?string $filename_override = NULL): ?FileInterface {
    try {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $filename = $filename_override ?? basename($file_uri);
      $file = $file_storage->create([
        'uri' => $file_uri,
        'uid' => $owner_id,
        'filename' => $filename,
        // 1 for permanent, 0 for temporary.
        'status' => 1,
      ]);
      $file->save();
      return $file;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create file entity for URI @uri: @error', [
        '@uri' => $file_uri,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a placeholder file entity.
   *
   * @param int $owner_id
   *   The owner user ID.
   *
   * @return int|null
   *   File entity ID if successful, NULL otherwise.
   */
  public function createPlaceholderFile(int $owner_id): ?int {
    // In a production environment, this would create an actual file.
    // For the prototype, we'll log the intent and return file ID 1 as a
    // placeholder.
    $this->logger->notice('Would create a placeholder file with owner ID: @owner_id', [
      '@owner_id' => $owner_id,
    ]);

    return 1;
  }

  /**
   * Downloads an image from a URL and creates a Drupal file entity.
   *
   * @param string $url
   *   The URL of the image to download.
   * @param int $owner_id
   *   The user ID to set as the owner of the file.
   *
   * @return \Drupal\file\FileInterface|null
   *   The created file entity, or NULL on failure.
   */
  public function createFileEntityFromUrl(string $url, int $owner_id): ?FileInterface {
    try {
      // Extract filename from URL.
      $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
      $extension = $path_info['extension'] ?? 'jpg';
      $base_name = $path_info['filename'] ?? 'image';

      // Create a unique filename.
      $filename = $this->sanitizeFilename($base_name) . '_' . time() . '.' . $extension;
      $destination_uri = 'public://ai_images/' . $filename;

      // Ensure the directory exists.
      $directory = dirname($destination_uri);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Failed to create directory: @directory', ['@directory' => $directory]);
        return NULL;
      }

      // Download the file.
      $file_contents = file_get_contents($url);
      if ($file_contents === FALSE) {
        $this->logger->error('Failed to download image from URL: @url', ['@url' => $url]);
        return NULL;
      }

      // Save the file.
      $file_uri = $this->fileSystem->saveData($file_contents, $destination_uri, FileSystemInterface::EXISTS_REPLACE);
      if (!$file_uri) {
        $this->logger->error('Failed to save downloaded image to: @destination', ['@destination' => $destination_uri]);
        return NULL;
      }

      // Create the file entity.
      $file_entity = $this->createFileEntity($file_uri, $owner_id, $filename);
      if ($file_entity) {
        $this->logger->info('Successfully downloaded and created file entity from URL: @url -> @uri', [
          '@url' => $url,
          '@uri' => $file_uri,
        ]);
      }

      return $file_entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Error downloading image from URL @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
