<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\drupalx_ai\Service\OutputFormatterService;

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
   * The output formatter service.
   *
   * @var \Drupal\drupalx_ai\Service\OutputFormatterService
   */
  protected OutputFormatterService $outputFormatter;

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
   * @param \Drupal\drupalx_ai\Service\OutputFormatterService $output_formatter
   *   The output formatter service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ParagraphService $paragraph_service,
    MediaService $media_service,
    TaxonomyService $taxonomy_service,
    FileService $file_service,
    OutputFormatterService $output_formatter = NULL
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->paragraphService = $paragraph_service;
    $this->mediaService = $media_service;
    $this->taxonomyService = $taxonomy_service;
    $this->fileService = $file_service;
    $this->outputFormatter = $output_formatter ?: \Drupal::service('drupalx_ai.output_formatter_service');
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
   *   Whether to generate detailed logs for debugging. Default is TRUE.
   *
   * @return array
   *   The preprocessed component data.
   */
  public function preprocessComponents(array $components_data, bool $detailed_logging = TRUE): array {
    // Check if we have any standalone card components.
    $card_components = [];
    $non_card_components = [];
    $card_group_needed = FALSE;

    // First pass - identify problematic components.
    foreach ($components_data as $index => $component) {
      // Missing type is a major issue.
      if (!isset($component['type'])) {
        $this->logger->error('EntitySaveService: Component at index @index is missing type field: @data', [
          '@index' => $index,
          '@data' => json_encode($component),
        ]);
        continue;
      }

      $component_type = strtolower($component['type']);

      // Only log details for each component if detailed logging is enabled.
      // We'll keep logs minimal for command-line operations.
      if ($detailed_logging) {
        $this->logger->debug('EntitySaveService: Processing component type: @type at index @index', [
          '@type' => $component_type,
          '@index' => $index,
        ]);
      }

      // Handle cards.
      if ($component_type === 'card') {
        $card_components[] = $component;
        $card_group_needed = TRUE;
        $this->logger->warning('EntitySaveService: Found standalone card at index @index that will be regrouped: @data', [
          '@index' => $index,
          '@data' => json_encode($component),
        ]);
      }
      // Special handling for card_group to ensure it has proper structure.
      elseif ($component_type === 'card_group') {
        // Ensure field_card is properly populated.
        if (!isset($component['field_card']) || !is_array($component['field_card'])) {
          $this->logger->warning('Card group at index @index has missing or invalid field_card: @data', [
            '@index' => $index,
            '@data' => json_encode($component),
          ]);

          // Initialize field_card as empty array if missing.
          $component['field_card'] = [];
        }
        elseif ($detailed_logging) {
          $this->logger->notice('Card group at index @index has @count cards', [
            '@index' => $index,
            '@count' => count($component['field_card']),
          ]);
        }

        // Verify that all field_card items have type=card.
        if (!empty($component['field_card'])) {
          foreach ($component['field_card'] as $card_index => $card) {
            if (!isset($card['type']) || strtolower($card['type']) !== 'card') {
              $this->logger->warning('Card group contains item at index @card_index that is missing type=card: @data', [
                '@card_index' => $card_index,
                '@data' => json_encode($card),
              ]);

              // Fix by explicitly setting type.
              $component['field_card'][$card_index]['type'] = 'card';
            }
          }
        }

        $non_card_components[] = $component;
      }
      // Handle specific cases where cards might be in the wrong field.
      elseif (isset($component['card']) && is_array($component['card'])) {
        $this->logger->warning('Component type @type has a "card" field that should be field_card: @data', [
          '@type' => $component_type,
          '@data' => json_encode($component),
        ]);

        // Check if these are indeed cards we can reuse.
        $valid_cards = TRUE;
        foreach ($component['card'] as $card) {
          if (!isset($card['type']) || (!in_array(strtolower($card['type']), ['card', 'stats_item']))) {
            $valid_cards = FALSE;
            break;
          }
        }

        if ($valid_cards && !empty($component['card'])) {
          // Create a proper card_group with these cards.
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
        }
        else {
          // If not valid cards, just keep the component as is.
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

}
