<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\media\Entity\Media;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\drupalx_ai\Service\OutputFormatterService;

/**
 * Service for handling paragraph entities in the DrupalX AI module.
 */
class ParagraphService {
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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The media service.
   *
   * @var \Drupal\drupalx_ai\Service\MediaService
   */
  protected MediaService $mediaService;

  /**
   * The output formatter service.
   *
   * @var \Drupal\drupalx_ai\Service\OutputFormatterService
   */
  protected OutputFormatterService $outputFormatter;

  /**
   * The taxonomy service.
   *
   * @var \Drupal\drupalx_ai\Service\TaxonomyService
   */
  protected TaxonomyService $taxonomyService;

  /**
   * Constructs a new ParagraphService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The bundle information service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\drupalx_ai\Service\MediaService $media_service
   *   The media service.
   * @param \Drupal\drupalx_ai\Service\TaxonomyService $taxonomy_service
   *   The taxonomy service.
   * @param \Drupal\drupalx_ai\Service\OutputFormatterService $output_formatter
   *   The output formatter service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    MediaService $media_service,
    TaxonomyService $taxonomy_service,
    OutputFormatterService $output_formatter
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->mediaService = $media_service;
    $this->taxonomyService = $taxonomy_service;
    $this->outputFormatter = $output_formatter;
  }

  /**
   * Saves paragraphs, media, and other entities to a node's content field.
   *
   * @param int $nid
   *   The Node ID to attach entities to.
   * @param array $components_data
   *   Data for components (paragraphs, media, etc.) from the AI.
   *
   * @return array
   *   An array of created paragraph entity IDs.
   *   Returns empty array on failure or if no components were processed.
   */
  public function saveEntitiesToNode(int $nid, array $components_data): array {
    // Log the full component data structure we're receiving.
    $this->logger->notice('Full components data JSON structure: @data', [
      '@data' => json_encode($components_data, JSON_PRETTY_PRINT),
    ]);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if (!$node) {
      $this->logger->error('Node with NID @nid not found for saving entities.', ['@nid' => $nid]);
      return [];
    }

    if (!$node->hasField('field_content') ||
        $node->field_content->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
      $this->logger->error('Node does not have a valid field_content field for paragraphs.');
      return [];
    }

    $paragraph_ids = [];
    $owner_id = $node->getOwnerId();

    foreach ($components_data as $component_data) {
      // Handle the component based on its type.
      $paragraph_id = $this->createNestedParagraph($component_data, $owner_id);
      if ($paragraph_id) {
        $paragraph_ids[] = $paragraph_id;
      }
    }

    if (!empty($paragraph_ids)) {
      // Clear any existing paragraphs from the node
      if ($node->hasField('field_content')) {
        $this->logger->notice('Clearing existing paragraphs from node @nid.', ['@nid' => $nid]);
        $node->set('field_content', []);
        $node->save();
      }

      // Attach paragraph references to the node.
      $paragraph_references = [];
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $filtered_count = 0;

      foreach ($paragraph_ids as $pid) {
        // Load the paragraph to check if it should be included
        $paragraph = $paragraph_storage->load($pid);
        if (!$paragraph) {
          continue;
        }

        // Get the bundle type
        $bundle_type = $paragraph->bundle();

        // Log the processing of this paragraph
        $this->logger->notice('Processing paragraph @id of type @type for field_content attachment.', [
          '@id' => $pid,
          '@type' => $bundle_type,
        ]);

        // Define child-only paragraph types that should never be at the top level
        $child_only_types = [
          'card',
          'accordion_item',
          'carousel_item',
          'bullet',
          'feature_item',
          'pricing_card',
        ];

        // Skip paragraphs that are marked to bypass the main collection (child paragraphs)
        if (isset($paragraph->setInternalBypassMainCollection) && $paragraph->setInternalBypassMainCollection === TRUE) {
          $this->logger->warning('FILTERING: Skipping paragraph @id of type @type from field_content as it is marked as a child-only paragraph.', [
            '@id' => $pid,
            '@type' => $bundle_type,
          ]);
          continue;
        }

        // CRITICAL CHECK: Ensure no child-only paragraphs get attached directly to node
        if (in_array($bundle_type, $child_only_types, TRUE)) {
          $this->logger->emergency('BLOCKING: Prevented attaching child-only paragraph @id of type @type directly to node @nid.', [
            '@id' => $pid,
            '@type' => $bundle_type,
            '@nid' => $nid,
          ]);
          continue;
        }

        // Additional safety check for any variations of card type that might slip through
        if (strpos(strtolower($bundle_type), 'card') !== FALSE && $bundle_type !== 'card_group') {
          $this->logger->emergency('BLOCKING: Prevented attaching card-like paragraph @id of type @type directly to node @nid.', [
            '@id' => $pid,
            '@type' => $bundle_type,
            '@nid' => $nid,
          ]);
          continue;
        }

        $paragraph_references[] = [
          'target_id' => $pid,
          'target_revision_id' => $pid,
        ];
        $filtered_count++;
      }

      $node->set('field_content', $paragraph_references);
      $node->save();

      $this->logger->notice('Added @count paragraphs to node @nid (filtered from @total).', [
        '@count' => $filtered_count,
        '@total' => count($paragraph_ids),
        '@nid' => $nid,
      ]);
    }

    return $paragraph_ids;
  }

  /**
   * Creates a nested paragraph component with the given data.
   *
   * @param array $component_data
   *   The component data to use for creating the paragraph.
   * @param int $owner_id
   *   The owner ID.
   *
   * @return int|null
   *   The paragraph entity ID if successful, NULL otherwise.
   */
  public function createNestedParagraph(array $component_data, int $owner_id): ?int {
    // Enhanced logging of incoming component data
    $this->logger->notice('Creating nested paragraph from component: @data', [
      '@data' => json_encode($component_data),
    ]);

    // Validate component data.
    if (empty($component_data['type']) || !is_string($component_data['type'])) {
      $this->logger->error('Component data missing required type or type is not a string.', [
        'data' => json_encode($component_data),
      ]);
      return NULL;
    }

    // Normalize the component type to handle case variations (e.g., card, Card)
    $normalized_type = strtolower($component_data['type']);

    // CRITICAL GUARD: Ensure card components are never processed directly
    if ($normalized_type === 'card') {
      $this->logger->emergency('BLOCKING: Attempted to create a standalone card paragraph. Cards must only be created as part of a card_group.', []);
      // Return without creating the paragraph
      return NULL;
    }

    // Define child-only component types (these should never be top-level).
    $child_only_types = [
      'card',
      'accordion_item',
      'carousel_item',
      'bullet',
      'feature_item',
      'pricing_card',
    ];

    // Check if this is a child-only component type - strict check to avoid matching card_group
    if (in_array($normalized_type, $child_only_types, TRUE)) {
      $this->logger->warning('Component type @type is a child-only component and cannot be created at the top level. Skipping.', [
        '@type' => $component_data['type'],
      ]);
      return NULL;
    }

    // Define allowed top-level component types (these can be at the top level)
    // These are based on component types that actually exist in sample-components.json
    $allowed_top_level_types = [
      'accordion',
      'card_group',
      'carousel',
      'gallery',
      'hero',
      'logo_collection',
      'newsletter',
      'pricing',
      'quote',
      'sidebyside',
      'text',
    ];

    // Log the type normalization for debugging.
    $this->logger->notice('Component type normalization: @original -> @normalized', [
      '@original' => $component_data['type'],
      '@normalized' => $normalized_type,
    ]);

    // Check if this is a child-only component type with stricter matching.
    foreach ($child_only_types as $child_type) {
      // Make sure we're not matching 'card' with 'card_group' - exact match only for child types
      if ($normalized_type === $child_type) {
        $this->logger->warning('Component type @type (@normalized) is a child-only component and cannot be created at the top level. Skipping.', [
          '@type' => $component_data['type'],
          '@normalized' => $normalized_type,
        ]);
        return NULL;
      }
    }

    // Verify this is an allowed top-level component type
    if (!in_array($normalized_type, $allowed_top_level_types)) {
      $this->logger->warning('Component type @type (@normalized) is not in the list of allowed top-level components. Skipping.', [
        '@type' => $component_data['type'],
        '@normalized' => $normalized_type,
      ]);
      return NULL;
    }

    // Special handling for card_group to ensure it has field_card properly set
    if ($normalized_type === 'card_group' && (!isset($component_data['field_card']) || !is_array($component_data['field_card']))) {
      $this->logger->warning('Card group component is missing field_card array. Creating with empty array.');
      $component_data['field_card'] = [];
    }

    // Map the AI-generated component type to a paragraph bundle.
    // Include both camelCase and snake_case versions for compatibility.
    // Only include components that exist in sample-components.json.
    $type_mapping = [
      // Snake case keys - these map directly to paragraph bundles.
      'accordion' => 'accordion',
      'card_group' => 'card_group',
      'carousel' => 'carousel',
      'gallery' => 'gallery',
      'hero' => 'hero',
      'logo_collection' => 'logo_collection',
      'newsletter' => 'newsletter',
      'pricing' => 'pricing',
      'quote' => 'quote',
      'sidebyside' => 'sidebyside',
      'text' => 'text',

      // Camel case keys (for backward compatibility).
      'cardGroup' => 'card_group',
      'logoCollection' => 'logo_collection',
    ];

    $component_type = $component_data['type'];

    // If the component type is not recognized, log a warning and skip it.
    if (!isset($type_mapping[$component_type])) {
      $this->logger->warning('Unrecognized component type: @type. Skipping this component.', [
        '@type' => $component_type,
      ]);
      return NULL;
    }

    $paragraph_bundle = $type_mapping[$component_type];

    // Check if the paragraph bundle exists.
    $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    if (!isset($paragraph_bundles[$paragraph_bundle])) {
      $this->logger->error('Paragraph bundle @bundle does not exist.', [
        '@bundle' => $paragraph_bundle,
      ]);
      return NULL;
    }

    // Create paragraph with basic information.
    try {
      $paragraph = Paragraph::create([
        'type' => $paragraph_bundle,
        'uid' => $owner_id,
      ]);

      // Set field values from component data.
      $this->setParagraphFields($paragraph, $component_data, $owner_id);

      // Create child entities if needed (for card groups, features, etc.).
      $this->createChildEntities($paragraph, $component_data, $owner_id);

      $paragraph->save();
      $paragraph_id = $paragraph->id();

      $this->logger->notice('Created @type paragraph with ID: @id', [
        '@type' => $paragraph_bundle,
        '@id' => $paragraph_id,
      ]);

      return $paragraph_id;
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating @type paragraph: @message', [
        '@type' => $paragraph_bundle,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Sets field values on a paragraph entity from component data.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity.
   * @param array $component_data
   *   The component data from the AI.
   * @param int $owner_id
   *   The user ID who owns the content.
   */
  protected function setParagraphFields(Paragraph $paragraph, array $component_data, int $owner_id): void {
    // Get available fields for this paragraph type.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      'paragraph',
      $paragraph->bundle()
    );

    // Access fields directly from component data.
    foreach ($component_data as $field_name => $field_value) {
      // Skip the type field as it's already used to create the paragraph.
      if ($field_name === 'type') {
        continue;
      }

      // Skip if the field doesn't exist on this paragraph type.
      if (!isset($field_definitions[$field_name])) {
        $this->logger->warning('Field @field does not exist on paragraph type @type. This appears to be a hallucinated field from the AI.', [
          '@field' => $field_name,
          '@type' => $paragraph->bundle(),
        ]);
        continue;
      }

      $field_type = $field_definitions[$field_name]->getType();

      // Handle different field types.
      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $paragraph->set($field_name, $field_value);
          break;

        case 'link':
          if (is_array($field_value) && isset($field_value['url'])) {
            $link_data = [
              'uri' => $field_value['url'],
              'title' => $field_value['title'] ?? '',
              'options' => [],
            ];
            $paragraph->set($field_name, $link_data);
          }
          elseif (is_string($field_value)) {
            // If it's just a string, assume it's a URL.
            $paragraph->set($field_name, [
              'uri' => $field_value,
              'title' => '',
              'options' => [],
            ]);
          }
          break;

        case 'entity_reference':
          $target_type = $field_definitions[$field_name]->getSetting('target_type');
          if ($target_type === 'media' && !empty($field_value)) {
            // Handle media reference (use MediaService).
            $media_id = $this->mediaService->createOrLoadMediaItem($field_value, $owner_id);
            if ($media_id) {
              $paragraph->set($field_name, ['target_id' => $media_id]);
            }
          }
          elseif ($target_type === 'taxonomy_term' && !empty($field_value)) {
            // Handle taxonomy term reference (use TaxonomyService).
            $target_bundles = $field_definitions[$field_name]->getSetting('handler_settings')['target_bundles'] ?? [];
            $term_id = $this->taxonomyService->getTermIdByName($field_value, array_keys($target_bundles));
            if ($term_id) {
              $paragraph->set($field_name, ['target_id' => $term_id]);
            }
          }
          break;

        default:
          // Log field types that are not explicitly handled.
          $this->logger->notice('Field type @type for field @field not handled explicitly.', [
            '@type' => $field_type,
            '@field' => $field_name,
          ]);
          break;
      }
    }
  }

  /**
   * Creates child entities for complex paragraphs like card groups.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The parent paragraph entity.
   * @param array $component_data
   *   The component data.
   * @param int $owner_id
   *   The owner ID.
   */
  protected function createChildEntities(Paragraph $paragraph, array $component_data, int $owner_id): void {
    // Handle specific paragraph types that need child entities.
    switch ($paragraph->bundle()) {
      case 'card_group':
        $this->createCardGroupItems($paragraph, $component_data, $owner_id);
        break;

      case 'features':
        $this->createFeatureItems($paragraph, $component_data, $owner_id);
        break;

      // Add more cases as needed for other complex paragraph types.
    }
  }

  /**
   * Creates card items for a card group paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The card group paragraph.
   * @param array $component_data
   *   The component data.
   * @param int $owner_id
   *   The owner ID.
   */
  protected function createCardGroupItems(Paragraph $paragraph, array $component_data, int $owner_id): void {
    // Look for cards under either 'cards', 'field_card', or as individual items in 'card' key.
    $cards = [];

    if (!empty($component_data['field_card']) && is_array($component_data['field_card'])) {
      $cards = $component_data['field_card'];
      $this->logger->notice('Found @count cards in field_card array.', ['@count' => count($cards)]);
    }
    elseif (!empty($component_data['cards']) && is_array($component_data['cards'])) {
      $cards = $component_data['cards'];
      $this->logger->notice('Found @count cards in cards array.', ['@count' => count($cards)]);
    }
    // Special case: if there's a single card specified with field_title, field_summary, etc.
    elseif (!empty($component_data['field_title']) || !empty($component_data['title'])) {
      // Create a synthetic card data structure from the current component
      $this->logger->notice('Converting direct card properties to a card item in card_group.');
      $card_data = [];

      // Map commonly expected fields
      foreach (['field_title', 'title', 'field_summary', 'summary', 'field_media', 'media', 'field_link', 'link'] as $field) {
        if (isset($component_data[$field])) {
          $card_data[$field] = $component_data[$field];
        }
      }

      if (!empty($card_data)) {
        $cards = [$card_data];
        $this->logger->notice('Created a synthetic card from component properties.');
      }
    }

    if (empty($cards)) {
      $this->logger->notice('No cards found in card_group component data.');
      return;
    }

    $card_items = [];
    $processed_count = 0;

    foreach ($cards as $card_data) {
      // If the card data has a 'type' field that isn't 'card', add it
      if (!isset($card_data['type']) || $card_data['type'] !== 'card') {
        $card_data['type'] = 'card';
        $this->logger->notice('Added missing type=card to card data in card_group.');
      }

      // Create a card paragraph for each item.
      try {
        $card = Paragraph::create([
          'type' => 'card',
          'uid' => $owner_id,
        ]);

        // Set fields on the card, supporting multiple field name variations.
        // Handle title.
        if (!empty($card_data['field_title'])) {
          $card->set('field_title', $card_data['field_title']);
        }
        elseif (!empty($card_data['title'])) {
          $card->set('field_title', $card_data['title']);
        }

        // Handle content/summary.
        if (!empty($card_data['field_summary'])) {
          $card->set('field_summary', $card_data['field_summary']);
        }
        elseif (!empty($card_data['field_content'])) {
          $card->set('field_summary', $card_data['field_content']);
        }
        elseif (!empty($card_data['summary'])) {
          $card->set('field_summary', $card_data['summary']);
        }
        elseif (!empty($card_data['content'])) {
          $card->set('field_summary', $card_data['content']);
        }

        // Handle media.
        if (!empty($card_data['field_media'])) {
          $media_data = $card_data['field_media'];
          $media_id = $this->mediaService->createOrLoadMediaItem($media_data, $owner_id);
          if ($media_id) {
            $card->set('field_media', ['target_id' => $media_id]);
          }
        }
        elseif (!empty($card_data['image'])) {
          $media_id = $this->mediaService->createPlaceholderMediaItem('image', $card_data['title'] ?? 'Card image', $owner_id);
          if ($media_id) {
            $card->set('field_media', ['target_id' => $media_id]);
          }
        }

        // Handle link.
        if (!empty($card_data['field_link'])) {
          $link_data = $card_data['field_link'];
          // If it's a string, assume it's a URI.
          if (is_string($link_data)) {
            $link_data = [
              'uri' => $link_data,
              'title' => 'Read more',
              'options' => [],
            ];
          }
          // If it's an array, make sure it has the required fields.
          elseif (is_array($link_data)) {
            if (empty($link_data['uri']) && !empty($link_data['url'])) {
              $link_data['uri'] = $link_data['url'];
              unset($link_data['url']);
            }
            if (empty($link_data['uri'])) {
              $link_data['uri'] = 'internal:/';
            }
            if (empty($link_data['title'])) {
              $link_data['title'] = 'Read more';
            }
            if (empty($link_data['options'])) {
              $link_data['options'] = [];
            }
          }
          $card->set('field_link', $link_data);
        }
        elseif (!empty($card_data['link'])) {
          $link_data = $card_data['link'];
          // If it's a string, assume it's a URI.
          if (is_string($link_data)) {
            $link_data = [
              'uri' => $link_data,
              'title' => 'Read more',
              'options' => [],
            ];
          }
          // If it's an array, make sure it has the required fields.
          elseif (is_array($link_data)) {
            if (empty($link_data['uri']) && !empty($link_data['url'])) {
              $link_data['uri'] = $link_data['url'];
              unset($link_data['url']);
            }
            if (empty($link_data['uri'])) {
              $link_data['uri'] = 'internal:/';
            }
            if (empty($link_data['title'])) {
              $link_data['title'] = 'Read more';
            }
            if (empty($link_data['options'])) {
              $link_data['options'] = [];
            }
          }
          $card->set('field_link', $link_data);
        }

        // CRITICAL: Mark this as an internal paragraph that should not be added to the node's field_content directly.
        // This property will be checked in saveEntitiesToNode() to prevent cards from being attached to nodes directly.
        $card->setInternalBypassMainCollection = TRUE;

        // Log that we're creating a card as a child paragraph only
        $this->logger->notice('Created card paragraph @id as a child-only component.', [
          '@id' => $card->id(),
        ]);

        $card->save();
        $processed_count++;

        $card_items[] = [
          'target_id' => $card->id(),
          'target_revision_id' => $card->getRevisionId(),
        ];
      }
      catch (\Exception $e) {
        $this->logger->error('Error creating card paragraph: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($card_items)) {
      $paragraph->set('field_card', $card_items);
      $this->logger->notice('Added @count card paragraphs to card_group paragraph @id.', [
        '@count' => $processed_count,
        '@id' => $paragraph->id(),
      ]);
    }
  }

  /**
   * Creates feature items for a features paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The features paragraph.
   * @param array $component_data
   *   The component data.
   * @param int $owner_id
   *   The owner ID.
   */
  protected function createFeatureItems(Paragraph $paragraph, array $component_data, int $owner_id): void {
    if (empty($component_data['features']) || !is_array($component_data['features'])) {
      return;
    }

    $feature_items = [];
    foreach ($component_data['features'] as $feature_data) {
      try {
        $feature = Paragraph::create([
          'type' => 'feature_item',
          'uid' => $owner_id,
        ]);

        // Set fields on the feature item.
        if (!empty($feature_data['title'])) {
          $feature->set('field_title', $feature_data['title']);
        }

        if (!empty($feature_data['description'])) {
          $feature->set('field_description', $feature_data['description']);
        }

        if (!empty($feature_data['icon'])) {
          $media_id = $this->mediaService->createPlaceholderMediaItem('image', $feature_data['title'] ?? 'Feature icon', $owner_id);
          if ($media_id) {
            $feature->set('field_icon', ['target_id' => $media_id]);
          }
        }

        $feature->save();
        $feature_items[] = [
          'target_id' => $feature->id(),
          'target_revision_id' => $feature->getRevisionId(),
        ];
      }
      catch (\Exception $e) {
        $this->logger->error('Error creating feature item paragraph: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($feature_items)) {
      $paragraph->set('field_items', $feature_items);
    }
  }

}
