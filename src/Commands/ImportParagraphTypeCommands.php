<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\AnthropicApiService;
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
   * The Anthropic API service.
   *
   * @var \Drupal\drupalx_ai\Service\AnthropicApiService
   */
  protected $anthropicApiService;

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
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropic_api_service
   *   The Anthropic API service.
   * @param \Drupal\drupalx_ai\Service\ComponentReaderService $component_reader
   *   The component reader service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ParagraphImporterService $paragraph_importer, LoggerChannelFactoryInterface $logger_factory, AnthropicApiService $anthropic_api_service, ComponentReaderService $component_reader) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->paragraphImporter = $paragraph_importer;
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
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
      $container->get('drupalx_ai.anthropic_api'),
      $container->get('drupalx_ai.component_reader')
    );
  }

  /**
   * Import a new paragraph type based on a Next.js component using AI.
   *
   * @command drupalx-ai:import-from-component
   * @aliases dai-ifc
   * @usage drush drupalx-ai:import-from-component
   */
  public function importParagraphTypeFromComponent(OutputInterface $output) {
    // Check if API key is set before proceeding.
    if (empty($this->configFactory->get('drupalx_ai.settings')->get('api_key'))) {
      $output->writeln("<error>Anthropic API key is not set. Please configure it in the DrupalX AI Settings before running this command.</error>");
      return;
    }

    // Use the ComponentReaderService for these operations.
    $componentFolderName = $this->componentReader->askComponentFolder($this->io());
    [$componentName, $componentContent] = $this->componentReader->readComponentFile($componentFolderName, $this->io());

    if (!$componentContent) {
      $output->writeln("<error>Could not read component file. Please check the file exists and is readable.</error>");
      return;
    }

    // Generate paragraph type details using Claude 3 Haiku.
    $paragraphTypeDetails = $this->generateParagraphTypeDetails($componentName, $componentContent);

    if (!$paragraphTypeDetails) {
      $output->writeln("<error>Failed to generate paragraph type details from the component.</error>");
      return;
    }

    // Display generated details and ask for confirmation.
    $output->writeln("<info>Generated Paragraph Type Details:</info>");
    $output->writeln(print_r($paragraphTypeDetails, TRUE));

    if (!$this->io()->confirm('Do you want to proceed with importing this paragraph type?', TRUE)) {
      $output->writeln('Import cancelled.');
      return;
    }

    // Import the paragraph type using the ParagraphImporterService.
    $result = $this->paragraphImporter->importParagraphType((object) $paragraphTypeDetails);
    $output->writeln($result);
  }

  /**
   * Generate paragraph type details using Claude 3 Haiku.
   */
  protected function generateParagraphTypeDetails($componentName, $componentContent) {
    $prompt = "Based on this Next.js component named '{$componentName}', suggest a Drupal paragraph type
      structure using the suggest_paragraph_type function:\n\n{$componentContent}.
      The name of the paragraph should not include the word 'paragraph'.
      For fields, only lowercase alphanumeric characters and underscores are allowed,
      and only lowercase letters and underscore are allowed as the first character.
      Do not use the field type 'list_text' - the correct type is 'list_string'.
      Use only Drupal 10 valid field types. For images use the 'image' field type.";

    $tools = [
      [
        'name' => 'suggest_paragraph_type',
        'description' => "Suggests a Drupal paragraph type structure based on a Next.js component",
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
                ],
                'required' => ['name', 'label', 'type', 'sample_value'],
              ],
            ],
          ],
          'required' => ['id', 'name', 'description', 'fields'],
        ],
      ],
    ];

    return $this->anthropicApiService->callAnthropic($prompt, $tools, 'suggest_paragraph_type');
  }

}
