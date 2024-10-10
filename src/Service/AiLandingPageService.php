<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service for creating AI-generated landing pages.
 */
final class AiLandingPageService
{

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * Constructs a new AiLandingPageService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\MockLandingPageService $mockLandingPageService
   *   The mock landing page service.
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropicApiService
   *   The Anthropic API service.
   * @param \Drupal\drupalx_ai\Service\ParagraphStructureService $paragraphStructureService
   *   The paragraph structure service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MockLandingPageService $mockLandingPageService,
    private readonly AnthropicApiService $anthropicApiService,
    private readonly ParagraphStructureService $paragraphStructureService,
    ConfigFactoryInterface $configFactory
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * Generates AI content for a landing page based on a description.
   *
   * @param string $description
   *   The user-provided description of the desired landing page.
   *
   * @return array|null
   *   An array of paragraph data generated by AI, or null if generation failed.
   */
  public function generateAiContent(string $description): ?array
  {
    $paragraphStructures = $this->paragraphStructureService->getParagraphStructures();
    $materialIcons = $this->paragraphStructureService->getMaterialIconNames();
    $prompt = $this->buildPrompt($description, $paragraphStructures, $materialIcons);

    $tools = [
      [
        'name' => 'generate_ai_landing_page',
        'description' => 'Generate an AI-driven landing page structure with content',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'paragraphs' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'type' => [
                    'type' => 'string',
                    'description' => 'The type of the paragraph',
                  ],
                  'fields' => [
                    'type' => 'object',
                    'additionalProperties' => TRUE,
                    'description' => 'The fields of the paragraph with their values',
                  ],
                ],
                'required' => ['type', 'fields'],
              ],
            ],
          ],
          'required' => ['paragraphs'],
        ],
      ],
    ];

    $result = $this->anthropicApiService->callAnthropic($prompt, $tools, 'generate_ai_landing_page');

    if (is_array($result) && isset($result['paragraphs'])) {
      return $result['paragraphs'];
    } else {
      $this->loggerFactory->get('drupalx_ai')->error('AI content generation failed or returned unexpected result');
      return NULL;
    }
  }

  /**
   * Builds the prompt for the Anthropic API.
   *
   * @param string $description
   *   The user-provided description of the desired landing page.
   * @param array $paragraphStructures
   *   The available paragraph structures.
   * @param array $materialIcons
   *   The list of Material Icon names.
   *
   * @return string
   *   The constructed prompt.
   */
  private function buildPrompt(string $description, array $paragraphStructures, array $materialIcons): string
  {
    $prompt = "Generate an AI-driven landing page structure with content based on the following description:\n\n";
    $prompt .= "$description\n\n";
    $prompt .= "Available paragraph types and their structures (in JSON format):\n\n";

    $prompt .= json_encode($paragraphStructures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    $prompt .= "Understanding the JSON structure:\n";
    $prompt .= "- 'b' represents the bundle (paragraph type)\n";
    $prompt .= "- 'f' is an array of fields, where each field has:\n";
    $prompt .= "  - 't': field type\n";
    $prompt .= "  - 'n': field name\n";
    $prompt .= "  - 'r': required (boolean)\n";
    $prompt .= "  - 'c': cardinality (-1 for unlimited, or a positive integer)\n";
    $prompt .= "  - 'e': an example value for the field\n";
    $prompt .= "  - 'o': (for list_string fields only) an array of allowed options\n\n";

    $prompt .= "Available Material Icon names:\n";
    $prompt .= implode(", ", $materialIcons) . "\n\n";

    $allowedParagraphTypes = $this->mockLandingPageService->getAllowedParagraphTypes('node', 'landing', 'field_content');
    $prompt .= "IMPORTANT: Only use the following paragraph types as top-level paragraphs:\n";
    $prompt .= implode(", ", $allowedParagraphTypes) . "\n\n";

    $prompt .= "CRITICAL: When generating the landing page structure, ensure that ONLY the allowed paragraph types listed above are used as top-level paragraphs. Other paragraph types can be used as nested paragraphs within these allowed types if the structure permits.\n\n";

    $prompt .= "Please generate a landing page structure using these paragraph types. Fill in realistic content for each field. Use a variety of paragraph types to create an engaging and diverse landing page, while adhering to the allowed top-level paragraph types. When you're done, call the generate_ai_landing_page function with the generated structure.\n\n";

    $prompt .= "The structure should be an array of paragraphs, where each paragraph is an object with 'type' and 'fields' properties. The 'fields' property should be an object where keys are field names and values are the content for those fields.\n\n";
    $prompt .= "CRITICAL: Ensure that EVERY paragraph, including sub-paragraphs (such as accordion items or pricing cards), has a 'type' property. Do not omit the 'type' for any paragraph at any level.\n\n";
    $prompt .= "For entity reference fields, use appropriate existing entity names or IDs. For viewsreference fields, use existing view names and display IDs.\n\n";
    $prompt .= "For list_string fields, make sure to choose a value from the provided options in the 'o' array.\n\n";
    $prompt .= "CRITICAL: For fields named 'field_icon', you MUST choose a value ONLY from the provided Material Icon names listed above. Do not use any icon names that are not in this list.\n\n";

    $prompt .= "Example structure:\n";
    $prompt .= "{\n";
    $prompt .= "  \"paragraphs\": [\n";
    $prompt .= "    {\n";
    $prompt .= "      \"type\": \"hero\",\n";
    $prompt .= "      \"fields\": {\n";
    $prompt .= "        \"field_title\": \"Welcome to Our Service\",\n";
    $prompt .= "        \"field_body\": \"We provide top-notch solutions for your needs.\",\n";
    $prompt .= "        \"field_media\": \"Technology\"\n";
    $prompt .= "      }\n";
    $prompt .= "    },\n";
    $prompt .= "    {\n";
    $prompt .= "      \"type\": \"accordion\",\n";
    $prompt .= "      \"fields\": {\n";
    $prompt .= "        \"field_title\": \"Frequently Asked Questions\",\n";
    $prompt .= "        \"field_accordion_item\": [\n";
    $prompt .= "          {\n";
    $prompt .= "            \"type\": \"accordion_item\",\n";
    $prompt .= "            \"fields\": {\n";
    $prompt .= "              \"field_title\": \"What services do you offer?\",\n";
    $prompt .= "              \"field_body\": \"We offer a wide range of services including...\"\n";
    $prompt .= "            }\n";
    $prompt .= "          }\n";
    $prompt .= "        ]\n";
    $prompt .= "      }\n";
    $prompt .= "    }\n";
    $prompt .= "  ]\n";
    $prompt .= "}\n";

    return $prompt;
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
  public function createLandingNodeWithAiContent(array $paragraphs): ?string
  {
    $node = Node::create([
      'type' => 'landing',
      'title' => 'AI Generated Landing Page',
      'field_hide_page_title' => 1,
      'status' => 1,
    ]);

    foreach ($paragraphs as $paragraphData) {
      $paragraph = $this->createParagraphFromGeneratedContent($paragraphData);
      if ($paragraph) {
        $node->get('field_content')->appendItem($paragraph);
      }
    }

    try {
      $node->save();
      $url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]);
      return $url->setAbsolute()->toString();
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to create landing page: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Creates a paragraph entity from generated content.
   *
   * @param array $paragraphData
   *   The generated data for a single paragraph.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph|null
   *   The created paragraph entity, or null if creation failed.
   */
  private function createParagraphFromGeneratedContent(array $paragraphData): ?Paragraph
  {
    try {
      $paragraph = Paragraph::create([
        'type' => $paragraphData['type'],
      ]);

      $fieldDefinitions = $paragraph->getFieldDefinitions();

      foreach ($paragraphData['fields'] as $fieldName => $fieldValue) {
        $fieldDefinition = $fieldDefinitions[$fieldName] ?? null;

        if ($fieldDefinition && $fieldDefinition->getType() === 'entity_reference' && $fieldDefinition->getSetting('target_type') === 'media' && is_string($fieldValue)) {
          $media = $this->createOrFetchMedia($this->preprocessImageSearchTerm($fieldValue));
          if ($media) {
            $paragraph->set($fieldName, $media);
          }
        } elseif (is_array($fieldValue) && isset($fieldValue[0]['type'])) {
          // This is likely a nested paragraph field.
          $nestedParagraphs = [];
          foreach ($fieldValue as $nestedParagraphData) {
            $nestedParagraph = $this->createParagraphFromGeneratedContent($nestedParagraphData);
            if ($nestedParagraph) {
              $nestedParagraphs[] = $nestedParagraph;
            }
          }
          $paragraph->set($fieldName, $nestedParagraphs);
        } elseif ($fieldName === 'field_icon') {
          // Special handling for field_icon.
          $materialIcons = $this->paragraphStructureService->getMaterialIconNames();
          if (!in_array($fieldValue, $materialIcons, TRUE)) {
            // If the icon is not valid, use 'star' as a fallback.
            $fieldValue = 'star';
          }
          $paragraph->set($fieldName, $fieldValue);
        } else {
          $paragraph->set($fieldName, $fieldValue);
        }
      }

      $paragraph->save();
      return $paragraph;
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to create paragraph: @message', ['@message' => $e->getMessage()]);
      return NULL;
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
  private function preprocessImageSearchTerm(mixed $term): string
  {
    if (is_array($term)) {
      // If $term is an array, let's join its elements into a string.
      $term = implode(' ', $term);
    }

    $term = (string) $term;
    $term = trim($term);
    $term = strtolower($term);
    return preg_replace('/[^a-z0-9\s]/', ' ', $term);
  }

  /**
   * Creates or fetches a media entity based on a search term.
   *
   * @param string $searchTerm
   *   The search term to use for finding or creating media.
   *
   * @return int|null
   *   The media entity ID if successful, null otherwise.
   */
  private function createOrFetchMedia(string $searchTerm)
  {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $imageGenerator = $config->get('image_generator') ?: 'unsplash';

    try {
      $newMedia = null;
      if ($imageGenerator === 'unsplash') {
        $newMedia = (int) $this->mockLandingPageService->createMediaEntityFromUnsplash($searchTerm);
      } elseif ($imageGenerator === 'pexels') {
        $newMedia = (int) $this->mockLandingPageService->createMediaEntityFromPexels($searchTerm);
      }

      if ($newMedia) {
        $this->loggerFactory->get('drupalx_ai')->info('Successfully created new media entity for search term: @term using @generator', [
          '@term' => $searchTerm,
          '@generator' => $imageGenerator,
        ]);
        return $newMedia;
      }
      $this->loggerFactory->get('drupalx_ai')->warning('Failed to create new media entity for search term: @term using @generator', [
        '@term' => $searchTerm,
        '@generator' => $imageGenerator,
      ]);
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Exception while creating media entity: @message', ['@message' => $e->getMessage()]);
    }

    // If creation fails or returns null, fall back to finding an existing media item.
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $existingMedia = $mediaStorage->loadByProperties([
      'name' => $searchTerm,
      'bundle' => 'image',
    ]);

    if (!empty($existingMedia)) {
      return reset($existingMedia)->id();
    }

    // If no existing media found either, return null.
    return NULL;
  }
}
