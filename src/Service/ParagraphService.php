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
      // Attach paragraph references to the node.
      $paragraph_references = [];
      foreach ($paragraph_ids as $pid) {
        $paragraph_references[] = [
          'target_id' => $pid,
          'target_revision_id' => $pid,
        ];
      }

      $node->set('field_content', $paragraph_references);
      $node->save();

      $this->logger->notice('Added @count paragraphs to node @nid.', [
        '@count' => count($paragraph_ids),
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
    // Validate component data.
    if (empty($component_data['type']) || !is_string($component_data['type'])) {
      $this->logger->error('Component data missing required type or type is not a string.', [
        'data' => json_encode($component_data),
      ]);
      return NULL;
    }

    // Map the AI-generated component type to a paragraph bundle.
    // Include both camelCase and snake_case versions for compatibility.
    $type_mapping = [
      // Snake case keys
      'hero' => 'hero',
      'cta' => 'cta',
      'text_block' => 'text_block',
      'quote' => 'quote',
      'image_block' => 'image_block',
      'video_block' => 'video_block',
      'card_group' => 'card_group',
      'features' => 'features',
      'gallery' => 'gallery',
      'rich_text' => 'rich_text',
      'testimonial' => 'testimonial',
      'banner' => 'banner',
      'faq' => 'faq',
      'accordion' => 'accordion',
      'stats' => 'stats',
      'content_with_image' => 'content_with_image',
      'form_block' => 'form_block',
      'map' => 'map',
      'social_links' => 'social_links',
      'slider' => 'slider',
      'team' => 'team',
      'timeline' => 'timeline',
      'tabs' => 'tabs',
      'pricing' => 'pricing',
      'callout' => 'callout',
      'header' => 'header',
      'footer' => 'footer',
      'menu' => 'menu',
      'section' => 'section',
      'logo' => 'logo',
      'logo_collection' => 'logo_collection',
      'newsletter' => 'newsletter',
      
      // Camel case keys (for backward compatibility)
      'textBlock' => 'text_block',
      'imageBlock' => 'image_block',
      'videoBlock' => 'video_block',
      'cardGroup' => 'card_group',
      'richText' => 'rich_text',
      'contentWithImage' => 'content_with_image',
      'formBlock' => 'form_block',
      'socialLinks' => 'social_links',
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
    if (empty($component_data['cards']) || !is_array($component_data['cards'])) {
      return;
    }

    $card_items = [];
    foreach ($component_data['cards'] as $card_data) {
      // Create a card paragraph for each item.
      try {
        $card = Paragraph::create([
          'type' => 'card',
          'uid' => $owner_id,
        ]);

        // Set fields on the card.
        if (!empty($card_data['title'])) {
          $card->set('field_title', $card_data['title']);
        }

        if (!empty($card_data['content'])) {
          $card->set('field_content', $card_data['content']);
        }

        if (!empty($card_data['image'])) {
          $media_id = $this->mediaService->createPlaceholderMediaItem('image', $card_data['title'] ?? 'Card image', $owner_id);
          if ($media_id) {
            $card->set('field_image', ['target_id' => $media_id]);
          }
        }

        if (!empty($card_data['link'])) {
          $link_data = [
            'uri' => $card_data['link']['url'] ?? $card_data['link'],
            'title' => $card_data['link']['title'] ?? 'Read more',
            'options' => [],
          ];
          $card->set('field_link', $link_data);
        }

        $card->save();
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
      $paragraph->set('field_cards', $card_items);
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
