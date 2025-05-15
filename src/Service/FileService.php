<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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

}
