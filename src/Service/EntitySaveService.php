<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for coordinating entity saving operations based on AI data.
 *
 * This service delegates specific entity operations to specialized services.
 */
class EntitySaveService {
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
   * The paragraph service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphService
   */
  protected ParagraphService $paragraphService;

  /**
   * The media service.
   *
   * @var \Drupal\drupalx_ai\Service\MediaService
   */
  protected MediaService $mediaService;

  /**
   * The taxonomy service.
   *
   * @var \Drupal\drupalx_ai\Service\TaxonomyService
   */
  protected TaxonomyService $taxonomyService;

  /**
   * The file service.
   *
   * @var \Drupal\drupalx_ai\Service\FileService
   */
  protected FileService $fileService;

  /**
   * Constructs a new EntitySaveService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\ParagraphService $paragraph_service
   *   The paragraph service.
   * @param \Drupal\drupalx_ai\Service\MediaService $media_service
   *   The media service.
   * @param \Drupal\drupalx_ai\Service\TaxonomyService $taxonomy_service
   *   The taxonomy service.
   * @param \Drupal\drupalx_ai\Service\FileService $file_service
   *   The file service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ParagraphService $paragraph_service,
    MediaService $media_service,
    TaxonomyService $taxonomy_service,
    FileService $file_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->paragraphService = $paragraph_service;
    $this->mediaService = $media_service;
    $this->taxonomyService = $taxonomy_service;
    $this->fileService = $file_service;
  }

  /**
   * Saves entities to a node's content field.
   *
   * This is the main entry point for saving AI-generated components to a node.
   * It delegates the actual work to specialized services.
   *
   * @param int $nid
   *   The node ID.
   * @param array $components_data
   *   Array of component data to save.
   *
   * @return array
   *   Array of created entity IDs.
   */
  public function saveEntitiesToNode(int $nid, array $components_data): array {
    // Log the full component data structure we're receiving.
    $this->logger->notice('Full components data JSON structure: @data', [
      '@data' => json_encode($components_data, JSON_PRETTY_PRINT),
    ]);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if (!$node) {
      $this->logger->error('Node with ID @nid not found.', ['@nid' => $nid]);
      return [];
    }

    $created_entity_ids = [];

    // Process each component and add to the node.
    foreach ($components_data as $component_data) {
      $paragraph_id = $this->paragraphService->createNestedParagraph(
        $component_data,
        $node->id()
      );

      if ($paragraph_id) {
        $created_entity_ids[] = $paragraph_id;
      }
    }

    return $created_entity_ids;
  }

  /**
   * Creates or loads a media item.
   *
   * This method exists for backward compatibility. It delegates to MediaService.
   *
   * @param array $media_data
   *   Array containing media information.
   * @param int $owner_id
   *   The user ID to set as the owner of the media item.
   *
   * @return int|null
   *   The media ID or NULL on failure.
   */
  public function createOrLoadMediaItem(array $media_data, int $owner_id): ?int {
    return $this->mediaService->createOrLoadMediaItem($media_data, $owner_id);
  }

  /**
   * Gets a taxonomy term ID by name, creating it if it doesn't exist.
   *
   * This method exists for backward compatibility. It delegates to TaxonomyService.
   *
   * @param string $term_name
   *   The name of the taxonomy term.
   * @param array|string $vocabularies
   *   A single vocabulary machine name or an array of vocabulary machine names.
   *
   * @return int|null
   *   The term ID or NULL if not found/created.
   */
  public function getTermIdByName(string $term_name, $vocabularies): ?int {
    return $this->taxonomyService->getTermIdByName($term_name, $vocabularies);
  }

  /**
   * Sanitizes a filename by removing potentially problematic characters.
   *
   * This method exists for backward compatibility. It delegates to FileService.
   *
   * @param string $filename
   *   The original filename.
   *
   * @return string
   *   The sanitized filename.
   */
  public function sanitizeFilename(string $filename): string {
    return $this->fileService->sanitizeFilename($filename);
  }

}
