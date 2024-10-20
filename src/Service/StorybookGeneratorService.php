<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for generating Storybook stories.
 */
class StorybookGeneratorService {
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
   * Constructor for StorybookGeneratorService.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\AiModelApiService $ai_model_api_service
   *   The AI Model API service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AiModelApiService $ai_model_api_service) {
    $this->loggerFactory = $logger_factory;
    $this->aiModelApiService = $ai_model_api_service;
  }

  /**
   * Generate a Storybook story for a given component.
   *
   * @param string $componentName
   *   The name of the component.
   * @param string $componentContent
   *   The content of the component file.
   * @param string $category
   *   The category of the component.
   *
   * @return string|null
   *   The generated Storybook story content, or null if generation failed.
   */
  public function generateStorybookStory($componentName, $componentContent, $category) {
    $prompt = "Based on this Next.js component named '{$componentName}' in the '{$category}' category, use
    the generate_storybook_story function to generate a Storybook story in TypeScript:

    {$componentContent}

    Please create a Storybook story that demonstrates the component's usage, including different prop variations if applicable. Use the following example as a template for the structure and format of the story:

    ```typescript
    import type { Meta, StoryObj } from '@storybook/react';
    import {$componentName} from './{$componentName}';

    const meta: Meta<typeof {$componentName}> = {
      title: '{$category}/{$componentName}',
      component: {$componentName},
      argTypes: {
        // Define argTypes based on the component's props
      },
    };

    export default meta;
    type Story = StoryObj<typeof {$componentName}>;

    export const Default: Story = {
      args: {
        // Define default args
      },
    };
    ```
    Import the default component object from the component file. Ensure that the story reflects the '{$category}' category in its structure and content where appropriate.";

    $tools = [
      [
        'name' => 'generate_storybook_story',
        'description' => "Generates a TypeScript Storybook story for a Next.js component",
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'story_content' => [
              'type' => 'string',
              'description' => 'The content of the TypeScript Storybook story',
            ],
          ],
          'required' => ['story_content'],
        ],
      ],
    ];

    $result = $this->aiModelApiService->callAiApi($prompt, $tools, 'generate_storybook_story');

    if (isset($result['story_content'])) {
      return $result['story_content'];
    }

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Storybook story for component: @component in category: @category', [
      '@component' => $componentName,
      '@category' => $category,
    ]);
    return NULL;
  }

}
