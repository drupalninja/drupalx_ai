<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Service for saving entities based on AI data.
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
   * Constructs a new EntitySaveService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The bundle information service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    AccountInterface $current_user,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
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
        $node->field_content->getFieldDefinition()->getType() !== 'entity_reference_revisions' ||
        $node->field_content->getFieldDefinition()->getSetting('target_type') !== 'paragraph') {
      $this->logger->error('Node @nid does not have a compatible field_content (entity_reference_revisions to paragraph type) field.', ['@nid' => $nid]);
      return [];
    }

    $created_paragraph_ids = [];
    $owner_id = ($node instanceof EntityOwnerInterface && $node->getOwnerId()) ? $node->getOwnerId() : $this->currentUser->id();
    if (empty($owner_id)) {
      // Fallback to admin user if no owner can be determined or is anonymous.
      $owner_id = 1;
    }

    foreach ($components_data as $key => $component_data) {
      if (!is_array($component_data) || (empty($component_data['type']) && empty($component_data['id']) && empty($component_data['name']))) {
        $this->logger->warning('Skipping component due to missing type, id, or name. Component data: @data', [
          '@data' => json_encode($component_data),
        ]);
        continue;
      }

      // Try to derive paragraph type from 'type', then 'id', then 'name'.
      $paragraph_bundle_key_name = NULL;
      if (!empty($component_data['type'])) {
        $paragraph_bundle_key_name = $component_data['type'];
      }
      elseif (!empty($component_data['id'])) {
        $this->logger->debug("Component data is missing 'type', falling back to 'id' for paragraph bundle key: @id", ['@id' => $component_data['id']]);
        $paragraph_bundle_key_name = $component_data['id'];
      }
      elseif (!empty($component_data['name'])) {
        // This fallback might be less reliable.
        $this->logger->debug("Component data is missing 'type' and 'id', falling back to 'name' for paragraph bundle key: @name", ['@name' => $component_data['name']]);
        $paragraph_bundle_key_name = $component_data['name'];
      }

      if (!$paragraph_bundle_key_name) {
        // This case should be caught by the initial check, but as a safeguard.
        $this->logger->warning('Skipping component because a bundle key (type, id, or name) could not be determined. Component data: @data', [
          '@data' => json_encode($component_data),
        ]);
        continue;
      }

      // Use the paragraph bundle type directly as found in sample-components.json.
      // Convert spaces to underscores and ensure it's lowercase.
      $paragraph_type = strtolower(str_replace(' ', '_', $paragraph_bundle_key_name));

      $this->logger->info('Attempting to create paragraph of type: @type for component: @key_name', [
        '@type' => $paragraph_type,
        '@key_name' => $paragraph_bundle_key_name,
      ]);

      try {
        $paragraph_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
        if (!isset($paragraph_bundle_info[$paragraph_type])) {
          $log_context = [
            '@bundle' => $paragraph_type,
            '@component_id' => $paragraph_bundle_key_name,
          ];
          $this->logger->error('Paragraph bundle @bundle does not exist. Skipping component: @component_id', $log_context);
          continue;
        }

        $paragraph = Paragraph::create([
          'type' => $paragraph_type,
          'uid' => $owner_id,
          // 1 = published, 0 = unpublished
          'status' => 1,
        ]);

        // Skip 'type' as it's used for paragraph bundle type, not a field.
        foreach ($component_data as $field_name => $field_value) {
          // Skip type and other non-field properties.
          if (in_array($field_name, ['type', 'id', 'name'])) {
            continue;
          }

          if ($paragraph->hasField($field_name)) {
            $field_definition = $paragraph->get($field_name)->getFieldDefinition();
            $field_type = $field_definition->getType();
            $target_entity_type = $field_definition->getSetting('target_type');

            // Handle link fields (uri + title).
            if ($field_type === 'link' && is_array($field_value) && isset($field_value['uri'])) {
              $link_value = [
                'uri' => $field_value['uri'],
                'title' => $field_value['title'] ?? '',
                'options' => $field_value['options'] ?? [],
              ];
              $paragraph->set($field_name, $link_value);
            }
            // Handle media fields.
            elseif ($field_type === 'entity_reference' && $target_entity_type === 'media') {
              if (isset($field_value['media_url'])) {
                // Handle explicit media URL reference.
                $media_id = $this->createOrLoadMediaItem($field_value, $owner_id);
                if ($media_id) {
                  $paragraph->set($field_name, ['target_id' => $media_id]);
                }
              }
              elseif (is_array($field_value) && isset($field_value['type']) && $field_value['type'] === 'image') {
                // Create a placeholder media item for now.
                // Later this would be replaced with actual media from API or user upload.
                $media_id = $this->createPlaceholderMediaItem('image', $field_value['alt'] ?? 'Placeholder image', $owner_id);
                if ($media_id) {
                  $paragraph->set($field_name, ['target_id' => $media_id]);
                }
              }
              else {
                $log_context = [
                  '@field_name' => $field_name,
                  '@value' => json_encode($field_value),
                ];
                $this->logger->warning('Unsupported field value structure for field @field_name on paragraph type @paragraph_type. Value: @value', $log_context + ['@paragraph_type' => $paragraph_type]);
              }
            }
            // Handle nested paragraph fields (like card_group > cards or features).
            elseif ($field_type === 'entity_reference_revisions' && $target_entity_type === 'paragraph' && is_array($field_value)) {
              $nested_paragraphs = [];

              // Handle both indexed arrays and associative arrays.
              if (isset($field_value[0])) {
                // Indexed array of paragraph items.
                foreach ($field_value as $index => $nested_component) {
                  if (!isset($nested_component['type'])) {
                    $this->logger->warning('Nested component missing type field: @data', ['@data' => json_encode($nested_component)]);
                    continue;
                  }

                  $nested_paragraph_id = $this->createNestedParagraph($nested_component, $owner_id);
                  if ($nested_paragraph_id) {
                    $nested_paragraphs[] = [
                      'target_id' => $nested_paragraph_id,
                      'target_revision_id' => $nested_paragraph_id,
                    ];
                  }
                }
              }
              else {
                // Single nested paragraph item.
                $nested_paragraph_id = $this->createNestedParagraph($field_value, $owner_id);
                if ($nested_paragraph_id) {
                  $nested_paragraphs[] = [
                    'target_id' => $nested_paragraph_id,
                    'target_revision_id' => $nested_paragraph_id,
                  ];
                }
              }

              if (!empty($nested_paragraphs)) {
                $paragraph->set($field_name, $nested_paragraphs);
              }
            }
            elseif ($field_type === 'entity_reference' && $target_entity_type === 'taxonomy_term' && is_string($field_value)) {
              $handler_settings = $field_definition->getSetting('handler_settings');
              $target_bundles = $handler_settings['target_bundles'] ?? NULL;
              if (!$target_bundles) {
                $log_context = [
                  '@field_name' => $field_name,
                  '@paragraph_type' => $paragraph_type,
                ];
                $this->logger->warning('Target bundles not defined for taxonomy term field @field_name on paragraph type @paragraph_type.', $log_context);
                continue;
              }
              $term_id = $this->getTermIdByName($field_value, $target_bundles);
              if ($term_id) {
                $paragraph->set($field_name, ['target_id' => $term_id]);
              }
              else {
                $log_context = [
                  '@term_name' => $field_value,
                  '@field_name' => $field_name,
                  '@paragraph_type' => $paragraph_type,
                ];
                $this->logger->warning('Could not find or create taxonomy term "@term_name" for field @field_name on paragraph type @paragraph_type.', $log_context);
              }
            }
            else {
              if (is_array($field_value) && isset($field_value['value'])) {
                $paragraph->set($field_name, $field_value);
              }
              elseif (is_scalar($field_value)) {
                $paragraph->set($field_name, $field_value);
              }
              else {
                $log_context = [
                  '@field_name' => $field_name,
                  '@paragraph_type' => $paragraph_type,
                  '@value' => json_encode($field_value),
                ];
                $this->logger->warning('Unsupported field value structure for field @field_name on paragraph type @paragraph_type. Value: @value', $log_context);
              }
            }
          }
          else {
            $log_context = [
              '@bundle' => $paragraph_type,
              '@field_name' => $field_name,
              '@component_id' => $paragraph_bundle_key_name,
            ];
            $this->logger->warning('Paragraph bundle @bundle does not have field @field_name. Skipping for component: @component_id', $log_context);
          }
        }
        $paragraph->save();
        $node->field_content[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $created_paragraph_ids[] = $paragraph->id();
        $log_context = [
          '@pid' => $paragraph->id(),
          '@type' => $paragraph_type,
          '@nid' => $nid,
        ];
        $this->logger->info('Created paragraph @pid of type @type and added to node @nid.', $log_context);
      }
      catch (\Exception $e) {
        $log_context = [
          '@type' => $paragraph_type,
          '@nid' => $nid,
          '@message' => $e->getMessage(),
          '@data' => json_encode($component_data),
        ];
        $this->logger->error('Failed to create paragraph of type @type for node @nid: @message. Component data: @data', $log_context);
      }
    }

    if (!empty($created_paragraph_ids)) {
      $node->save();
      $log_context = [
        '@count' => count($created_paragraph_ids),
        '@nid' => $nid,
      ];
      $this->logger->info('Successfully saved @count paragraphs to node @nid.', $log_context);
    }

    return $created_paragraph_ids;
  }

  /**
   * Creates or loads a media item.
   *
   * @param array $media_data
   *   Array containing media information.
   *   Expected keys:
   *     'media_url' (string): The URL of the media.
   *     'media_type' (string, optional): 'image' or 'remote_video'. Autodetected if not provided.
   *     'alt' (string, optional): Alt text for images.
   *     'name' (string, optional): Name for the media item.
   * @param int $owner_id
   *   The user ID to set as the owner of the media item.
   *
   * @return int|null
   *   The media ID or NULL on failure.
   */
  protected function createOrLoadMediaItem(array $media_data, int $owner_id): ?int {
    if (empty($media_data['media_url'])) {
      $this->logger->warning('Media URL is missing.');
      return NULL;
    }

    $media_url = $media_data['media_url'];
    if (!filter_var($media_url, FILTER_VALIDATE_URL)) {
      $this->logger->warning('Invalid media URL provided: @url', ['@url' => $media_url]);
      return NULL;
    }

    $media_type = $media_data['media_type'] ?? NULL;
    $file_extension = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    $bundle = NULL;
    $source_field = NULL;

    if ($media_type === 'image' || in_array($file_extension, $image_extensions)) {
      // Default image bundle.
      $bundle = 'image';
      // Default source field for image bundle.
      $source_field = 'field_media_image';
    }
    elseif ($media_type === 'remote_video' || strpos($media_url, 'youtube.com') !== FALSE || strpos($media_url, 'vimeo.com') !== FALSE) {
      // Default remote video bundle.
      $bundle = 'remote_video';
      // Default source field for remote video.
      $source_field = 'field_media_oembed_video';
    }
    else {
      $this->logger->warning('Could not determine media bundle for URL: @url. Please specify media_type (e.g., \'image\' or \'remote_video\') or ensure URL is for a common image/video provider.', [
        '@url' => $media_url,
      ]);
      return NULL;
    }

    $media_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('media');
    if (!isset($media_bundle_info[$bundle])) {
      $this->logger->error('Media bundle @bundle does not exist.', ['@bundle' => $bundle]);
      return NULL;
    }

    $bundle_fields = $this->entityFieldManager->getFieldDefinitions('media', $bundle);
    if (!isset($bundle_fields[$source_field])) {
      $log_context = ['@bundle' => $bundle, '@field' => $source_field];
      $this->logger->error('Media bundle @bundle does not have the source field @field.', $log_context);
      return NULL;
    }

    try {
      if ($bundle === 'image') {
        $image_data = @file_get_contents($media_url);
        if ($image_data === FALSE) {
          $this->logger->error('Failed to download image from URL: @url', ['@url' => $media_url]);
          return NULL;
        }
        $date_folder = date('Y-m');
        $destination_directory = 'public://media_imports/' . $date_folder;
        $this->fileSystem->prepareDirectory($destination_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        $parsed_url = parse_url($media_url, PHP_URL_PATH);
        $filename_from_url = $parsed_url ? basename($parsed_url) : 'image.jpg';
        $sanitized_filename = $this->sanitizeFilename($filename_from_url);
        $file_uri = $destination_directory . '/' . $sanitized_filename;
        $final_file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

        if (!$final_file_uri) {
          $this->logger->error('Failed to save downloaded image to @uri', ['@uri' => $destination_directory]);
          return NULL;
        }

        $file = File::create([
          'uri' => $final_file_uri,
          'uid' => $owner_id,
          // Mark as temporary.
          'status' => 0,
        ]);
        $file->save();

        $media_name = $media_data['name'] ?? $this->sanitizeFilename(basename($final_file_uri));
        $alt_text = $media_data['alt'] ?? $this->t('AI Generated Image from @url', ['@url' => $media_url]);

        $media = Media::create([
          'bundle' => $bundle,
          'uid' => $owner_id,
          'status' => Media::PUBLISHED,
          'name' => $media_name,
          $source_field => [
            'target_id' => $file->id(),
            'alt' => $alt_text,
          ],
        ]);
        $media->save();
        $file->setPermanent();
        $file->save();
        $this->logger->info('Created image media item @mid from URL @url', [
          '@mid' => $media->id(),
          '@url' => $media_url,
        ]);
        return $media->id();
      }
      elseif ($bundle === 'remote_video') {
        $media_name = $media_data['name'] ?? $this->t('Remote video from @url', ['@url' => $media_url]);
        $media = Media::create([
          'bundle' => $bundle,
          'uid' => $owner_id,
          'status' => Media::PUBLISHED,
          'name' => $media_name,
          $source_field => $media_url,
        ]);
        $media->save();
        $this->logger->info('Created remote video media item @mid for URL @url', [
          '@mid' => $media->id(),
          '@url' => $media_url,
        ]);
        return $media->id();
      }
    }
    catch (\Exception $e) {
      $log_context = [
        '@url' => $media_url,
        '@message' => $e->getMessage(),
      ];
      $this->logger->error('Failed to create media item for URL @url: @message', $log_context);
      return NULL;
    }
    return NULL;
  }

  /**
   * Creates a taxonomy term with the given name in a vocabulary and returns its ID.
   *
   * @param string $term_name
   *   Name of the term to create.
   * @param string $vocabulary
   *   Machine name of the vocabulary.
   * @param int $owner_id
   *   User ID of the owner.
   *
   * @return int|null
   *   Term ID if successful, NULL otherwise.
   */
  protected function createTaxonomyTerm(string $term_name, string $vocabulary, int $owner_id): ?int {
    return 0;
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
  protected function createNestedParagraph(array $component_data, int $owner_id): ?int {
    if (empty($component_data['type'])) {
      $this->logger->error('Cannot create nested paragraph without type: @data', [
        '@data' => json_encode($component_data),
      ]);
      return NULL;
    }

    $paragraph_type = strtolower(str_replace(' ', '_', $component_data['type']));

    $paragraph_bundle_info = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    if (!isset($paragraph_bundle_info[$paragraph_type])) {
      $log_context = [
        '@bundle' => $paragraph_type,
        '@data' => json_encode($component_data),
      ];
      $this->logger->error('Nested paragraph bundle @bundle does not exist. Data: @data', $log_context);
      return NULL;
    }

    try {
      $paragraph = Paragraph::create([
        'type' => $paragraph_type,
        'uid' => $owner_id,
        // 1 = published, 0 = unpublished
        'status' => 1,
      ]);

      // Set field values.
      foreach ($component_data as $field_name => $field_value) {
        // Skip type field and other non-field properties.
        if (in_array($field_name, ['type', 'id', 'name'])) {
          continue;
        }

        if ($paragraph->hasField($field_name)) {
          $field_definition = $paragraph->get($field_name)->getFieldDefinition();
          $field_type = $field_definition->getType();

          // Set field value based on type.
          if ($field_type === 'string' || $field_type === 'text' || $field_type === 'text_long') {
            $paragraph->set($field_name, $field_value);
          }
          elseif ($field_type === 'link' && is_array($field_value) && isset($field_value['uri'])) {
            $paragraph->set($field_name, [
              'uri' => $field_value['uri'],
              'title' => $field_value['title'] ?? '',
              'options' => $field_value['options'] ?? [],
            ]);
          }
          // Handle other field types as needed.
        }
      }

      $paragraph->save();
      return $paragraph->id();
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating nested paragraph: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a placeholder media item for use in AI-generated content.
   *
   * @param string $bundle
   *   The media bundle type (e.g., 'image', 'video', etc.).
   * @param string $alt_text
   *   The alt text for the media.
   * @param int $owner_id
   *   The owner user ID.
   *
   * @return int|null
   *   Media entity ID if successful, NULL otherwise.
   */
  protected function createPlaceholderMediaItem(string $bundle, string $alt_text, int $owner_id): ?int {
    // In a demo/prototype environment, we'll use a fixed media ID rather than creating
    // actual media entities that would require proper file handling.
    // This is a simplified approach for development purposes.

    // Log that we would create a media entity in production.
    $this->logger->notice('Would create @bundle media with alt text: @alt', [
      '@bundle' => $bundle,
      '@alt' => $alt_text,
    ]);

    // Return media ID 1 as a placeholder in all cases.
    // In a production environment, we would create proper media entities.
    return 1;
  }

  /**
   * Gets a taxonomy term ID by name, creating it if it doesn't exist.
   *
   * @param string $term_name
   *   The name of the taxonomy term.
   * @param array|string $vocabularies
   *   A single vocabulary machine name or an array of vocabulary machine names to search within.
   *
   * @return int|null
   *   The term ID or NULL if not found/created.
   */
  protected function getTermIdByName(string $term_name, $vocabularies): ?int {
    if (empty($term_name)) {
      return NULL;
    }
    $vocabularies = (array) $vocabularies;
    if (empty($vocabularies)) {
      $this->logger->warning('No vocabularies specified for taxonomy term lookup: @term', ['@term' => $term_name]);
      return NULL;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $term_storage->getQuery()
      ->condition('name', $term_name)
      ->condition('vid', $vocabularies, 'IN')
      // Perform access check.
      ->accessCheck(TRUE);
    $term_ids = $query->execute();

    if (!empty($term_ids)) {
      return reset($term_ids);
    }
    else {
      $primary_vocabulary = reset($vocabularies);
      $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
      $vocabulary_entity = $vocabulary_storage->load($primary_vocabulary);

      if (!$vocabulary_entity) {
        $log_context = [
          '@term_name' => $term_name,
          '@vid' => $primary_vocabulary,
        ];
        $this->logger->error('Cannot create term "@term_name" because vocabulary "@vid" does not exist.', $log_context);
        return NULL;
      }

      try {
        // Default to admin if anonymous.
        $term_owner_id = $this->currentUser->id() ?: 1;
        $term = $term_storage->create([
          'name' => $term_name,
          'vid' => $primary_vocabulary,
          'uid' => $term_owner_id,
        ]);
        $term->save();
        $log_context = [
          '@name' => $term_name,
          '@tid' => $term->id(),
          '@vid' => $primary_vocabulary,
        ];
        $this->logger->info('Created taxonomy term "@name" (@tid) in vocabulary @vid.', $log_context);
        return $term->id();
      }
      catch (\Exception $e) {
        $log_context = [
          '@name' => $term_name,
          '@vid' => $primary_vocabulary,
          '@message' => $e->getMessage(),
        ];
        $this->logger->error('Failed to create taxonomy term "@name" in vocabulary @vid: @message', $log_context);
        return NULL;
      }
    }
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
  protected function sanitizeFilename(string $filename): string {
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
    // Ensure filename is not too long (e.g. less than 200 chars after sanitization).
    $filename = mb_substr(mb_strtolower($filename), 0, 200);
    // Remove trailing period if any.
    $filename = rtrim($filename, '.');
    if (empty($filename)) {
      // Re-check after trimming and substr.
      return 'unnamed_file';
    }
    return $filename;
  }

}
