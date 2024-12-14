<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructor for StorybookGeneratorService.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    AiModelApiService $ai_model_api_service,
    ConfigFactoryInterface $config_factory
  ) {
    $this->loggerFactory = $logger_factory;
    $this->aiModelApiService = $ai_model_api_service;
    $this->configFactory = $config_factory;
  }

  /**
   * Generate a Storybook story for a given component.
   */
  public function generateStorybookStory($componentName, $componentContent, $category) {
    $config = $this->configFactory->get('drupalx_ai.settings');
    $is_nextjs = $config->get('is_nextjs');

    // Determine if the component is a Twig template
    $isTwig = !$is_nextjs || str_contains($componentContent, '.twig');

    // Capitalize the component name for the story title
    $capitalizedName = ucfirst($componentName);

    if ($isTwig) {
      return $this->generateTwigStory($componentName, $capitalizedName, $componentContent, $category);
    }
    else {
      return $this->generateNextJsStory($componentName, $capitalizedName, $componentContent, $category);
    }
  }

  /**
   * Generate a Storybook story for a Twig component.
   */
  protected function generateTwigStory($componentName, $capitalizedName, $componentContent, $category) {
    $prompt = "Based on this Twig component named '{$capitalizedName}' in the '{$category}' category, generate
    a Storybook story that uses the Drupal HTML format. The component content is:

    {$componentContent}

    Please create a Storybook story that demonstrates the component's usage with proper Twig template integration.
    The story should:
    1. Import the Twig template with the original (non-capitalized) component name
    2. Define meaningful argTypes for all component variables
    3. Include a renderComponent function that passes args to the template
    4. Create multiple component variants as named exports
    5. Follow this structure, making sure the title uses the capitalized name:

    ```javascript
    import template from './{$componentName}.twig';

    export default {
      title: '{$category}/{$capitalizedName}',
      argTypes: {
        // Define controls for Twig variables
      },
    };

    const renderComponent = (args) => {
      return template({
        // Pass args to template
      });
    };

    export const Default = {
      render: renderComponent,
      args: {
        // Default properties
      },
    };
    ```

    Include multiple variants based on the component's parameters and possible states.";

    $tools = [
      [
        'name' => 'generate_storybook_story',
        'description' => "Generates a Storybook story for a Twig component",
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'story_content' => [
              'type' => 'string',
              'description' => 'The content of the Storybook story',
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

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Twig Storybook story for component: @component', [
      '@component' => $capitalizedName,
    ]);
    return NULL;
  }

  /**
   * Generate a Storybook story for a Next.js component.
   */
  protected function generateNextJsStory($componentName, $capitalizedName, $componentContent, $category) {
    $prompt = "Based on this Next.js component named '{$capitalizedName}' in the '{$category}' category, generate
    a Storybook story in TypeScript. The component content is:

    {$componentContent}

    Please create a Storybook story that follows the Next.js/React TypeScript format, making sure to use the capitalized name in the title:

    ```typescript
    import type { Meta, StoryObj } from '@storybook/react';
    import {$componentName} from './{$componentName}';

    const meta: Meta<typeof {$componentName}> = {
      title: '{$category}/{$capitalizedName}',
      component: {$componentName},
      argTypes: {
        // Define TypeScript-aware argTypes
      },
    };

    export default meta;
    type Story = StoryObj<typeof {$componentName}>;

    export const Default: Story = {
      args: {
        // Define TypeScript-compatible args
      },
    };
    ```";

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

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Next.js Storybook story for component: @component', [
      '@component' => $capitalizedName,
    ]);
    return NULL;
  }

}
