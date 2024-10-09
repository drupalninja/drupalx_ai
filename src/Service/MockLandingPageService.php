<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for creating mock landing pages with paragraphs.
 */
class MockLandingPageService
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The paragraph structure service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphStructureService
   */
  protected $paragraphStructureService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new MockLandingPageService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\drupalx_ai\Service\ParagraphStructureService $paragraph_structure_service
   *   The paragraph structure service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    ClientInterface $http_client,
    LoggerInterface $logger,
    ParagraphStructureService $paragraph_structure_service,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->paragraphStructureService = $paragraph_structure_service;
    $this->configFactory = $config_factory;
  }

  /**
   * Creates a landing node with mock paragraph content.
   *
   * @return string
   *   The full edit URL of the created node.
   */
  public function createLandingNodeWithMockContent()
  {
    $paragraphs_structure = $this->paragraphStructureService->getParagraphStructures();

    // Get allowed paragraph types for field_content
    $allowed_paragraph_types = $this->getAllowedParagraphTypes('node', 'landing', 'field_content');

    // Create the node
    $node = Node::create([
      'type' => 'landing',
      'title' => 'Mock Landing Page ' . time(),
      'uid' => 1, // Set author to user 1
    ]);

    // Create paragraphs and add them to the node
    $paragraphs = [];
    foreach ($paragraphs_structure as $paragraph_type) {
      if (in_array($paragraph_type->bundle, $allowed_paragraph_types)) {
        $paragraph = $this->createMockParagraph($paragraph_type, $paragraphs_structure);
        $paragraphs[] = $paragraph;
      }
    }

    $node->set('field_content', $paragraphs);
    $node->save();

    // Generate the full edit URL
    return Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['absolute' => TRUE])->toString();
  }

  /**
   * Gets allowed paragraph types for a given field.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node').
   * @param string $bundle
   *   The bundle (e.g., 'landing').
   * @param string $field_name
   *   The name of the field (e.g., 'field_content').
   *
   * @return array
   *   An array of allowed paragraph type machine names.
   */
  protected function getAllowedParagraphTypes($entity_type, $bundle, $field_name)
  {
    $field_config = $this->entityTypeManager
      ->getStorage('field_config')
      ->load($entity_type . '.' . $bundle . '.' . $field_name);

    if (!$field_config) {
      return [];
    }

    $handler_settings = $field_config->getSetting('handler_settings');
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    $negate = $handler_settings['negate'] ?? false;
    $target_bundles_drag_drop = $handler_settings['target_bundles_drag_drop'] ?? [];

    $all_types = array_keys($target_bundles_drag_drop);
    $specified_types = array_keys($target_bundles);

    if ($negate) {
      return array_diff($all_types, $specified_types);
    } else {
      return $specified_types;
    }
  }

  /**
   * Creates a mock paragraph based on the given structure.
   *
   * @param object $paragraph_type
   *   The structure of the paragraph type.
   * @param array $all_paragraph_types
   *   All paragraph type structures.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The created paragraph entity.
   */
  protected function createMockParagraph($paragraph_type, $all_paragraph_types)
  {
    $paragraph = Paragraph::create([
      'type' => $paragraph_type->bundle,
    ]);

    foreach ($paragraph_type->fields as $field) {
      $field_name = $field->name;
      $field_type = $field->type;
      $example_value = $field->example;

      // Skip setting a value for field_custom_icon
      if ($field_name === 'field_custom_icon') {
        continue;
      }

      switch ($field_type) {
        case 'entity_reference_revisions':
          if (isset($example_value->bundle)) {
            $sub_paragraph_full_type = $this->findParagraphType($example_value->bundle, $all_paragraph_types);
            if ($sub_paragraph_full_type) {
              $sub_paragraph = $this->createMockParagraph($sub_paragraph_full_type, $all_paragraph_types);
              $paragraph->set($field_name, $sub_paragraph);
            }
          }
          break;
        case 'entity_reference':
          if (isset($field->target_type) && $field->target_type === 'media') {
            $media_entity = $this->createMediaEntityFromPexels($example_value);
            if ($media_entity) {
              $paragraph->set($field_name, $media_entity);
            }
          } else {
            // For other entity references, we're not creating actual entities
            $paragraph->set($field_name, NULL);
          }
          break;
        case 'link':
          if (is_object($example_value) && isset($example_value->url) && isset($example_value->text)) {
            $paragraph->set($field_name, [
              'uri' => $example_value->url,
              'title' => $example_value->text,
            ]);
          }
          break;
        case 'list_string':
          // For list_string, we'll just use the example value as is
          $paragraph->set($field_name, $example_value);
          break;
        case 'viewfield':
          // For viewfield, we'll set a placeholder value
          $paragraph->set($field_name, [
            'target_id' => 'recent_cards',
            'display_id' => 'article_cards',
          ]);
          break;
        default:
          $paragraph->set($field_name, $example_value);
      }
    }

    return $paragraph;
  }

  /**
   * Finds a full paragraph type structure by its bundle name.
   *
   * @param string $bundle
   *   The bundle name to find.
   * @param array $all_paragraph_types
   *   All paragraph type structures.
   *
   * @return object|null
   *   The full paragraph type structure or null if not found.
   */
  protected function findParagraphType($bundle, $all_paragraph_types)
  {
    foreach ($all_paragraph_types as $paragraph_type) {
      if ($paragraph_type->bundle === $bundle) {
        return $paragraph_type;
      }
    }
    return null;
  }

  /**
   * Creates a media entity from Pexels API.
   *
   * @param string $alt_text
   *   The alt text to use for the image search and media entity.
   *
   * @return int|null
   *   The media entity ID if successful, null otherwise.
   */
  protected function createMediaEntityFromPexels($alt_text)
  {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $api_key = $config->get('pexels_api_key');

    $pexels_api_url = 'https://api.pexels.com/v1/search?query=' . urlencode($alt_text) . '&per_page=1';

    try {
      $response = $this->httpClient->get($pexels_api_url, [
        'headers' => [
          'Authorization' => $api_key,
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);
    } catch (\Exception $e) {
      $this->logger->error('Failed to fetch image from Pexels: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    if (!isset($data['photos'][0]['src']['medium'])) {
      $this->logger->error('Failed to fetch image from Pexels for alt text: @alt', ['@alt' => $alt_text]);
      return NULL;
    }

    $image_url = $data['photos'][0]['src']['medium'];

    // Download the image
    try {
      $image_response = $this->httpClient->get($image_url);
      $image_data = $image_response->getBody()->getContents();
    } catch (\Exception $e) {
      $this->logger->error('Failed to download image from Pexels: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    // Save the image as a file entity
    $directory = 'public://pexels';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    $file = File::create([
      'filename' => 'pexels_' . time() . '.jpg',
      'uri' => $directory . '/pexels_' . time() . '.jpg',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);

    try {
      $this->fileSystem->saveData($image_data, $file->getFileUri(), FileSystemInterface::EXISTS_REPLACE);
      $file->save();
    } catch (\Exception $e) {
      $this->logger->error('Failed to save Pexels image as file: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    // Create a media entity
    $media = Media::create([
      'bundle' => 'image',
      'uid' => 1,
      'field_image' => [
        'target_id' => $file->id(),
        'alt' => $alt_text,
      ],
      'name' => $alt_text,
    ]);

    $media->save();

    return $media->id();
  }
}
