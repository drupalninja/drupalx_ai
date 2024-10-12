<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\drupalx_ai\Service\AiLandingPageService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for creating AI-generated landing pages using Anthropic API.
 */
class AiLandingPageCommands extends DrushCommands {
  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * Constructs a new AiLandingPageCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\AiLandingPageService $ai_landing_page_service
   *   The AI landing page service.
   */
  public function __construct(AiLandingPageService $ai_landing_page_service) {
    parent::__construct();
    $this->aiLandingPageService = $ai_landing_page_service;
  }

  /**
   * Creates an AI-generated landing page using Anthropic API.
   *
   * @command drupalx:create-ai-landing-page
   * @aliases dxail
   */
  public function createAiLandingPage() {
    $description = $this->io()->ask('Please provide a description of the landing page content you want to generate:');

    $data = $this->aiLandingPageService->generateAiContent($description);

    if ($data) {
      $nodeUrl = $this->aiLandingPageService->createLandingNodeWithAiContent($data['page_title'], $data['paragraphs']);
      $this->io()->success("AI-generated landing page created successfully. Edit URL: $nodeUrl");
    }
    else {
      $this->io()->error('Failed to generate AI landing page content.');
    }
  }

}
