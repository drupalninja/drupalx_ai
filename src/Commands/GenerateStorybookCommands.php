<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupalx_ai\Service\AiModelApiService;
use Drupal\drupalx_ai\Service\ComponentReaderService;
use Drupal\drupalx_ai\Service\StorybookGeneratorService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 *
 * @package Drupal\drupalx_ai\Commands
 */
class GenerateStorybookCommands extends DrushCommands {

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
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

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
   * The Storybook generator service.
   *
   * @var \Drupal\drupalx_ai\Service\StorybookGeneratorService
   */
  protected $storybookGenerator;

  /**
   * Constructor for GenerateStorybookCommands.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   * @param \Drupal\drupalx_ai\Service\AiModelApiService $ai_model_api_service
   *   The AI Model API service.
   * @param \Drupal\drupalx_ai\Service\ComponentReaderService $component_reader
   *   The component reader service.
   * @param \Drupal\drupalx_ai\Service\StorybookGeneratorService $storybook_generator
   *   The Storybook generator service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ThemeManagerInterface $theme_manager,
    AiModelApiService $ai_model_api_service,
    ComponentReaderService $component_reader,
    StorybookGeneratorService $storybook_generator,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->themeManager = $theme_manager;
    $this->aiModelApiService = $ai_model_api_service;
    $this->componentReader = $component_reader;
    $this->storybookGenerator = $storybook_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('theme.manager'),
      $container->get('drupalx_ai.ai_model_api'),
      $container->get('drupalx_ai.component_reader'),
      $container->get('drupalx_ai.storybook_generator')
    );
  }

  /**
   * Generate a Storybook story for a component.
   *
   * @command drupalx-ai:generate-storybook
   * @aliases dxstory
   * @usage drush drupalx-ai:generate-storybook
   */
  public function generateStorybookStory(OutputInterface $output) {
    // Check if API key is set before proceeding.
    if (empty($this->configFactory->get('drupalx_ai.settings')->get('api_key'))) {
      $output->writeln("<error>AI API key is not set. Please configure it in the DrupalX AI Settings before running this command.</error>");
      return;
    }

    // Get config settings
    $config = $this->configFactory->get('drupalx_ai.settings');
    $is_nextjs = $config->get('is_nextjs');

    // Use the ComponentReaderService to get the component.
    $componentFolderName = $this->componentReader->askComponentFolder($this->io());
    [$componentName, $componentContent] = $this->componentReader->readComponentFiles($componentFolderName, $this->io());

    if (!$componentContent) {
      $output->writeln("<error>Could not read component file. Please check the file exists and is readable.</error>");
      return;
    }

    // Prompt for component category.
    $category = $this->io()->choice(
      'Select the category for the Storybook component:',
      ['General', 'Editorial', 'Navigation', 'Messages'],
      'General'
    );

    // Generate Storybook story.
    $storyContent = $this->storybookGenerator->generateStorybookStory($componentName, $componentContent, $category);

    if (!$storyContent) {
      $output->writeln("<error>Failed to generate Storybook story for the component.</error>");
      return;
    }

    // Determine the correct path and file extension based on the is_nextjs setting.
    if ($is_nextjs) {
      $basePath = '../nextjs/components/';
      $fileExtension = '.stories.tsx';
    }
    else {
      $activeTheme = $this->themeManager->getActiveTheme();
      $themePath = $activeTheme->getPath();
      $basePath = $themePath . '/components/';
      $fileExtension = '.stories.js';
    }

    // Ensure the component directory exists.
    $componentDir = $basePath . $componentFolderName;
    if (!is_dir($componentDir)) {
      mkdir($componentDir, 0777, TRUE);
    }

    // Write the story to a file.
    $storyFileName = $componentName . $fileExtension;
    $storyFilePath = $componentDir . '/' . $storyFileName;

    if (file_put_contents($storyFilePath, $storyContent) === FALSE) {
      $output->writeln("<error>Failed to write Storybook story to file: {$storyFilePath}</error>");
      return;
    }

    $output->writeln("<info>Successfully generated Storybook story: {$storyFilePath}</info>");
  }

}
