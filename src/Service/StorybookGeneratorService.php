<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalx_ai\Service\AnthropicApiService;

/**
 * Service for generating Storybook stories.
 */
class StorybookGeneratorService
{
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
   * Constructor for StorybookGeneratorService.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\AnthropicApiService $anthropic_api_service
   *   The Anthropic API service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AnthropicApiService $anthropic_api_service)
  {
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
  }

  /**
   * Generate a Storybook story for a given component.
   *
   * @param string $componentName
   *   The name of the component.
   * @param string $componentContent
   *   The content of the component file.
   *
   * @return string|null
   *   The generated Storybook story content, or null if generation failed.
   */
  public function generateStorybookStory($componentName, $componentContent)
  {
    $prompt = "Based on this Next.js component named '{$componentName}', use
    the generate_storybook_story function to generate a Storybook story in TypeScript:

{$componentContent}

Please create a Storybook story that demonstrates the component's usage, including different prop variations if applicable. Use the following example as a template for the structure and format of the story:

```typescript
import type { Meta, StoryObj } from '@storybook/react';
import {$componentName} from './{$componentName}';

const meta: Meta<typeof {$componentName}> = {
  title: 'Components/{$componentName}',
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
Import the default component object from the component file.";

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

    $result = $this->anthropicApiService->callAnthropic($prompt, $tools, 'generate_storybook_story');

    if (isset($result['story_content'])) {
      return $result['story_content'];
    }

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Storybook story for component: @component', ['@component' => $componentName]);
    return null;
  }
}
