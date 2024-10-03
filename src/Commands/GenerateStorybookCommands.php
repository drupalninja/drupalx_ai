<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\AnthropicApiService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Drush commandfile for generating Storybook stories.
 *
 * @package Drupal\drupalx_ai\Commands
 */
class GenerateStorybookCommands extends DrushCommands
{

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
   * Constructor for GenerateStorybookCommands.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropic_api_service
   *   The Anthropic API service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, AnthropicApiService $anthropic_api_service)
  {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
  }

  /**
   * Generate a Storybook story for a Next.js component.
   *
   * @command drupalx-ai:generate-storybook
   * @aliases dai-gs
   * @usage drush drupalx-ai:generate-storybook
   */
  public function generateStorybookStory(OutputInterface $output)
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

    // Generate Storybook story using Claude 3 Haiku.
    $storybookStory = $this->generateStorybookStoryContent($componentContent, $componentName);

    if (!$storybookStory) {
      $output->writeln("<error>Failed to generate Storybook story for the component.</error>");
      return;
    }

    // Display generated story and ask for confirmation.
    $output->writeln("<info>Generated Storybook Story:</info>");
    $output->writeln($storybookStory);

    if (!$this->io()->confirm('Do you want to save this Storybook story?', TRUE)) {
      $output->writeln('Story generation cancelled.');
      return;
    }

    // Save the Storybook story.
    $this->saveStorybookStory($componentName, $storybookStory);
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

    $selectedIndex = $this->io()->choice('Select a component to generate a Storybook story for', $components);
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
   * Generate Storybook story content using Claude 3 Haiku.
   */
  protected function generateStorybookStoryContent($componentContent, $componentName)
  {
    $prompt = "Based on this Next.js component, generate a Storybook story:
      \n\n{$componentContent}\n\n
      Create a Storybook story for this component named '{$componentName}.stories.tsx'.
      Include a default export with title and component.
      Create at least two story exports: a default story and a variant.
      Use the CSF 3.0 format.
      Include any necessary imports.
      If the component uses props, create ArgTypes for them.
      Import the default object from component which will live in the same directory.

      The following is a full example of what this might look like using the 'Alerts'
      component as an example.

      import type { Meta, StoryObj } from '@storybook/react';
      import Alerts from './Alerts';

      const meta: Meta<typeof Alerts> = {
        title: 'General/Alerts',
        component: Alerts,
        argTypes: {
          type: {
            control: 'select',
            options: ['default', 'destructive'],
          },
          title: { control: 'text' },
          onDismiss: { action: 'dismissed' },
        },
      };

      export default meta;
      type Story = StoryObj<typeof Alerts>;

      export const Default: Story = {
        args: {
          type: 'default',
          children: 'This is a default alert.',
        },
      };

      export const Destructive: Story = {
        args: {
          type: 'destructive',
          children: 'This is a destructive alert.',
        },
      };

      export const WithTitle: Story = {
        args: {
          type: 'default',
          title: 'Alert Title',
          children: 'This is an alert with a title.',
        },
      };

      export const Dismissible: Story = {
        args: {
          type: 'default',
          children: 'This is a dismissible alert.',
          onDismiss: () => console.log('Alert dismissed'),
        },
      };

      export const LongContent: Story = {
        args: {
          type: 'default',
          title: 'Long Content Alert',
          children: 'This alert has a longer content to demonstrate how the component handles multiple lines of text. It should wrap properly and maintain good readability.',
        },
      };

      export const DestructiveWithTitle: Story = {
        args: {
          type: 'destructive',
          title: 'Warning',
          children: 'This is a destructive alert with a title.',
        },
      };";

    $tools = [
      [
        'name' => 'generate_storybook_story',
        'description' => "Generates a Storybook story for a Next.js component",
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'story_content' => [
              'type' => 'string',
              'description' => 'The complete content of the Storybook story file',
            ],
          ],
          'required' => ['story_content'],
        ],
      ],
    ];

    return $this->anthropicApiService->callAnthropic($prompt, $tools, 'generate_storybook_story');
  }

  /**
   * Save the generated Storybook story.
   */
  protected function saveStorybookStory($componentName, $storyContent)
  {
    $componentDir = "../nextjs/components/{$componentName}";
    $storyFileName = "{$componentName}.stories.tsx";
    $storyFilePath = "{$componentDir}/{$storyFileName}";

    if (file_put_contents($storyFilePath, $storyContent)) {
      $this->output()->writeln("<info>Successfully saved Storybook story to {$storyFilePath}</info>");
    } else {
      $this->output()->writeln("<error>Failed to save Storybook story. Please check file permissions.</error>");
    }
  }
}
