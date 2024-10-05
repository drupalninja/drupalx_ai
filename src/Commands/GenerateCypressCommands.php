<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\AnthropicApiService;
use Drupal\drupalx_ai\Service\ComponentReaderService;
use Drupal\drupalx_ai\Service\CypressGeneratorService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 *
 * @package Drupal\drupalx_ai\Commands
 */
class GenerateCypressCommands extends DrushCommands {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * The Cypress generator service.
   *
   * @var \Drupal\drupalx_ai\Service\CypressGeneratorService
   */
  protected $cypressGenerator;

  /**
   * Constructor for GenerateCypressCommands.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropic_api_service
   *   The Anthropic API service.
   * @param \Drupal\drupalx_ai\Service\ComponentReaderService $component_reader
   *   The component reader service.
   * @param \Drupal\drupalx_ai\Service\CypressGeneratorService $cypress_generator
   *   The Cypress generator service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AnthropicApiService $anthropic_api_service,
    ComponentReaderService $component_reader,
    CypressGeneratorService $cypress_generator,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
    $this->componentReader = $component_reader;
    $this->cypressGenerator = $cypress_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('drupalx_ai.anthropic_api'),
      $container->get('drupalx_ai.component_reader'),
      $container->get('drupalx_ai.cypress_generator')
    );
  }

  /**
   * Generate a Cypress test for a Next.js component.
   *
   * @command drupalx-ai:generate-cypress
   * @aliases dai-gc
   * @usage drush drupalx-ai:generate-cypress
   */
  public function generateCypressTest(OutputInterface $output) {
    // Check if API key is set before proceeding.
    if (empty($this->configFactory->get('drupalx_ai.settings')->get('api_key'))) {
      $output->writeln("<error>Anthropic API key is not set. Please configure it in the DrupalX AI Settings before running this command.</error>");
      return;
    }

    // Use the ComponentReaderService to get the component and story files.
    $componentFolderName = $this->componentReader->askComponentFolder($this->io());
    [$componentName, $componentContent, $storyContent] = $this->componentReader->readComponentFiles($componentFolderName, $this->io());

    if (!$componentContent) {
      $output->writeln("<error>Could not read component file. Please check the file exists and is readable.</error>");
      return;
    }

    // Generate Cypress test.
    $cypressContent = $this->cypressGenerator->generateCypressTest($componentFolderName, $componentName, $componentContent, $storyContent);

    if (!$cypressContent) {
      $output->writeln("<error>Failed to generate Cypress test for the component.</error>");
      return;
    }

    // Write the Cypress test to a file in the same folder as the component.
    $cypressFileName = $componentName . '.cy.js';
    $cypressFilePath = "../nextjs/components/{$componentFolderName}/{$cypressFileName}";

    if (file_put_contents($cypressFilePath, $cypressContent) === FALSE) {
      $output->writeln("<error>Failed to write Cypress test to file: {$cypressFilePath}</error>");
      return;
    }

    $output->writeln("<info>Successfully generated Cypress test: {$cypressFilePath}</info>");
  }

}
