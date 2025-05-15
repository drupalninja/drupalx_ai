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
use Drupal\drupalx_ai\Service\ValidationService;

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
   * The validation service.
   *
   * @var \Drupal\drupalx_ai\Service\ValidationService
   */
  protected ValidationService $validationService;

  /**
   * Cached child processing configuration.
   *
   * @var array|null
   */
  protected static ?array $childProcessingConfigCache = NULL;

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
   * @param \Drupal\drupalx_ai\Service\ValidationService $validation_service
   *   The validation service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    MediaService $media_service,
    TaxonomyService $taxonomy_service,
    ValidationService $validation_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->mediaService = $media_service;
    $this->taxonomyService = $taxonomy_service;
    $this->validationService = $validation_service;
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
      $valid_top_level_types = $this->validationService->getTopLevelSampleTypes();
      if (empty($valid_top_level_types)) {
        $this->logger->warning('No valid top-level sample types found. Paragraph attachment to node might be overly restrictive or permissive.');
        $valid_top_level_types = [];
      }

      foreach ($paragraph_ids as $pid) {
        $paragraph = $paragraph_storage->load($pid);
        if (!$paragraph) {
          continue;
        }

        $bundle_type = $paragraph->bundle();

        if (isset($paragraph->setInternalBypassMainCollection) && $paragraph->setInternalBypassMainCollection === TRUE) {
          continue;
        }

        if (!in_array($bundle_type, $valid_top_level_types, TRUE)) {
          $this->logger->notice(
            'Paragraph type @type (@id) is not a valid top-level type based on samples and was skipped for node attachment.',
            [
              '@type' => $bundle_type,
              '@id' => $pid,
              '@nid' => $nid,
            ]
          );
          continue;
        }

        $paragraph_references[] = [
          'target_id' => $pid,
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
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
    $component_data['type'] = $normalized_type;
    $paragraph_bundle = $normalized_type;

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

    $child_processing_config = $this->getChildProcessingConfiguration();
    $child_entity_fields_map = [];
    if (isset($child_processing_config[$paragraph_type])) {
      foreach ($child_processing_config[$paragraph_type] as $config) {
        // $config[0] is the drupal_target_field_name.
        if (!in_array($config[0], $child_entity_fields_map[$paragraph_type] ?? [], TRUE)) {
          $child_entity_fields_map[$paragraph_type][] = $config[0];
        }
      }
    }

    foreach ($component_data as $key => $value) {
      if ($key === 'type') {
        continue;
      }

      $field_name = $this->normalizeFieldName($key);

      // Skip if this field is handled by createChildEntities for the current paragraph type.
      if (isset($child_entity_fields_map[$paragraph_type]) && in_array($field_name, $child_entity_fields_map[$paragraph_type], TRUE)) {
        continue;
      }

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
              $media_ids = [];
              // Check if $value is an array of arrays (list of media items)
              // or a single media item array (or even just a URL string).
              if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                // Multiple media items.
                foreach ($value as $media_item_data) {
                  $media_id = $this->mediaService->ensureMediaEntityExists($media_item_data, $owner_id, 'image');
                  if ($media_id) {
                    $media_ids[] = $media_id;
                  }
                }
              }
              elseif (!empty($value)) {
                // Single media item (or URL string).
                $media_id = $this->mediaService->ensureMediaEntityExists($value, $owner_id, 'image');
                if ($media_id) {
                  $media_ids[] = $media_id;
                }
              }

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
              // This generic handling is for paragraph fields NOT managed by createChildEntities.
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
   * Creates child entities for complex paragraphs.
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

    $child_processing_map = $this->getChildProcessingConfiguration();

    if (isset($child_processing_map[$paragraph_type])) {
      $current_type_map = $child_processing_map[$paragraph_type];

      foreach ($current_type_map as $ai_data_key => $config) {
        [$drupal_target_field, $expected_child_bundle] = $config;

        if (isset($component_data[$ai_data_key]) && is_array($component_data[$ai_data_key])) {
          $this->createGenericChildItems(
            $paragraph,
            $component_data[$ai_data_key],
            $owner_id,
            $drupal_target_field,
            $expected_child_bundle
          );
          break;
        }
      }
    }
    // No specific child entity handling configured for other paragraph types.
  }

  /**
   * Creates generic child items for a parent paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $parent_paragraph
   *   The parent paragraph.
   * @param array $child_items_data_list
   *   Array of child item data objects.
   * @param int $owner_id
   *   The owner ID.
   * @param string $drupal_target_field_name
   *   The machine name of the field on the parent_paragraph that will store
   *   these children.
   * @param string $expected_child_bundle_type
   *   The expected paragraph bundle type for the children.
   */
  protected function createGenericChildItems(
      Paragraph $parent_paragraph,
      array $child_items_data_list,
      int $owner_id,
      string $drupal_target_field_name,
      string $expected_child_bundle_type
  ): void {
    if (!$parent_paragraph->hasField($drupal_target_field_name)) {
      $this->logger->error(
        'Parent paragraph (@type) is missing field @field_name for generic child items.',
        [
          '@type' => $parent_paragraph->bundle(),
          '@field_name' => $drupal_target_field_name,
        ]
      );
      return;
    }

    $item_paragraph_references = [];
    foreach ($child_items_data_list as $item_data) {
      if (!is_array($item_data)) {
        $this->logger->warning(
          'Skipping non-array item in child_items_data_list for field @field_name on parent @type.',
          [
            '@field_name' => $drupal_target_field_name,
            '@type' => $parent_paragraph->bundle(),
            'item_data' => json_encode($item_data),
          ]
        );
        continue;
      }

      // Determine the type for the child paragraph.
      $child_paragraph_type = $item_data['type'] ?? $expected_child_bundle_type;
      if (empty($item_data['type'])) {
        $this->logger->notice(
          'Child item data for field @field on parent @parent_type did not specify a type. Defaulting to @default_type.',
          [
            '@field' => $drupal_target_field_name,
            '@parent_type' => $parent_paragraph->bundle(),
            '@default_type' => $expected_child_bundle_type,
            'item_data' => json_encode($item_data),
          ]
        );
      }
      // Ensure the 'type' in item_data is what we're going to create.
      $item_data['type'] = $child_paragraph_type;

      // The drupalx_ai_is_child_component flag is no longer needed here,
      // as createGenericChildItems inherently handles child logic.
      unset($item_data['drupalx_ai_is_child_component']);

      $child_paragraph_id = $this->createNestedParagraph($item_data, $owner_id);

      if ($child_paragraph_id) {
        $child_p = Paragraph::load($child_paragraph_id);
        if ($child_p) {
          $child_p->setInternalBypassMainCollection = TRUE;
          // Child paragraph is already saved by createNestedParagraph.
          // We don't need to save it again here.
          $item_paragraph_references[] = [
            'target_id' => $child_p->id(),
            'target_revision_id' => $child_p->getRevisionId(),
          ];
        }
      }
    }

    if (!empty($item_paragraph_references)) {
      $parent_paragraph->set($drupal_target_field_name, $item_paragraph_references);
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

  /**
   * Returns the configuration map for processing child entities.
   *
   * This map defines, for each parent paragraph type, which AI data keys
   * correspond to child lists, their target Drupal fields, and expected child
   * bundles.
   *
   * @return array
   *   The child processing configuration map.
   *   Format: 'parent_bundle' => [
   *     'ai_data_key' => ['drupal_field_name', 'expected_child_bundle_type'],
   *     // ... other potential AI keys for the same Drupal field ...
   *   ]
   */
  protected function getChildProcessingConfiguration(): array {
    if (self::$childProcessingConfigCache !== NULL) {
      return self::$childProcessingConfigCache;
    }

    $inferred_map = [];
    $samples_result = $this->validationService->loadSampleComponents();

    if ($samples_result['status'] === 'success' && !empty($samples_result['data'])) {
      $all_sample_components = $samples_result['data'];

      foreach ($all_sample_components as $sample_component) {
        if (!is_array($sample_component) || !isset($sample_component['type'])) {
          continue;
        }
        $parent_bundle_type = $sample_component['type'];

        foreach ($sample_component as $field_name => $field_value) {
          if ($field_name === 'type') {
            continue;
          }

          // Check if the field_value represents a list of potential child
          // paragraphs.
          if (is_array($field_value) && !empty($field_value) && isset($field_value[0]) && is_array($field_value[0])) {
            $first_child_sample = $field_value[0];
            if (isset($first_child_sample['type']) && is_string($first_child_sample['type'])) {
              $expected_child_bundle_type = $first_child_sample['type'];
              // Use the field name from the sample as the AI data key and
              // Drupal field name. Normalization is applied when setting
              // fields, but samples should ideally use snake_case.
              $drupal_field_name = $this->normalizeFieldName($field_name);
              // Or $drupal_field_name if we enforce strict normalization for AI
              // keys.
              $ai_data_key = $field_name;

              if (!isset($inferred_map[$parent_bundle_type])) {
                $inferred_map[$parent_bundle_type] = [];
              }
              $inferred_map[$parent_bundle_type][$ai_data_key] = [
                $drupal_field_name,
                $expected_child_bundle_type,
              ];
            }
          }
        }
      }
    }
    else {
      $this->logger->warning('Could not load sample components to infer child processing configuration. Falling back to empty map.');
    }

    self::$childProcessingConfigCache = $inferred_map;
    return self::$childProcessingConfigCache;
  }

}
