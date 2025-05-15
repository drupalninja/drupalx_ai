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
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    MediaService $media_service,
    TaxonomyService $taxonomy_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->mediaService = $media_service;
    $this->taxonomyService = $taxonomy_service;
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
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if (!$node) {
      $this->logger->error('Node @nid not found for saving entities.', ['@nid' => $nid]);
      return [];
    }

    if (!$node->hasField('field_content') ||
        $node->field_content->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
      $this->logger->error('Node does not have field_content for paragraphs.');
      return [];
    }

    $paragraph_ids = [];
    $owner_id = $node->getOwnerId();

    foreach ($components_data as $component_data) {
      $paragraph_id = $this->createNestedParagraph($component_data, $owner_id);
      if ($paragraph_id) {
        $paragraph_ids[] = $paragraph_id;
      }
    }

    if (!empty($paragraph_ids)) {
      if ($node->hasField('field_content')) {
        $node->set('field_content', []);
        $node->save();
      }

      $paragraph_references = [];
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $filtered_count = 0;

      foreach ($paragraph_ids as $pid) {
        $paragraph = $paragraph_storage->load($pid);
        if (!$paragraph) {
          continue;
        }

        $bundle_type = $paragraph->bundle();
        $child_only_types = [
          'card',
          'accordion_item',
          'carousel_item',
          'bullet',
          'feature_item',
          'pricing_card',
        ];

        if (isset($paragraph->setInternalBypassMainCollection) && $paragraph->setInternalBypassMainCollection === TRUE) {
          continue;
        }

        if (in_array($bundle_type, $child_only_types, TRUE)) {
          $this->logger->error(
            'Prevented attaching child-only paragraph @id (@type) to node @nid.',
            [
              '@id' => $pid,
              '@type' => $bundle_type,
              '@nid' => $nid,
            ]
          );
          continue;
        }

        if (strpos(strtolower($bundle_type), 'card') !== FALSE && $bundle_type !== 'card_group') {
          $this->logger->error(
            'Prevented attaching card-like paragraph @id (@type) to node @nid.',
            [
              '@id' => $pid,
              '@type' => $bundle_type,
              '@nid' => $nid,
            ]
          );
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
    $paragraph_type = $component_data['type'] ?? NULL;
    if (!$paragraph_type) {
      $this->logger->error('Paragraph type missing: @data', [
        '@data' => json_encode($component_data),
      ]);
      return NULL;
    }

    $normalized_type = strtolower(str_replace(' ', '_', $paragraph_type));
    $normalized_type = preg_replace('/[^a-z0-9_]/', '', $normalized_type);

    if ($normalized_type === 'card') {
      $this->logger->error('Attempted to create a standalone card paragraph.');
      return NULL;
    }

    $child_only_types = [
      'card', 'accordion_item', 'carousel_item', 'bullet', 'feature_item', 'pricing_card',
    ];
    if (in_array($normalized_type, $child_only_types, TRUE)) {
      return NULL;
    }

    $component_data['type'] = $normalized_type;

    $allowed_top_level_types = [
      'text_block', 'image_component', 'card_group', 'quote_block', 'feature_list',
      'accordion', 'video_component', 'faq_group', 'cta_banner', 'testimonial_slider',
    ];

    if (!in_array($normalized_type, $allowed_top_level_types, TRUE)) {
      return NULL;
    }

    if ($normalized_type === 'card_group' && (!isset($component_data['field_card']) || !is_array($component_data['field_card']))) {
      $component_data['field_card'] = [];
    }

    $type_mapping = [
      'text_block' => 'text_block',
      'image_component' => 'image_component',
      'card_group' => 'card_group',
    ];
    // Fallback to normalized_type if not in mapping.
    $paragraph_bundle = $type_mapping[$normalized_type] ?? $normalized_type;

    $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    if (!isset($paragraph_bundles[$paragraph_bundle])) {
      $this->logger->error('Paragraph bundle @bundle does not exist.', [
        '@bundle' => $paragraph_bundle,
      ]);
      return NULL;
    }

    try {
      $paragraph = Paragraph::create([
        'type' => $paragraph_bundle,
        'uid' => $owner_id,
      ]);

      $this->setParagraphFields($paragraph, $component_data, $owner_id);
      $this->createChildEntities($paragraph, $component_data, $owner_id);

      $paragraph->save();
      $paragraph_id = $paragraph->id();

      return $paragraph_id;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error creating paragraph of type @type: @message',
        [
          '@type' => $paragraph_bundle,
          '@message' => $e->getMessage(),
          '@data' => json_encode($component_data),
        ]
      );
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
    $paragraph_type = $paragraph->bundle();
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $paragraph_type);

    foreach ($component_data as $key => $value) {
      if ($key === 'type') {
        continue;
      }

      $field_name = $this->normalizeFieldName($key);

      if (!$paragraph->hasField($field_name)) {
        continue;
      }

      try {
        $field_definition = $field_definitions[$field_name] ?? NULL;
        if (!$field_definition) {
          continue;
        }

        $field_type = $field_definition->getType();

        switch ($field_type) {
          case 'string':
          case 'string_long':
          case 'text':
          case 'text_long':
          case 'text_with_summary':
            $paragraph->set($field_name, $value);
            break;

          case 'link':
            if (is_array($value) && isset($value['url'])) {
              $link_data = [
                'uri' => $value['url'],
                'title' => $value['title'] ?? '',
                'options' => [],
              ];
              $paragraph->set($field_name, $link_data);
            }
            elseif (is_string($value)) {
              $paragraph->set($field_name, [
                'uri' => $value,
                'title' => '',
                'options' => [],
              ]);
            }
            break;

          case 'entity_reference':
            $target_type = $field_definition->getSetting('target_type');
            if ($target_type === 'media' && !empty($value)) {
              $media_ids = $this->mediaService->ensureMediaEntitiesExist($value, $owner_id, $paragraph_type, $field_name);
              if (!empty($media_ids)) {
                $paragraph->set($field_name, $media_ids);
              }
            }
            elseif ($target_type === 'taxonomy_term' && !empty($value)) {
              $term_ids = $this->taxonomyService->ensureTermsExist($value, $field_definition->getSetting('handler_settings')['target_bundles'] ?? NULL);
              if (!empty($term_ids)) {
                $paragraph->set($field_name, $term_ids);
              }
            }
            elseif ($target_type === 'paragraph' && is_array($value)) {
              $child_paragraph_ids = [];
              foreach ($value as $child_component_data) {
                if (!is_array($child_component_data)) {
                  $this->logger->error(
                    'Invalid child component data for field @field on @parent_type: @data',
                    [
                      '@field' => $field_name,
                      '@parent_type' => $paragraph_type,
                      '@data' => json_encode($child_component_data),
                    ]
                  );
                  continue;
                }
                $child_paragraph_id = $this->createNestedParagraph($child_component_data, $owner_id);
                if ($child_paragraph_id) {
                  $child_paragraph_ids[] = $child_paragraph_id;
                }
              }
              if (!empty($child_paragraph_ids)) {
                $paragraph->set($field_name, $child_paragraph_ids);
              }
            }
            break;

          default:
            // For any other field types not explicitly handled, do nothing for now.
            break;
        }
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Error setting field @field_name for paragraph type @type: @message. Data: @data',
          [
            '@field_name' => $field_name,
            '@type' => $paragraph_type,
            '@message' => $e->getMessage(),
            '@data' => json_encode($value),
          ]
        );
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

    switch ($paragraph_type) {
      case 'card_group':
        // 'cards' is the expected key for an array of card data.
        if (isset($component_data['cards']) && is_array($component_data['cards'])) {
          $this->createCardGroupItems($paragraph, $component_data['cards'], $owner_id);
        }
        // AI might mistakenly use 'card' (singular) for the array.
        elseif (isset($component_data['card']) && is_array($component_data['card'])) {
          $this->createCardGroupItems($paragraph, $component_data['card'], $owner_id);
        }
        break;

      case 'feature_list':
        // Assuming 'feature_list' paragraph type and 'features' key for items.
        if (isset($component_data['features']) && is_array($component_data['features'])) {
          // 'field_feature_items' is the target field on the parent.
          // 'feature_item' is the paragraph type of each child item.
          $this->createFeatureItems($paragraph, $component_data['features'], $owner_id, 'field_feature_items', 'feature_item');
        }
        break;

      case 'accordion':
        // Assuming 'accordion' paragraph type and 'items' key for accordion items.
        if (isset($component_data['items']) && is_array($component_data['items'])) {
          // 'field_accordion_items' is the target field on the parent.
          // 'accordion_item' is the paragraph type of each child accordion panel.
          $this->createGenericChildItems($paragraph, $component_data['items'], $owner_id, 'field_accordion_items', 'accordion_item');
        }
        break;

      default:
        // No specific child entity handling configured for other paragraph types.
        break;
    }
  }

  /**
   * Creates card items for a card_group paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent card_group paragraph.
   * @param array $cards_data
   *   Array of card data.
   * @param int $owner_id
   *   The owner ID.
   */
  protected function createCardGroupItems(Paragraph $parent_paragraph, array $cards_data, int $owner_id): void {
    if (!$parent_paragraph->hasField('field_card')) {
      $this->logger->error('Parent card_group missing field_card.');
      return;
    }

    $card_paragraph_ids = [];
    foreach ($cards_data as $card_data) {
      if (!is_array($card_data)) {
        continue;
      }
      if (empty($card_data['type'])) {
        $card_data['type'] = 'card';
      }
      elseif ($card_data['type'] !== 'card') {
        // Type mismatch, but proceed.
      }

      $card_data['drupalx_ai_is_child_component'] = TRUE;
      $card_paragraph_id = $this->createNestedParagraph($card_data, $owner_id);

      if ($card_paragraph_id) {
        $card_paragraph_ids[] = $card_paragraph_id;
        // Load the just-created card paragraph to set the internal flag.
        $card_p = Paragraph::load($card_paragraph_id);
        if ($card_p) {
          // This flag prevents the card from being added to the node's top-level field_content.
          $card_p->setInternalBypassMainCollection = TRUE;
        }
      }
    }

    if (!empty($card_paragraph_ids)) {
      $parent_paragraph->set('field_card', $card_paragraph_ids);
    }
  }

  /**
   * Creates feature items for a feature_list paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent paragraph (e.g., feature_list).
   * @param array $features_data
   *   Array of feature item data.
   * @param int $owner_id
   *   The owner ID.
   * @param string $field_name
   *   The field name on the parent paragraph to store feature items.
   * @param string $item_paragraph_type
   *   The paragraph type for individual feature items.
   */
  protected function createFeatureItems(
      Paragraph $parent_paragraph,
      array $features_data,
      int $owner_id,
      string $field_name = 'field_feature_items',
      string $item_paragraph_type = 'feature_item'
  ): void {
    if (!$parent_paragraph->hasField($field_name)) {
      $this->logger->error(
        'Parent paragraph (@type) missing field @field_name.',
        [
          '@type' => $parent_paragraph->bundle(),
          '@field_name' => $field_name,
        ]
      );
      return;
    }

    $item_paragraph_ids = [];
    foreach ($features_data as $item_data) {
      if (!is_array($item_data)) {
        continue;
      }
      if (empty($item_data['type'])) {
        $item_data['type'] = $item_paragraph_type;
      }
      elseif ($item_data['type'] !== $item_paragraph_type) {
        // Type mismatch, but proceed.
      }

      $item_data['drupalx_ai_is_child_component'] = TRUE;
      $item_paragraph_id = $this->createNestedParagraph($item_data, $owner_id);

      if ($item_paragraph_id) {
        $item_paragraph_ids[] = $item_paragraph_id;
        $item_p = Paragraph::load($item_paragraph_id);
        if ($item_p) {
          $item_p->setInternalBypassMainCollection = TRUE;
        }
      }
    }

    if (!empty($item_paragraph_ids)) {
      $parent_paragraph->set($field_name, $item_paragraph_ids);
    }
  }

  /**
   * Creates generic child items for a parent paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent paragraph.
   * @param array $items_data
   *   Array of child item data.
   * @param int $owner_id
   *   The owner ID.
   * @param string $field_name
   *   The field name on the parent paragraph to store child items.
   * @param string $item_paragraph_type
   *   The paragraph type for individual child items.
   */
  protected function createGenericChildItems(
      Paragraph $parent_paragraph,
      array $items_data,
      int $owner_id,
      string $field_name,
      string $item_paragraph_type
  ): void {
    if (!$parent_paragraph->hasField($field_name)) {
      $this->logger->error(
        'Parent paragraph (@type) is missing field @field_name for generic child items.',
        [
          '@type' => $parent_paragraph->bundle(),
          '@field_name' => $field_name,
        ]
      );
      return;
    }

    $item_paragraph_ids = [];
    foreach ($items_data as $item_data) {
      if (!is_array($item_data)) {
        continue;
      }

      // Default type if not specified for the child item.
      if (empty($item_data['type'])) {
        $item_data['type'] = $item_paragraph_type;
      }
      // Type is different from expected.
      elseif ($item_data['type'] !== $item_paragraph_type) {
        // Type mismatch, but proceed. createNestedParagraph will validate.
      }

      $item_data['drupalx_ai_is_child_component'] = TRUE;
      $item_paragraph_id = $this->createNestedParagraph($item_data, $owner_id);

      if ($item_paragraph_id) {
        $item_paragraph_ids[] = $item_paragraph_id;
        $item_p = Paragraph::load($item_paragraph_id);
        if ($item_p) {
          $item_p->setInternalBypassMainCollection = TRUE;
        }
      }
    }

    if (!empty($item_paragraph_ids)) {
      $parent_paragraph->set($field_name, $item_paragraph_ids);
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
    $base_fields = [
      'id', 'uuid', 'vid', 'langcode', 'type', 'status', 'uid', 'title',
      'created', 'changed', 'promote', 'sticky', 'default_langcode',
      'revision_translation_affected', 'path', 'moderation_state',
      'field_tags',
      'body', 'summary',
    ];

    $snake_case_key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    $snake_case_key = preg_replace('/[^a-z0-9_]/', '', $snake_case_key);

    if (!in_array($snake_case_key, $base_fields) && strpos($snake_case_key, 'field_') !== 0) {
      $potential_field_name = 'field_' . $snake_case_key;
      $snake_case_key = $potential_field_name;
    }

    return $snake_case_key;
  }

}
