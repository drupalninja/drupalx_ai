<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\ParagraphImporterService;
use Drupal\drupalx_ai\Service\AnthropicApiService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 *
 * @package Drupal\drupalx_ai\Commands
 */
class ImportParagraphTypeCommands extends DrushCommands
{

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
   */
  public function __construct(ConfigFactoryInterface $config_factory, ParagraphImporterService $paragraph_importer, LoggerChannelFactoryInterface $logger_factory, AnthropicApiService $anthropic_api_service)
  {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->paragraphImporter = $paragraph_importer;
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('drupalx_ai.paragraph_importer'),
      $container->get('logger.factory'),
      $container->get('drupalx_ai.anthropic_api')
    );
  }

  /**
   * Import a new paragraph type based on a Next.js component using AI.
   *
   * @command drupalx-ai:import-from-component
   * @aliases dai-ifc
   * @usage drush drupalx-ai:import-from-component
   */
  public function importParagraphTypeFromComponent(OutputInterface $output)
  {
    // Check if API key is set before proceeding.
    if (empty($this->configFactory->get('drupalx_ai.settings')->get('api_key'))) {
      $output->writeln("<error>Anthropic API key is not set. Please configure it in the DrupalX AI Settings before running this command.</error>");
      return;
    }

    // Prompt for component name.
    $componentName = $this->askComponent();

    // Read component file.
    $componentContent = $this->readComponentFile($componentName);

    if (!$componentContent) {
      $output->writeln("<error>Could not read component file. Please check the file exists and is readable.</error>");
      return;
    }

    // Generate paragraph type details using Claude 3 Haiku.
    $paragraphTypeDetails = $this->generateParagraphTypeDetails($componentContent);

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
   * Prompt the user for the component name.
   */
  protected function askComponent()
  {
    $componentDir = '../nextjs/components/';
    $components = scandir($componentDir);
    $components = array_filter(
      $components,
      function ($file) {
        return is_dir("../nextjs/components/$file") && $file != '.' && $file != '..';
      }
    );

    $selectedIndex = $this->io()->choice('Select a component to import', $components);
    return $components[$selectedIndex];
  }

  /**
   * Read the component file.
   */
  protected function readComponentFile($componentName)
  {
    $componentPath = "../nextjs/components/{$componentName}";
    if (!is_dir($componentPath)) {
      return FALSE;
    }

    $componentFiles = array_filter(
      scandir($componentPath),
      function ($file) {
        return pathinfo($file, PATHINFO_EXTENSION) === 'tsx' && !preg_match('/\.stories\.tsx$/', $file);
      }
    );

    if (empty($componentFiles)) {
      $this->loggerFactory->get('drupalx_ai')->warning("No suitable .tsx files found in the {$componentName} component directory.");
      return FALSE;
    }

    $selectedFile = $this->io()->choice(
      "Select a file from the {$componentName} component",
      array_combine($componentFiles, $componentFiles)
    );

    $filePath = "{$componentPath}/{$selectedFile}";
    if (!file_exists($filePath) || !is_readable($filePath)) {
      $this->loggerFactory->get('drupalx_ai')->error("Unable to read the selected file: {$filePath}");
      return FALSE;
    }

    return file_get_contents($filePath);
  }

  /**
   * Generate paragraph type details using Claude 3 Haiku.
   */
  protected function generateParagraphTypeDetails($componentContent)
  {
    $prompt = "Based on this Next.js component, suggest a Drupal paragraph type
      structure using the suggest_paragraph_type function:\n\n{$componentContent}.
      The name of the paragraph should not include the word 'paragraph'.
      For fields, only lowercase alphanumeric characters and underscores are allowed,
      and only lowercase letters and underscore are allowed as the first character
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
