<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Url;

/**
 * Service for creating AI-generated landing pages.
 */
class AiLandingPageService
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The mock landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\MockLandingPageService
   */
  protected $mockLandingPageService;

  /**
   * Constructs a new AiLandingPageService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\MockLandingPageService $mock_landing_page_service
   *   The mock landing page service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MockLandingPageService $mock_landing_page_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->mockLandingPageService = $mock_landing_page_service;
  }

  /**
   * Creates a landing page node with AI-generated content.
   *
   * @param array $paragraphs
   *   An array of paragraph data generated by AI.
   *
   * @return string|null
   *   The URL of the created node, or null if creation failed.
   */
  public function createLandingNodeWithAiContent($paragraphs)
  {
    $node = Node::create([
      'type' => 'landing',
      'title' => 'AI Generated Landing Page',
      'field_hide_page_title' => 1,
      'status' => 1,
    ]);

    foreach ($paragraphs as $paragraph_data) {
      $paragraph = $this->createParagraphFromGeneratedContent($paragraph_data);
      if ($paragraph) {
        $node->field_content[] = $paragraph;
      }
    }

    try {
      $node->save();
      $url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]);
      return $url->setAbsolute()->toString();
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to create landing page: @message', ['@message' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Creates a paragraph entity from generated content.
   *
   * @param array $paragraph_data
   *   The generated data for a single paragraph.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph|null
   *   The created paragraph entity, or null if creation failed.
   */
  protected function createParagraphFromGeneratedContent($paragraph_data)
  {
    try {
      $paragraph = Paragraph::create([
        'type' => $paragraph_data['type'],
      ]);

      foreach ($paragraph_data['fields'] as $field_name => $field_value) {
        if ($field_name === 'field_media' && is_string($field_value)) {
          $media = $this->createOrFetchMedia($this->preprocessImageSearchTerm($field_value));
          if ($media) {
            $paragraph->set($field_name, $media);
          }
        } elseif (is_array($field_value) && isset($field_value[0]['type'])) {
          // This is likely a nested paragraph field
          $nested_paragraphs = [];
          foreach ($field_value as $nested_paragraph_data) {
            $nested_paragraph = $this->createParagraphFromGeneratedContent($nested_paragraph_data);
            if ($nested_paragraph) {
              $nested_paragraphs[] = $nested_paragraph;
            }
          }
          $paragraph->set($field_name, $nested_paragraphs);
        } else {
          $paragraph->set($field_name, $field_value);
        }
      }

      $paragraph->save();
      return $paragraph;
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to create paragraph: @message', ['@message' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Preprocesses an image search term.
   *
   * @param mixed $term
   *   The search term to preprocess.
   *
   * @return string
   *   The preprocessed search term.
   */
  protected function preprocessImageSearchTerm($term)
  {
    if (is_array($term)) {
      // If $term is an array, let's join its elements into a string
      $term = implode(' ', $term);
    }

    if (!is_string($term)) {
      // If $term is still not a string, convert it to one
      $term = (string) $term;
    }

    $term = trim($term);
    $term = strtolower($term);
    $term = preg_replace('/[^a-z0-9\s]/', ' ', $term);
    return $term;
  }

  /**
   * Creates or fetches a media entity based on a search term.
   *
   * @param string $search_term
   *   The search term to use for finding or creating media.
   *
   * @return \Drupal\media\Entity\Media|null
   *   The media entity, or null if creation/fetching failed.
   */
  protected function createOrFetchMedia($search_term)
  {
    // First, try to create a new media entity using MockLandingPageService
    try {
      $new_media = $this->mockLandingPageService->createMediaEntityFromUnsplash($search_term);
      if ($new_media) {
        return $new_media;
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to create media entity: @message', ['@message' => $e->getMessage()]);
    }

    // If creation fails or returns null, fall back to finding an existing media item
    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing_media = $media_storage->loadByProperties([
      'name' => $search_term,
      'bundle' => 'image',
    ]);

    if (!empty($existing_media)) {
      return reset($existing_media);
    }

    // If no existing media found either, return null
    return null;
  }
}
