<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for generating Cypress tests.
 */
class CypressGeneratorService
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
   * Constructor for CypressGeneratorService.
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
   * Generate a Cypress test for a given component.
   *
   * @param string $componentName
   *   The name of the component.
   * @param string $componentContent
   *   The content of the component file.
   * @param string|false $storyContent
   *   The content of the story file, or FALSE if not available.
   *
   * @return string|null
   *   The generated Cypress test content, or null if generation failed.
   */
  public function generateCypressTest($componentName, $componentContent, $storyContent)
  {
    $prompt = "Based on this Next.js component named '{$componentName}' and its associated Storybook story, use
    the generate_cypress_test function to generate a Cypress test:

Component Content:
{$componentContent}

" . ($storyContent !== FALSE ? "Storybook Story Content:
{$storyContent}

" : "No Storybook story content available.

") . "Please create a Cypress test that thoroughly tests the component's functionality, including different prop variations if applicable. The test should cover the scenarios described in the Storybook story if available. The test file will be located in the same folder as the component.

Use the following example as a template for the structure and format of the test:

```javascript
describe('Accordion Component', () => {
  beforeEach(() => {
    cy.visit('/iframe.html?args=&id=editorial-accordion--default&viewMode=story');
  });

  it('should display the accordion container', () => {
    cy.get('.bg-gray-100').should('exist');
    cy.get('.container').should('exist');
  });

  it('should display the title if provided', () => {
    cy.get('h2').should('exist');
  });

  it('should have collapsed accordion items initially', () => {
    cy.get('[data-state=\"closed\"]').should('exist');
    cy.get('[data-state=\"open\"]').should('not.exist');
  });

  it('should expand and collapse an accordion item when clicked', () => {
    cy.get('[data-state=\"closed\"]').first().click();
    cy.get('[data-state=\"open\"]').should('exist');

    cy.get('[data-state=\"open\"]').first().click();
    cy.get('[data-state=\"closed\"]').should('exist');
  });

  it('should display accordion item content when expanded', () => {
    cy.get('[data-state=\"closed\"]').first().click();
    cy.get('[data-state=\"open\"] .accordion-content').should('be.visible');
  });

  it('should display a button in the accordion content if a link is provided', () => {
    cy.get('[data-state=\"closed\"]').first().click();
    cy.get('[data-state=\"open\"] .accordion-content a').should('exist');
  });

  context('Responsive Design', () => {
    const viewports = [
      { width: 320, height: 568 }, // Mobile
      { width: 768, height: 1024 }, // Tablet
      { width: 1024, height: 768 }, // Laptop
      { width: 1920, height: 1080 }, // Desktop
    ];

    viewports.forEach(({ width, height }) => {
      it(`should render correctly on \${width}x\${height} resolution`, () => {
        cy.viewport(width, height);
        cy.get('.bg-gray-100').should('be.visible');
        cy.get('.container').should('be.visible');
        cy.get('.accordion-item').should('be.visible');
      });
    });
  });
});

```

Ensure that the test covers all major functionality of the component, including any interactive elements or state changes. Use TypeScript for type safety where applicable. If Storybook story content is available, create test cases that correspond to the different stories or scenarios described in the Storybook file.";

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
      return $result['test_content'];
    }

    $this->loggerFactory->get('drupalx_ai')->error('Failed to generate Cypress test for component: @component', [
      '@component' => $componentName,
    ]);
    return NULL;
  }

}
