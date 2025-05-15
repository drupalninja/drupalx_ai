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
   * Saves components data to a given node.
   *
   * It delegates the actual work to specialized services.
   *
   * @param int $nid
   *   The node ID.
   * @param array $components_data
   *   Array of component data to save.
   * @param bool $already_preprocessed
   *   Whether the components have already been preprocessed.
   *
   * @return array
   *   Array of created entity IDs.
   */
  public function saveEntitiesToNode(int $nid, array $components_data, bool $already_preprocessed = false): array {
    // Only log the full component data if it hasn't been preprocessed already
    if (!$already_preprocessed) {
      // Log the full component data structure we're receiving.
      $this->logger->notice('EntitySaveService: Full components data JSON structure: @data', [
        '@data' => json_encode($components_data, JSON_PRETTY_PRINT),
      ]);
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if (!$node) {
      $this->logger->error('EntitySaveService: Node with ID @nid not found.', ['@nid' => $nid]);
      return [];
    }

    // Clear any existing paragraphs from the node
    if ($node->hasField('field_content')) {
      $this->logger->notice('EntitySaveService: Clearing existing paragraphs from node @nid.', ['@nid' => $nid]);
      $node->set('field_content', []);
      $node->save();
    }
    
    // Quick check if we need to do any preprocessing
    if (!$already_preprocessed) {
      $need_preprocessing = FALSE;
      foreach ($components_data as $component) {
        if (isset($component['type']) && strtolower($component['type']) === 'card') {
          $need_preprocessing = TRUE;
          break;
        }
      }
      
      // Only preprocess if needed (to avoid duplicate preprocessing)
      if ($need_preprocessing) {
        $this->logger->notice('EntitySaveService: Detected potential card structure issues, performing preprocessing...');
        // Use detailed logging only if the components weren't preprocessed elsewhere
        $components_data = $this->preprocessComponents($components_data, true);
      }
      else {
        $this->logger->notice('EntitySaveService: No standalone cards detected, skipping preprocessing.');
      }
    }
    else {
      $this->logger->notice('EntitySaveService: Components were already preprocessed elsewhere, skipping preprocessing step.');
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

    // Attach paragraphs to the node's field_content.
    if (!empty($created_entity_ids) && $node->hasField('field_content')) {
      // Load all paragraphs to check their bundle types before attaching
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $paragraph_references = [];
      $added_count = 0;

      foreach ($created_entity_ids as $pid) {
        $paragraph = $paragraph_storage->load($pid);
        if (!$paragraph) {
          $this->logger->warning('Could not load paragraph with ID @id', ['@id' => $pid]);
          continue;
        }

        // Skip any 'card' paragraphs at the top level - they should only be children of card_group
        if ($paragraph->bundle() === 'card') {
          $this->logger->warning('Skipping attaching card paragraph @id to node directly - cards should only be in card_groups', ['@id' => $pid]);
          continue;
        }

        $paragraph_references[] = [
          'target_id' => $pid,
          'target_revision_id' => $pid,
        ];
        $added_count++;
      }

      $node->set('field_content', $paragraph_references);
      $node->save();

      $this->logger->notice('Added @count paragraphs to node @nid.', [
        '@count' => count($created_entity_ids),
        '@nid' => $nid,
      ]);
    }

    return $created_entity_ids;
  }
  
  /**
   * Preprocesses component data to ensure proper nesting structure.
   *
   * This function reorganizes standalone card components by moving them into 
   * appropriate card_group containers.
   *
   * @param array $components_data
   *   The raw component data from AI.
   * @param bool $detailed_logging
   *   Whether to generate detailed logs for debugging. Default is true.
   *
   * @return array
   *   The preprocessed component data.
   */
  public function preprocessComponents(array $components_data, bool $detailed_logging = true): array {
    // Debug the incoming components structure
    if ($detailed_logging) {
      $this->logger->notice('EntitySaveService: Preprocessing components: @data', [
        '@data' => json_encode($components_data, JSON_PRETTY_PRINT),
      ]);
    }
    
    // Check if we have any standalone card components
    $card_components = [];
    $non_card_components = [];
    $card_group_needed = FALSE;
    
    // First pass - identify problematic components
    foreach ($components_data as $index => $component) {
      // Missing type is a major issue
      if (!isset($component['type'])) {
        $this->logger->error('EntitySaveService: Component at index @index is missing type field: @data', [
          '@index' => $index,
          '@data' => json_encode($component),
        ]);
        continue;
      }
      
      $component_type = strtolower($component['type']);
      
      // Only log details for each component if detailed logging is enabled
      if ($detailed_logging) {
        $this->logger->notice('EntitySaveService: Processing component type: @type at index @index', [
          '@type' => $component_type,
          '@index' => $index, 
        ]);
      }
      
      // Handle cards
      if ($component_type === 'card') {
        $card_components[] = $component;
        $card_group_needed = TRUE;
        $this->logger->warning('EntitySaveService: Found standalone card at index @index that will be regrouped: @data', [
          '@index' => $index,
          '@data' => json_encode($component),
        ]);
      } 
      // Special handling for card_group to ensure it has proper structure
      elseif ($component_type === 'card_group') {
        // Ensure field_card is properly populated
        if (!isset($component['field_card']) || !is_array($component['field_card'])) {
          $this->logger->warning('Card group at index @index has missing or invalid field_card: @data', [
            '@index' => $index, 
            '@data' => json_encode($component),
          ]);
          
          // Initialize field_card as empty array if missing
          $component['field_card'] = [];
        } 
        elseif ($detailed_logging) {
          $this->logger->notice('Card group at index @index has @count cards', [
            '@index' => $index,
            '@count' => count($component['field_card']),
          ]);
        }
        
        // Verify that all field_card items have type=card
        if (!empty($component['field_card'])) {
          foreach ($component['field_card'] as $card_index => $card) {
            if (!isset($card['type']) || strtolower($card['type']) !== 'card') {
              $this->logger->warning('Card group contains item at index @card_index that is missing type=card: @data', [
                '@card_index' => $card_index,
                '@data' => json_encode($card),
              ]);
              
              // Fix by explicitly setting type
              $component['field_card'][$card_index]['type'] = 'card';
            }
          }
        }
        
        $non_card_components[] = $component;
      }
      // Handle specific cases where cards might be in the wrong field
      elseif (isset($component['card']) && is_array($component['card'])) {
        $this->logger->warning('Component type @type has a "card" field that should be field_card: @data', [
          '@type' => $component_type,
          '@data' => json_encode($component),
        ]);
        
        // Check if these are indeed cards we can reuse
        $valid_cards = TRUE;
        foreach ($component['card'] as $card) {
          if (!isset($card['type']) || (!in_array(strtolower($card['type']), ['card', 'stats_item']))) {
            $valid_cards = FALSE;
            break;
          }
        }
        
        if ($valid_cards && !empty($component['card'])) {
          // Create a proper card_group with these cards
          $card_group = [
            'type' => 'card_group',
            'field_title' => $component['title'] ?? $component['field_title'] ?? 'Related Items',
            'field_card' => $component['card'],
          ];
          
          if ($detailed_logging) {
            $this->logger->notice('Created card_group from component with "card" field: @data', [
              '@data' => json_encode($card_group),
            ]);
          }
          
          $non_card_components[] = $card_group;
        } else {
          // If not valid cards, just keep the component as is
          $non_card_components[] = $component;
        }
      } 
      else {
        $non_card_components[] = $component;
      }
    }
    
    // If we have standalone cards, create a card group for them
    if ($card_group_needed && !empty($card_components)) {
      $this->logger->warning('Found @count standalone card components that need to be grouped.', [
        '@count' => count($card_components),
      ]);
      
      // Create a new card_group component
      $card_group = [
        'type' => 'card_group',
        'field_title' => 'Additional Information',
        'field_card' => $card_components,
      ];
      
      $non_card_components[] = $card_group;
      
      if ($detailed_logging) {
        $this->logger->notice('Created a new card_group component to contain @count standalone cards: @data', [
          '@count' => count($card_components),
          '@data' => json_encode($card_group),
        ]);
      }
    }
    
    return $non_card_components;
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
