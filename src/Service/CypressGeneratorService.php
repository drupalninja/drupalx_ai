<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for generating Cypress tests.
 */
class CypressGeneratorService {

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
   * Constructor for CypressGeneratorService.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AiModelApiService $ai_model_api_service) {
    $this->loggerFactory = $logger_factory;
    $this->aiModelApiService = $ai_model_api_service;
  }

  /**
   * Extract classes from the component content.
   */
  private function extractClasses($componentContent) {
    // Match both className="..." and class="..." patterns.
    $patterns = [
      '/className="([^"]+)"/',
      '/class="([^"]+)"/',
    ];

    $allClasses = [];
    foreach ($patterns as $pattern) {
      preg_match_all($pattern, $componentContent, $matches);
      if (!empty($matches[1])) {
        foreach ($matches[1] as $classString) {
          // Split space-separated classes and add to array.
          $classes = preg_split('/\s+/', trim($classString));
          $allClasses = array_merge($allClasses, $classes);
        }
      }
    }

    // Filter out empty values and duplicates.
    return array_unique(array_filter($allClasses));
  }

  /**
   * Generate a Cypress test for a given component.
   */
  public function generateCypressTest($componentFolderName, $componentName, $componentContent, $storyContent) {
    $existingClasses = $this->extractClasses($componentContent);

    // Validate that we actually found some classes.
    if (empty($existingClasses)) {
      $this->loggerFactory->get('drupalx_ai')->warning('No classes found in component: @component', [
        '@component' => $componentName,
      ]);
      return NULL;
    }

    $classesString = implode(', ', array_map(function ($class) {
      return '.' . $class;
    }, $existingClasses));

    $category = $this->extractCategoryFromStory($storyContent);

    $prompt = "Based on this component named '{$componentName}' and its associated Storybook story, generate a Cypress test:

    Component Content:
    {$componentContent}

    " . ($storyContent !== FALSE ? "Storybook Story Content:
    {$storyContent}

    " : "No Storybook story content available.

    ") . "Create a Cypress test that confirms the existence of key elements in the component using class-based selectors.
    The test should primarily use .exist() assertions to verify the presence of elements.

    CRITICAL: You MUST ONLY use the following valid classes in your selectors:
    {$classesString}

    Each selector MUST include at least one class from the above list.
    DO NOT generate empty selectors like '.'.
    DO NOT use any classes that are not in the above list.
    DO NOT use any HTML tags or attributes as selectors.

    Use this exact format for the test structure:

    ```javascript
    describe('{$componentName} Component', () => {
      beforeEach(() => {
        cy.visit('/iframe.html?args=&id={$category}-{$componentFolderName}--default&viewMode=story');
      });

      it('should contain all expected elements', () => {
        // Each selector must use one or more classes from the provided list
        cy.get('.specific-class').should('exist');
      });
    });
    ```";

    $tools = [
      [
        'name' => 'generate_cypress_test',
        'description' => "Generates a Cypress test for a component",
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

    $result = $this->aiModelApiService->callAiApi($prompt, $tools, 'generate_cypress_test');

    if (!isset($result['test_content'])) {
      $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Cypress test for component: @component', [
        '@component' => $componentName,
      ]);
      return NULL;
    }

    // Validate the generated test.
    $validatedContent = $this->validateAndCleanTest($result['test_content'], $existingClasses);

    // Additional validation to ensure we're not returning a test with empty selectors.
    if (strpos($validatedContent, "cy.get('.')") !== false || strpos($validatedContent, 'cy.get(".")') !== false) {
      $this->loggerFactory->get('drupalx_ai')->error('Generated test contains invalid empty selectors for component: @component', [
        '@component' => $componentName,
      ]);
      return NULL;
    }

    return $validatedContent;
  }

  /**
   * Validate and clean the generated test content.
   */
  private function validateAndCleanTest($testContent, array $allowedClasses) {
    $lines = explode("\n", $testContent);
    $cleanedLines = [];
    $hasValidSelectors = false;

    foreach ($lines as $line) {
      if (strpos($line, 'cy.get(') !== FALSE) {
        preg_match('/cy\.get\([\'"](.+?)[\'"]\)/', $line, $matches);
        if (!empty($matches[1])) {
          $selector = $matches[1];
          $cleanedSelector = $this->cleanSelector($selector, $allowedClasses);

          // Skip lines with invalid selectors.
          if ($cleanedSelector === '.' || empty($cleanedSelector)) {
            continue;
          }

          $line = str_replace($matches[1], $cleanedSelector, $line);
          $hasValidSelectors = TRUE;
        }
      }
      $cleanedLines[] = $line;
    }

    // Only return the test if it contains valid selectors.
    return $hasValidSelectors ? implode("\n", $cleanedLines) : NULL;
  }

  /**
   * Clean a selector based on allowed classes.
   */
  private function cleanSelector(string $selector, array $allowed_classes): string {
    // Remove any leading dots and split by remaining dots.
    $selector = ltrim($selector, '.');
    $parts = explode('.', $selector);
    $cleaned_parts = [];

    foreach ($parts as $part) {
      $part = trim($part);
      if (empty($part)) {
        continue;
      }
      if (in_array($part, $allowed_classes, TRUE)) {
        $cleaned_parts[] = $part;
      }
    }

    // Return null or empty string if no valid classes found.
    if (empty($cleaned_parts)) {
      return '';
    }

    return '.' . implode('.', $cleaned_parts);
  }

  /**
   * Extract the category from the story content.
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
