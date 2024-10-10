<?php

namespace Drupal\drupalx_ai\Commands;

use Drush\Commands\DrushCommands;
use Drupal\drupalx_ai\Service\AiLandingPageService;
use Drupal\drupalx_ai\Service\AnthropicApiService;
use Drupal\drupalx_ai\Service\ParagraphStructureService;

/**
 * Drush commands for creating AI-generated landing pages using Anthropic API.
 */
class AiLandingPageCommands extends DrushCommands
{

  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * The Anthropic API service.
   *
   * @var \Drupal\drupalx_ai\Service\AnthropicApiService
   */
  protected $anthropicApiService;

  /**
   * The paragraph structure service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphStructureService
   */
  protected $paragraphStructureService;

  /**
   * Constructs a new AiLandingPageCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\AiLandingPageService $ai_landing_page_service
   *   The AI landing page service.
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropic_api_service
   *   The Anthropic API service.
   * @param \Drupal\drupalx_ai\Service\ParagraphStructureService $paragraph_structure_service
   *   The paragraph structure service.
   */
  public function __construct(
    AiLandingPageService $ai_landing_page_service,
    AnthropicApiService $anthropic_api_service,
    ParagraphStructureService $paragraph_structure_service
  ) {
    parent::__construct();
    $this->aiLandingPageService = $ai_landing_page_service;
    $this->anthropicApiService = $anthropic_api_service;
    $this->paragraphStructureService = $paragraph_structure_service;
  }

  /**
   * Creates an AI-generated landing page using Anthropic API.
   *
   * @command drupalx:create-ai-landing-page
   * @aliases dxail
   */
  public function createAiLandingPage()
  {
    $description = $this->io()->ask('Please provide a description of the landing page content you want to generate:');

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
                    'additionalProperties' => true,
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

    if ($result) {
      $nodeUrl = $this->aiLandingPageService->createLandingNodeWithAiContent($result['paragraphs']);
      $this->io()->success("AI-generated landing page created successfully. Edit URL: $nodeUrl");
    } else {
      $this->io()->error('Failed to generate AI landing page content.');
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
  protected function buildPrompt($description, $paragraphStructures, $materialIcons)
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

    $prompt .= "IMPORTANT: Please generate a landing page structure using these paragraph types. Fill in realistic content for each field. Use a variety of paragraph types to create an engaging and diverse landing page. When you're done, call the generate_ai_landing_page function with the generated structure.\n\n";
    $prompt .= "The structure should be an array of paragraphs, where each paragraph is an object with 'type' and 'fields' properties. The 'fields' property should be an object where keys are field names and values are the content for those fields.\n\n";
    $prompt .= "CRITICAL: Ensure that EVERY paragraph, including sub-paragraphs (such as accordion items or pricing cards), has a 'type' property. Do not omit the 'type' for any paragraph at any level.\n\n";
    $prompt .= "For entity reference fields, use appropriate existing entity names or IDs. For viewsreference fields, use existing view names and display IDs.\n\n";
    $prompt .= "For list_string fields, make sure to choose a value from the provided options in the 'o' array.\n\n";
    $prompt .= "CRITICAL: For fields named 'field_icon', you MUST choose a value ONLY from the provided Material Icon names listed above. Do not use any icon names that are not in this list.\n\n";

    $prompt .= "IMPORTANT: Please generate a landing page structure using these paragraph types. Fill in realistic content for each field. Use a variety of paragraph types to create an engaging and diverse landing page. When you're done, call the generate_ai_landing_page function with the generated structure.\n\n";
    $prompt .= "The structure should be an array of paragraphs, where each paragraph is an object with 'type' and 'fields' properties. The 'fields' property should be an object where keys are field names and values are the content for those fields.\n\n";
    $prompt .= "CRITICAL: Ensure that EVERY paragraph, including sub-paragraphs (such as accordion items or pricing cards), has a 'type' property. Do not omit the 'type' for any paragraph at any level.\n\n";
    $prompt .= "For entity reference fields, use appropriate existing entity names or IDs. For viewsreference fields, use existing view names and display IDs.\n\n";
    $prompt .= "For list_string fields, make sure to choose a value from the provided options in the 'o' array.\n\n";
    $prompt .= "For fields named 'field_icon', choose a value from the provided Material Icon names.\n\n";
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
}
