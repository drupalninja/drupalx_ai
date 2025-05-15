<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

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
    $this->logger->notice($this->outputFormatter->formatLogMessage('Full components data JSON structure: @data', 'notice', [
      '@data' => json_encode($components_data, JSON_PRETTY_PRINT),
    ]));

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if (!$node) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Node with NID @nid not found for saving entities.', 'error', ['@nid' => $nid]));
      return [];
    }

    if (!$node->hasField('field_content') ||
        $node->field_content->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
      $this->logger->error($this->outputFormatter->formatLogMessage('Node does not have a valid field_content field for paragraphs.', 'error'));
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
      else {
        // Log if paragraph creation failed for some reason.
        $component_type = $component_data['type'] ?? 'unknown';
        $this->logger->warning($this->outputFormatter->formatLogMessage('Failed to create paragraph for component type: @type', 'warning', ['@type' => $component_type]));
      }
    }

    if (!empty($paragraph_ids)) {
      // Clear any existing paragraphs from the node.
      if ($node->hasField('field_content')) {
        $this->logger->notice($this->outputFormatter->formatLogMessage('Clearing existing paragraphs from node @nid.', 'notice', ['@nid' => $nid]));
        $node->set('field_content', []);
        $node->save();
      }

      // Attach paragraph references to the node.
      $paragraph_references = [];
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $filtered_count = 0;

      foreach ($paragraph_ids as $pid) {
        // Load the paragraph to check if it should be included.
        $paragraph = $paragraph_storage->load($pid);
        if (!$paragraph) {
          continue;
        }

        // Get the bundle type.
        $bundle_type = $paragraph->bundle();

        // Log the processing of this paragraph.
        $this->logger->notice($this->outputFormatter->formatLogMessage('Processing paragraph @id of type @type for field_content attachment.', 'notice', [
          '@id' => $pid,
          '@type' => $bundle_type,
        ]));

        // Define child-only paragraph types that should never be at the top
        // level.
        $child_only_types = [
          'card',
          'accordion_item',
          'carousel_item',
          'bullet',
          'feature_item',
          'pricing_card',
        ];

        // Skip paragraphs that are marked to bypass the main collection (child
        // paragraphs)
        if (isset($paragraph->setInternalBypassMainCollection) && $paragraph->setInternalBypassMainCollection === TRUE) {
          $this->logger->warning($this->outputFormatter->formatLogMessage('FILTERING: Skipping paragraph @id of type @type from field_content as it is marked as a child-only paragraph.', 'warning', [
            '@id' => $pid,
            '@type' => $bundle_type,
          ]));
          continue;
        }

        // CRITICAL CHECK: Ensure no child-only paragraphs get attached directly
        // to node.
        if (in_array($bundle_type, $child_only_types, TRUE)) {
          $this->logger->error($this->outputFormatter->formatLogMessage('BLOCKING: Prevented attaching child-only paragraph @id of type @type directly to node @nid.', 'error', [
            '@id' => $pid,
            '@type' => $bundle_type,
            '@nid' => $nid,
          ]));
          continue;
        }

        // Additional safety check for any variations of card type that might
        // slip through.
        if (strpos(strtolower($bundle_type), 'card') !== FALSE && $bundle_type !== 'card_group') {
          $this->logger->error($this->outputFormatter->formatLogMessage('BLOCKING: Prevented attaching card-like paragraph @id of type @type directly to node @nid.', 'error', [
            '@id' => $pid,
            '@type' => $bundle_type,
            '@nid' => $nid,
          ]));
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

      $this->logger->notice($this->outputFormatter->formatLogMessage('Added @count paragraphs to node @nid (filtered from @total).', 'notice', [
        '@count' => $filtered_count,
        '@total' => count($paragraph_ids),
        '@nid' => $nid,
      ]));
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
    // Enhanced logging of incoming component data.
    $this->logger->notice($this->outputFormatter->formatLogMessage('Creating nested paragraph from component: @data', 'notice', [
      '@data' => json_encode($component_data),
    ]));

    // Validate component data.
    if (empty($component_data['type']) || !is_string($component_data['type'])) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Component data missing required type or type is not a string.', 'error', [
        'data' => json_encode($component_data),
      ]));
      return NULL;
    }

    $original_type = $component_data['type'];
    // Normalize the component type (e.g., 'card group' to 'card_group').
    $normalized_type = strtolower(str_replace(' ', '_', $original_type));
    // Further sanitize to remove any non-alphanumeric or underscore characters.
    $normalized_type = preg_replace('/[^a-z0-9_]/', '', $normalized_type);

    // CRITICAL GUARD: Ensure card components are never processed directly.
    if ($normalized_type === 'card') {
      $this->logger->error($this->outputFormatter->formatLogMessage('BLOCKING: Attempted to create a standalone card paragraph. Cards must only be created as part of a card_group.', 'error'));
      // Return without creating the paragraph.
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

    // Check if this is a child-only component type - strict check to avoid
    // matching card_group.
    if (in_array($normalized_type, $child_only_types, TRUE)) {
      $this->logger->warning($this->outputFormatter->formatLogMessage('Component type @type is a child-only component and cannot be created at the top level. Skipping.', 'warning', [
        '@type' => $component_data['type'],
      ]));
      return NULL;
    }

    // Define allowed top-level component types (these can be at the top level)
    // These are based on component types that actually exist in
    // sample-components.json.
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
    if ($original_type !== $normalized_type) {
      $this->logger->notice($this->outputFormatter->formatLogMessage('Component type normalization: @original -> @normalized', 'notice', [
        '@original' => $original_type,
        '@normalized' => $normalized_type,
      ]));
    }
    $component_data['type'] = $normalized_type; // Update component data with normalized type.

    // Check if this is a child-only component type with stricter matching.
    foreach ($child_only_types as $child_type) {
      // Make sure we're not matching 'card' with 'card_group' - exact match only for child types
      if ($normalized_type === $child_type) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Component type @type (@normalized) is a child-only component and cannot be created at the top level. Skipping.', 'warning', [
          '@type' => $component_data['type'],
          '@normalized' => $normalized_type,
        ]));
        return NULL;
      }
    }

    // Verify this is an allowed top-level component type
    if (!in_array($normalized_type, $allowed_top_level_types)) {
      $this->logger->warning($this->outputFormatter->formatLogMessage('Component type @type (@normalized) is not in the list of allowed top-level components. Skipping.', 'warning', [
        '@type' => $component_data['type'],
        '@normalized' => $normalized_type,
      ]));
      return NULL;
    }

    // Special handling for card_group to ensure it has field_card properly set.
    if ($normalized_type === 'card_group' && (!isset($component_data['field_card']) || !is_array($component_data['field_card']))) {
      $this->logger->warning($this->outputFormatter->formatLogMessage('Card group component is missing field_card array. Creating with empty array.', 'warning'));
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
      $this->logger->warning($this->outputFormatter->formatLogMessage('Unrecognized component type: @type. Skipping this component.', 'warning', [
        '@type' => $component_type,
      ]));
      return NULL;
    }

    $paragraph_bundle = $type_mapping[$component_type];

    // Check if the paragraph bundle exists.
    $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    if (!isset($paragraph_bundles[$paragraph_bundle])) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Paragraph bundle @bundle does not exist.', 'error', [
        '@bundle' => $paragraph_bundle,
      ]));
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

      $this->logger->notice($this->outputFormatter->formatLogMessage('Created @type paragraph with ID: @id', 'notice', [
        '@type' => $paragraph_bundle,
        '@id' => $paragraph_id,
      ]));

      return $paragraph_id;
    }
    catch (\Exception $e) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Error creating @type paragraph: @message', 'error', [
        '@type' => $paragraph_bundle,
        '@message' => $e->getMessage(),
      ]));
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
        $this->logger->warning($this->outputFormatter->formatLogMessage('Field @field does not exist on paragraph type @type. This appears to be a hallucinated field from the AI.', 'warning', [
          '@field' => $field_name,
          '@type' => $paragraph->bundle(),
        ]));
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
          $this->logger->notice($this->outputFormatter->formatLogMessage('Field type @type for field @field not handled explicitly.', 'notice', [
            '@type' => $field_type,
            '@field' => $field_name,
          ]));
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
    $paragraph_type = $paragraph->bundle();

    $this->logger->notice($this->outputFormatter->formatLogMessage('Checking for child entities for paragraph type: @type', 'notice', ['@type' => $paragraph_type]));

    switch ($paragraph_type) {
      case 'card_group':
        // Check if 'cards' key exists and is an array.
        if (isset($component_data['cards']) && is_array($component_data['cards'])) {
          $this->logger->notice($this->outputFormatter->formatLogMessage('Found "cards" array for card_group. Processing items.', 'notice'));
          $this->createCardGroupItems($paragraph, $component_data['cards'], $owner_id);
        }
        // Check for a common misnaming 'card' instead of 'cards'.
        elseif (isset($component_data['card']) && is_array($component_data['card'])) {
          $this->logger->warning($this->outputFormatter->formatLogMessage('Found "card" (singular) array for card_group. Processing as if "cards". Consider renaming to "cards" in AI output.', 'warning'));
          $this->createCardGroupItems($paragraph, $component_data['card'], $owner_id);
        }
        else {
          $this->logger->notice($this->outputFormatter->formatLogMessage('No "cards" array found or it is not an array for card_group @id.', 'notice', ['@id' => $paragraph->id()]));
        }
        break;

      case 'feature_list': // Assuming a paragraph type 'feature_list'.
        if (isset($component_data['features']) && is_array($component_data['features'])) {
          $this->logger->notice($this->outputFormatter->formatLogMessage('Found "features" array for feature_list. Processing items.', 'notice'));
          $this->createFeatureItems($paragraph, $component_data['features'], $owner_id, 'field_feature_items'); // Example field name.
        }
        else {
          $this->logger->notice($this->outputFormatter->formatLogMessage('No "features" array found for feature_list @id.', 'notice', ['@id' => $paragraph->id()]));
        }
        break;

      // Add other component types that have nested structures.
      // Example: Accordion with Accordion Items.
      case 'accordion':
        if (isset($component_data['items']) && is_array($component_data['items'])) {
          $this->logger->notice($this->outputFormatter->formatLogMessage('Found "items" array for accordion. Processing accordion items.', 'notice'));
          // Assuming 'accordion_item' is the paragraph type for individual items
          // and 'field_accordion_items' is the field on the 'accordion' paragraph.
          $this->createGenericChildItems(
            $paragraph,
            $component_data['items'],
            $owner_id,
            'field_accordion_items',
            'accordion_item'
          );
        }
        else {
          $this->logger->notice($this->outputFormatter->formatLogMessage('No "items" array found for accordion @id.', 'notice', ['@id' => $paragraph->id()]));
        }
        break;

      default:
        // No specific child entity handling for this paragraph type.
        $this->logger->notice($this->outputFormatter->formatLogMessage('No specific child entity creation logic for paragraph type: @type', 'notice', ['@type' => $paragraph_type]));
        break;
    }
  }

  /**
   * Creates and attaches card items to a card group paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent card_group paragraph.
   * @param array $cards_data
   *   Array of data for individual card items.
   * @param int $owner_id
   *   The user ID for ownership.
   */
  protected function createCardGroupItems(Paragraph $parent_paragraph, array $cards_data, int $owner_id): void {
    if (!$parent_paragraph->hasField('field_card')) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Parent paragraph (card_group) is missing field_card.', 'error'));
      return;
    }

    $card_paragraph_ids = [];
    $this->logger->notice($this->outputFormatter->formatLogMessage('Found @count cards in cards_data array.', 'notice', ['@count' => count($cards_data)]));

    foreach ($cards_data as $card_data) {
      if (!is_array($card_data)) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Skipping invalid card data (not an array): @data', 'warning', ['@data' => json_encode($card_data)]));
        continue;
      }
      // Default type to 'card' if not specified, as these are items for a card_group.
      if (empty($card_data['type'])) {
        $this->logger->notice($this->outputFormatter->formatLogMessage('Card data missing type, defaulting to "card".', 'notice'));
        $card_data['type'] = 'card';
      }
      elseif ($card_data['type'] !== 'card') {
        // If a type is provided but it's not 'card', log a warning but still attempt to create.
        // This could be a stats_card or other card-like component.
        $this->logger->warning($this->outputFormatter->formatLogMessage('Card item type is "@type", expected "card". Proceeding with creation.', 'warning', ['@type' => $card_data['type']]));
      }

      // Mark this as a child component.
      $card_data['drupalx_ai_is_child_component'] = TRUE;

      $this->logger->notice($this->outputFormatter->formatLogMessage('Attempting to create card paragraph with data: @data', 'notice', ['@data' => json_encode($card_data)]));
      $card_paragraph_id = $this->createNestedParagraph($card_data, $owner_id);

      if ($card_paragraph_id) {
        $card_paragraph_ids[] = [
          'target_id' => $card_paragraph_id,
          'target_revision_id' => $card_paragraph_id,
        ];
        // Mark the created card paragraph so it's not added to the top-level field_content.
        $card_p = Paragraph::load($card_paragraph_id);
        if ($card_p) {
            $card_p->setInternalBypassMainCollection = TRUE;
            $this->logger->notice($this->outputFormatter->formatLogMessage('Created card paragraph @id as a child-only component.', 'notice', ['@id' => $card_paragraph_id]));
        }
      }
      else {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Failed to create card paragraph for card_group from data: @data', 'warning', ['@data' => json_encode($card_data)]));
      }
    }

    if (!empty($card_paragraph_ids)) {
      $parent_paragraph->set('field_card', $card_paragraph_ids);
      // No need to save the parent paragraph here, it will be saved after all fields are set.
      $this->logger->notice($this->outputFormatter->formatLogMessage('Attached @count card(s) to card_group @id.', 'notice', [
        '@count' => count($card_paragraph_ids),
        '@id' => $parent_paragraph->id(),
      ]));
    }
    else {
      $this->logger->notice($this->outputFormatter->formatLogMessage('No card paragraphs were created or attached to card_group @id.', 'notice', ['@id' => $parent_paragraph->id()]));
    }
  }

  /**
   * Creates and attaches feature items to a feature list paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent feature_list paragraph.
   * @param array $features_data
   *   Array of data for individual feature items.
   * @param int $owner_id
   *   The user ID for ownership.
   * @param string $field_name
   *   The field name on the parent paragraph that stores feature items.
   *   Defaults to 'field_feature_items'.
   * @param string $item_paragraph_type
   *   The paragraph bundle type for individual feature items.
   *   Defaults to 'feature_item'.
   */
  protected function createFeatureItems(
    Paragraph $parent_paragraph,
    array $features_data,
    int $owner_id,
    string $field_name = 'field_feature_items',
    string $item_paragraph_type = 'feature_item'
  ): void {
    if (!$parent_paragraph->hasField($field_name)) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Parent paragraph (@type) is missing field @field_name.', 'error', [
        '@type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
      ]));
      return;
    }

    $item_paragraph_ids = [];
    $this->logger->notice($this->outputFormatter->formatLogMessage('Found @count items in features_data array for @field_name.', 'notice', [
      '@count' => count($features_data),
      '@field_name' => $field_name,
    ]));

    foreach ($features_data as $item_data) {
      if (!is_array($item_data)) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Skipping invalid item data (not an array): @data', 'warning', ['@data' => json_encode($item_data)]));
        continue;
      }
      if (empty($item_data['type'])) {
        $this->logger->notice($this->outputFormatter->formatLogMessage('Item data missing type, defaulting to "@default_type".', 'notice', ['@default_type' => $item_paragraph_type]));
        $item_data['type'] = $item_paragraph_type;
      }
      elseif ($item_data['type'] !== $item_paragraph_type) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Item type is "@type", expected "@expected_type". Proceeding with creation.', 'warning', [
          '@type' => $item_data['type'],
          '@expected_type' => $item_paragraph_type,
        ]));
      }

      $item_data['drupalx_ai_is_child_component'] = TRUE;
      $this->logger->notice($this->outputFormatter->formatLogMessage('Attempting to create @item_type paragraph with data: @data', 'notice', [
        '@item_type' => $item_paragraph_type,
        '@data' => json_encode($item_data),
      ]));
      $item_paragraph_id = $this->createNestedParagraph($item_data, $owner_id);

      if ($item_paragraph_id) {
        $item_paragraph_ids[] = [
          'target_id' => $item_paragraph_id,
          'target_revision_id' => $item_paragraph_id,
        ];
        $item_p = Paragraph::load($item_paragraph_id);
        if ($item_p) {
          $item_p->setInternalBypassMainCollection = TRUE;
          $this->logger->notice($this->outputFormatter->formatLogMessage('Created @item_type paragraph @id as a child-only component for @field_name.', 'notice', [
            '@item_type' => $item_paragraph_type,
            '@id' => $item_paragraph_id,
            '@field_name' => $field_name,
          ]));
        }
      }
      else {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Failed to create @item_type paragraph for @field_name from data: @data', 'warning', [
          '@item_type' => $item_paragraph_type,
          '@field_name' => $field_name,
          '@data' => json_encode($item_data),
        ]));
      }
    }

    if (!empty($item_paragraph_ids)) {
      $parent_paragraph->set($field_name, $item_paragraph_ids);
      $this->logger->notice($this->outputFormatter->formatLogMessage('Attached @count @item_type(s) to @parent_type @field_name @id.', 'notice', [
        '@count' => count($item_paragraph_ids),
        '@item_type' => $item_paragraph_type,
        '@parent_type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
        '@id' => $parent_paragraph->id(),
      ]));
    }
    else {
      $this->logger->notice($this->outputFormatter->formatLogMessage('No @item_type paragraphs were created or attached to @parent_type @field_name @id.', 'notice', [
        '@item_type' => $item_paragraph_type,
        '@parent_type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
        '@id' => $parent_paragraph->id(),
      ]));
    }
  }

  /**
   * Creates and attaches generic child items to a parent paragraph.
   *
   * This is a more generic version of createCardGroupItems or createFeatureItems.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent paragraph.
   * @param array $items_data
   *   Array of data for individual child items.
   * @param int $owner_id
   *   The user ID for ownership.
   * @param string $field_name
   *   The field name on the parent paragraph that stores child items.
   * @param string $item_paragraph_type
   *   The paragraph bundle type for individual child items.
   */
  protected function createGenericChildItems(
    Paragraph $parent_paragraph,
    array $items_data,
    int $owner_id,
    string $field_name,
    string $item_paragraph_type
  ): void {
    if (!$parent_paragraph->hasField($field_name)) {
      $this->logger->error($this->outputFormatter->formatLogMessage('Parent paragraph (@type) is missing field @field_name for generic child items.', 'error', [
        '@type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
      ]));
      return;
    }

    $item_paragraph_ids = [];
    $this->logger->notice($this->outputFormatter->formatLogMessage('Found @count items in items_data array for @field_name (expecting type @item_type).', 'notice', [
      '@count' => count($items_data),
      '@field_name' => $field_name,
      '@item_type' => $item_paragraph_type,
    ]));

    foreach ($items_data as $item_data) {
      if (!is_array($item_data)) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Skipping invalid item data (not an array) for @field_name: @data', 'warning', [
          '@field_name' => $field_name,
          '@data' => json_encode($item_data),
        ]));
        continue;
      }

      // Default type if not specified.
      if (empty($item_data['type'])) {
        $this->logger->notice($this->outputFormatter->formatLogMessage('Item data missing type for @field_name, defaulting to "@default_type".', 'notice', [
          '@field_name' => $field_name,
          '@default_type' => $item_paragraph_type,
        ]));
        $item_data['type'] = $item_paragraph_type;
      }
      // Log if type is different from expected, but still attempt creation.
      elseif ($item_data['type'] !== $item_paragraph_type) {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Item type is "@type" for @field_name, expected "@expected_type". Proceeding.', 'warning', [
          '@type' => $item_data['type'],
          '@field_name' => $field_name,
          '@expected_type' => $item_paragraph_type,
        ]));
      }

      $item_data['drupalx_ai_is_child_component'] = TRUE;
      $this->logger->notice($this->outputFormatter->formatLogMessage('Attempting to create @item_type paragraph for @field_name with data: @data', 'notice', [
        '@item_type' => $item_paragraph_type,
        '@field_name' => $field_name,
        '@data' => json_encode($item_data),
      ]));
      $item_paragraph_id = $this->createNestedParagraph($item_data, $owner_id);

      if ($item_paragraph_id) {
        $item_paragraph_ids[] = [
          'target_id' => $item_paragraph_id,
          'target_revision_id' => $item_paragraph_id,
        ];
        $item_p = Paragraph::load($item_paragraph_id);
        if ($item_p) {
          $item_p->setInternalBypassMainCollection = TRUE;
          $this->logger->notice($this->outputFormatter->formatLogMessage('Created @item_type paragraph @id as child-only for @field_name.', 'notice', [
            '@item_type' => $item_paragraph_type,
            '@id' => $item_paragraph_id,
            '@field_name' => $field_name,
          ]));
        }
      }
      else {
        $this->logger->warning($this->outputFormatter->formatLogMessage('Failed to create @item_type paragraph for @field_name from data: @data', 'warning', [
          '@item_type' => $item_paragraph_type,
          '@field_name' => $field_name,
          '@data' => json_encode($item_data),
        ]));
      }
    }

    if (!empty($item_paragraph_ids)) {
      $parent_paragraph->set($field_name, $item_paragraph_ids);
      $this->logger->notice($this->outputFormatter->formatLogMessage('Attached @count @item_type(s) to @parent_type @field_name @id.', 'notice', [
        '@count' => count($item_paragraph_ids),
        '@item_type' => $item_paragraph_type,
        '@parent_type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
        '@id' => $parent_paragraph->id(),
      ]));
    }
    else {
      $this->logger->notice($this->outputFormatter->formatLogMessage('No @item_type paragraphs were created or attached to @parent_type @field_name @id.', 'notice', [
        '@item_type' => $item_paragraph_type,
        '@parent_type' => $parent_paragraph->bundle(),
        '@field_name' => $field_name,
        '@id' => $parent_paragraph->id(),
      ]));
    }
  }

  /**
   * Normalizes a field name to the snake_case format expected by Drupal.
   *
   * It handles camelCase, PascalCase, and ensures 'field_' prefix if not present
   * and not a Drupal base field.
   *
   * @param string $key
   *   The raw field name key.
   *
   * @return string
   *   The normalized field name.
   */
  protected function normalizeFieldName(string $key): string {
    // List of known base fields that should not be prefixed with 'field_'.
    $base_fields = [
      'id', 'uuid', 'vid', 'langcode', 'type', 'status', 'uid', 'title',
      'created', 'changed', 'promote', 'sticky', 'default_langcode',
      'revision_translation_affected', 'path', 'moderation_state',
      'field_tags',
      'body', 'summary',
    ];

    // Convert camelCase or PascalCase to snake_case.
    $snake_case_key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

    // Remove any character that is not a letter, number, or underscore.
    $snake_case_key = preg_replace('/[^a-z0-9_]/', '', $snake_case_key);

    // Add 'field_' prefix if it's not a base field and doesn't already have it.
    if (!in_array($snake_case_key, $base_fields) && strpos($snake_case_key, 'field_') !== 0) {
      $potential_field_name = 'field_' . $snake_case_key;
      $snake_case_key = $potential_field_name;
    }

    if ($key !== $snake_case_key) {
      $this->logger->notice($this->outputFormatter->formatLogMessage('Field name normalization: @original -> @normalized', 'notice', [
        '@original' => $key,
        '@normalized' => $snake_case_key,
      ]));
    }

    return $snake_case_key;
  }

}
