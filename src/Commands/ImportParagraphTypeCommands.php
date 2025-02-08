<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\AiModelApiService;
use Drupal\drupalx_ai\Service\ComponentReaderService;
use Drupal\drupalx_ai\Service\ParagraphImporterService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 *
 * @package Drupal\drupalx_ai\Commands
 */
class ImportParagraphTypeCommands extends DrushCommands {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The paragraph importer service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphImporterService
   */
  protected $paragraphImporter;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The AI Model API service.
   *
   * @var \Drupal\drupalx_ai\Service\AiModelApiService
   */
  protected $aiModelApiService;

  /**
   * The component reader service.
   *
   * @var \Drupal\drupalx_ai\Service\ComponentReaderService
   */
  protected $componentReader;

  /**
   * Constructor for ImportParagraphTypeCommands.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\drupalx_ai\Service\ParagraphImporterService $paragraph_importer
   *   The paragraph importer service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\AiModelApiService $ai_model_api_service
   *   The AI Model API service.
   * @param \Drupal\drupalx_ai\Service\ComponentReaderService $component_reader
   *   The component reader service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ParagraphImporterService $paragraph_importer, LoggerChannelFactoryInterface $logger_factory, AiModelApiService $ai_model_api_service, ComponentReaderService $component_reader) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->paragraphImporter = $paragraph_importer;
    $this->loggerFactory = $logger_factory;
    $this->aiModelApiService = $ai_model_api_service;
    $this->componentReader = $component_reader;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('drupalx_ai.paragraph_importer'),
      $container->get('logger.factory'),
      $container->get('drupalx_ai.ai_model_api'),
      $container->get('drupalx_ai.component_reader')
    );
  }

  /**
   * Import a paragraph type based on a theme component using AI.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output interface.
   * @param string|null $component_name
   *   Optional component name to import.
   * @param array $options
   *   An array of command options.
   *
   * @option auto-confirm
   *   Skip confirmation prompts.
   *
   * @command drupalx-ai:import-from-component
   * @aliases dxcomp
   *
   * @usage drush drupalx-ai:import-from-component
   * @usage drush drupalx-ai:import-from-component hero
   * @usage drush drupalx-ai:import-from-component hero --auto-confirm
   */
  public function importParagraphTypeFromComponent(OutputInterface $output, ?string $component_name = NULL, array $options = ['auto-confirm' => FALSE]) {
    // Check if API key is set before proceeding.
    if (empty($this->configFactory->get('drupalx_ai.settings')->get('api_key'))) {
      $output->writeln("<e>AI API key is not set. Please configure it in the DrupalX AI Settings before running this command.</e>");
      return;
    }

    // Use the ComponentReaderService for these operations.
    $componentFolderName = $component_name;
    if (!$componentFolderName) {
      $componentFolderName = $this->componentReader->askComponentFolder($this->io(), $options['auto-confirm']);
    }
    [$componentName, $componentContent, $storyContent] = $this->componentReader->readComponentFiles($componentFolderName, $this->io(), $options['auto-confirm']);

    if (!$componentContent) {
      $output->writeln("<e>Could not read component file. Please check the file exists and is readable.</e>");
      return;
    }

    // Generate paragraph type details using AI model.
    $paragraphTypeDetails = $this->generateParagraphTypeDetails($componentName, $componentContent, $storyContent);

    if (!$paragraphTypeDetails) {
      $output->writeln("<e>Failed to generate paragraph type details from the component.</e>");
      return;
    }

    // Display generated details and ask for confirmation if auto-confirm is not set.
    $output->writeln("<info>Generated Paragraph Type Details:</info>");
    $output->writeln(print_r($paragraphTypeDetails, TRUE));

    if (!$options['auto-confirm'] && !$this->io()->confirm('Do you want to proceed with importing this paragraph type?', TRUE)) {
      $output->writeln('Import cancelled.');
      return;
    }

    // Convert the array to an object recursively.
    $paragraphTypeObject = json_decode(json_encode($paragraphTypeDetails));

    // Import the paragraph type using the ParagraphImporterService.
    $result = $this->paragraphImporter->importParagraphType($paragraphTypeObject);
    $output->writeln($result);

    drupal_flush_all_caches();
    $output->writeln("<info>All caches have been flushed.</info>");
  }

  /**
   * Generate paragraph type details using AI model.
   */
  protected function generateParagraphTypeDetails($componentName, $componentContent, $storyContent) {
    $prompt = "Based on this component named '{$componentName}', suggest a Drupal paragraph type
      structure using the suggest_paragraph_type function. If the component has a nested structure
      (like cards within a card container), create both the parent and child paragraph types.
      For the recent-cards component specifically:
      1. Create a parent paragraph type named 'recent_cards' that has a field referencing the child type
      2. Create a child paragraph type named 'recent_card_item' with fields for title, summary, link, and media
      3. The parent type should be the main return object, with the child type defined in its child_types array
      4. The parent type should have an entity_reference_revisions field that references the child type.

      The name of the paragraph should not include the word 'paragraph'.
      Make sure the name of the paragraph is the exact same as the name of the component.
      For fields, only lowercase alphanumeric characters and underscores are allowed,
      and only lowercase letters and underscore are allowed as the first character.
      Do not add '_component' to the name of the component.
      Do not use the field type 'list_text' - the correct type is 'list_string'.
      Use only Drupal 10 valid field types. For images use the 'image' field type.

      IMPORTANT: For components that have a parent-child relationship like recent-cards:
      1. First define the child type in the child_types array
      2. Then in the parent type's fields, include an 'entity_reference_revisions' field that references the child paragraph type
      3. The parent type should be the main return object, with child types nested within it
      4. Make sure to set appropriate cardinality for the reference field (usually -1 for unlimited)
      5. For entity_reference_revisions fields, set target_type to 'paragraph' and target_bundle to the child type's id

      Example structure for recent-cards:
      {
        'id': 'recent_cards',
        'name': 'Recent Cards',
        'description': 'A collection of recent card items',
        'fields': [
          {
            'name': 'card_items',
            'label': 'Card Items',
            'type': 'entity_reference_revisions',
            'target_type': 'paragraph',
            'target_bundle': 'recent_card_item',
            'cardinality': -1,
            'required': true
          }
        ],
        'child_types': [
          {
            'id': 'recent_card_item',
            'name': 'Recent Card Item',
            'description': 'Individual card item',
            'fields': [
              {
                'name': 'title',
                'label': 'Title',
                'type': 'string',
                'required': true
              },
              // ... other fields ...
            ]
          }
        ]
      }";

    $tools = [
      [
        'name' => 'suggest_paragraph_type',
        'description' => "Suggests a Drupal paragraph type structure based on a theme component",
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'string',
              'description' => 'Machine name of the paragraph type',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'Human-readable name of the paragraph type',
            ],
            'description' => [
              'type' => 'string',
              'description' => 'Description of the paragraph type',
            ],
            'fields' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'name' => [
                    'type' => 'string',
                    'description' => 'Machine name of the field',
                  ],
                  'label' => [
                    'type' => 'string',
                    'description' => 'Human-readable label of the field',
                  ],
                  'type' => [
                    'type' => 'string',
                    'description' => 'Drupal 10 valid field type',
                  ],
                  'required' => [
                    'type' => 'boolean',
                    'description' => 'Whether the field is required',
                  ],
                  'cardinality' => [
                    'type' => 'integer',
                    'description' => 'The number of values users can enter for this field. -1 for unlimited.',
                  ],
                  'sample_value' => [
                    'type' => 'string',
                    'description' => 'Sample value for the field',
                  ],
                  'options' => [
                    'type' => 'array',
                    'items' => [
                      'type' => 'string',
                    ],
                    'description' => 'Array of string options for the field (list text only)',
                  ],
                  'target_type' => [
                    'type' => 'string',
                    'description' => 'For entity reference fields, the type of entity to reference',
                  ],
                  'target_bundle' => [
                    'type' => 'string',
                    'description' => 'For entity reference fields, the bundle to reference',
                  ],
                ],
                'required' => ['name', 'label', 'type', 'sample_value'],
              ],
            ],
            'child_types' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'id' => [
                    'type' => 'string',
                    'description' => 'Machine name of the child paragraph type',
                  ],
                  'name' => [
                    'type' => 'string',
                    'description' => 'Human-readable name of the child paragraph type',
                  ],
                  'description' => [
                    'type' => 'string',
                    'description' => 'Description of the child paragraph type',
                  ],
                  'fields' => [
                    'type' => 'array',
                    'items' => [
                      'type' => 'object',
                      'properties' => [
                        'name' => [
                          'type' => 'string',
                          'description' => 'Machine name of the field',
                        ],
                        'label' => [
                          'type' => 'string',
                          'description' => 'Human-readable label of the field',
                        ],
                        'type' => [
                          'type' => 'string',
                          'description' => 'Drupal 10 valid field type',
                        ],
                        'required' => [
                          'type' => 'boolean',
                          'description' => 'Whether the field is required',
                        ],
                        'cardinality' => [
                          'type' => 'integer',
                          'description' => 'The number of values users can enter for this field. -1 for unlimited.',
                        ],
                        'sample_value' => [
                          'type' => 'string',
                          'description' => 'Sample value for the field',
                        ],
                      ],
                      'required' => ['name', 'label', 'type', 'sample_value'],
                    ],
                  ],
                ],
                'required' => ['id', 'name', 'description', 'fields'],
              ],
            ],
          ],
          'required' => ['id', 'name', 'description', 'fields'],
        ],
      ],
    ];

    return $this->aiModelApiService->callAiApi($prompt, $tools, 'suggest_paragraph_type');
  }

}
