<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\drupalx_ai\Service\MockLandingPageService;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for creating mock landing pages.
 */
class CreateMockLandingCommands extends DrushCommands {

  /**
   * The mock landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\MockLandingPageService
   */
  protected $mockLandingPageService;

  /**
   * Constructs a new CreateMockLandingCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\MockLandingPageService $mock_landing_page
   *   The mock landing page service.
   */
  public function __construct(MockLandingPageService $mock_landing_page) {
    parent::__construct();
    $this->mockLandingPageService = $mock_landing_page;
  }

  /**
   * Creates a mock landing page.
   *
   * @command drupalx_ai:create-mock-landing
   * @aliases drupalx-ai-cml
   * @usage drupalx_ai:create-mock-landing
   *   Creates a new mock landing page and returns the edit URL.
   */
  public function createMockLanding() {
    $edit_url = $this->mockLandingPageService->createLandingNodeWithMockContent();
    $this->output()->writeln("Mock landing page created successfully.");
    $this->output()->writeln("Edit URL: $edit_url");
  }

}
