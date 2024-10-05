<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for generating Cypress tests using only class-based selectors and .exist() assertions.
 */
class CypressGeneratorService {

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
   * Constructor for CypressGeneratorService.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AnthropicApiService $anthropic_api_service) {
    $this->loggerFactory = $logger_factory;
    $this->anthropicApiService = $anthropic_api_service;
  }

  /**
   * Generate a Cypress test for a given component.
   */
  public function generateCypressTest($componentFolderName, $componentName, $componentContent, $storyContent) {
    $existingClasses = $this->extractClasses($componentContent);

    $classesString = implode(', ', array_map(function ($class) {
      return '.' . $class;
    }, $existingClasses));

    // Extract category from story content
    $category = $this->extractCategoryFromStory($storyContent);

    $prompt = "Based on this Next.js component named '{$componentName}' and its associated Storybook story, generate a Cypress test:

    Component Content:
    {$componentContent}

    " . ($storyContent !== FALSE ? "Storybook Story Content:
    {$storyContent}

    " : "No Storybook story content available.

    ") . "Create a Cypress test that confirms the existence of key elements in the component using class-based selectors.
    The test should primarily use .exist() assertions to verify the presence of elements.

    CRITICAL: You MUST ONLY use the following classes in your Cypress test selectors. These are the ONLY classes that exist in the component:
    Classes: {$classesString}

    DO NOT use any classes that are not in the above list. DO NOT use any HTML tags or attributes as selectors.

    Use the following example as a template for the structure and format of the test:

    ```javascript
    describe('{$componentName} Component', () => {
      beforeEach(() => {
        cy.visit('/iframe.html?args=&id={$category}-{$componentFolderName}--default&viewMode=story');
      });

      it('should contain all expected elements', () => {
        cy.get('.some-class').should('exist');
        cy.get('.another-class').should('exist');
        // Add more .exist() checks for other important classes
      });

      // You can add a few more test cases if absolutely necessary, but keep it minimal
    });
    ```

    Focus on verifying the existence of key elements using the available classes.
    Avoid complex interactions or state checks unless absolutely critical to the component's functionality.
    Remember: ONLY use classes that exist in the component content provided.
    Skip hover classes which only render when hovering.
    Keep the tests simple and primarily focused on .exist() assertions.";

    $tools = [
      [
        'name' => 'generate_cypress_test',
        'description' => "Generates a Cypress test for a Next.js component",
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'test_content' => [
              'type' => 'string',
              'description' => 'The content of the Cypress test',
            ],
          ],
          'required' => ['test_content'],
        ],
      ],
    ];

    $result = $this->anthropicApiService->callAnthropic($prompt, $tools, 'generate_cypress_test');

    if (isset($result['test_content'])) {
      // Validate the generated test.
      $validatedContent = $this->validateAndCleanTest($result['test_content'], $existingClasses);
      return $validatedContent;
    }

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Cypress test for component: @component', [
      '@component' => $componentName,
    ]);
    return NULL;
  }

  /**
   * Extract classes from the component content.
   *
   * @param string $componentContent
   *   The content of the component file.
   *
   * @return array
   *   An array of extracted classes.
   */
  private function extractClasses($componentContent) {
    preg_match_all('/className="([^"]+)"/', $componentContent, $matches);
    return array_unique(explode(' ', implode(' ', $matches[1])));
  }

  /**
   * Validate and clean the generated test content.
   *
   * @param string $testContent
   *   The generated test content.
   * @param array $allowedClasses
   *   An array of allowed classes.
   *
   * @return string
   *   The validated and cleaned test content.
   */
  private function validateAndCleanTest($testContent, array $allowedClasses) {
    $lines = explode("\n", $testContent);
    $cleanedLines = [];

    foreach ($lines as $line) {
      if (strpos($line, 'cy.get(') !== FALSE) {
        preg_match('/cy\.get\([\'"](.+?)[\'"]\)/', $line, $matches);
        if (!empty($matches[1])) {
          $selector = $matches[1];
          $cleanedSelector = $this->cleanSelector($selector, $allowedClasses);
          $line = str_replace($matches[1], $cleanedSelector, $line);
        }
      }
      $cleanedLines[] = $line;
    }

    return implode("\n", $cleanedLines);
  }

  /**
   * Clean a selector based on allowed classes.
   *
   * @param string $selector
   *   The selector to clean.
   * @param array $allowed_classes
   *   An array of allowed class names.
   *
   * @return string
   *   The cleaned selector.
   */
  private function cleanSelector(string $selector, array $allowed_classes): string {
    $parts = explode('.', $selector);
    $cleaned_parts = [];

    foreach ($parts as $part) {
      if (empty($part)) {
        continue;
      }
      if (in_array($part, $allowed_classes, TRUE)) {
        $cleaned_parts[] = $part;
      }
    }

    return empty($cleaned_parts) ? '.' . reset($allowed_classes) : '.' . implode('.', $cleaned_parts);
  }

  /**
   * Extract the category from the story content.
   *
   * @param string $storyContent
   *   The content of the Storybook story.
   *
   * @return string
   *   The extracted category.
   */
  private function extractCategoryFromStory($storyContent) {
    if ($storyContent === FALSE) {
      return 'general';
    }

    if (preg_match('/title:\s*[\'"]([^\'"]*)\//', $storyContent, $matches)) {
      return strtolower(str_replace(' ', '-', $matches[1]));
    }

    return 'general';
  }

}
