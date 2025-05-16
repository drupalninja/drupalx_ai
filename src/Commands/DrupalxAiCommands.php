<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupalx_ai\Service\AIService;
use Drupal\drupalx_ai\Service\ParagraphService;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;
use Drush\Commands\DrushCommands;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * A Drush commandfile for DrupalX AI.
 */
class DrupalxAiCommands extends DrushCommands {

  /**
   * The AI service.
   *
   * @var \Drupal\drupalx_ai\Service\AIService
   */
  protected AIService $aiService;

  /**
   * The paragraph service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphService
   */
  protected ParagraphService $paragraphService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The DrupalX AI logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $drupalxAiLogger;

  /**
   * Constructs a DrupalxAiCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\AIService $ai_service
   *   The AI service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\drupalx_ai\Service\ParagraphService $paragraph_service
   *   The paragraph service.
   */
  public function __construct(
    AIService $ai_service,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    ParagraphService $paragraph_service
  ) {
    parent::__construct();
    $this->aiService = $ai_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->drupalxAiLogger = $logger_factory->get('drupalx_ai');
    $this->paragraphService = $paragraph_service;
  }

  /**
   * Generates a landing page using AI based on a description.
   *
   * @param string $description
   *   The description of the page to generate.
   * @param array $options
   *   An associative array of options.
   *   - uid: The user ID to assign as the author of the page. Defaults to the current Drush user or user 1.
   *
   * @command drupalx_ai:generate-page
   * @aliases dxp
   *
   * @option uid The user ID to assign as the author of the page. Defaults to the current Drush user or user 1.
   * @usage drupalx_ai:generate-page "Create a page about sustainable energy solutions for urban environments."
   * @usage dxp "A promotional page for a new tech startup focused on AI-driven analytics." --uid=1
   */
  public function generatePage(string $description, array $options = ['uid' => NULL]): void {
    // Revert to debug, as command entry is confirmed.
    $this->drupalxAiLogger->debug('DrupalxAiCommands: Entered generatePage method with description: "@desc"', ['@desc' => $description]);

    $ai_response = $this->aiService->getComponents($description);

    if (!empty($ai_response['error'])) {
      $this->logger()->error(
        'Error from AIService: @error. Raw: @raw',
        [
          '@error' => $ai_response['error'],
          '@raw' => $ai_response['raw_response'] ?? 'N/A',
        ]
      );
      $this->drupalxAiLogger->error('AIService failed: @error', ['@error' => $ai_response['error']]);
      return;
    }

    $page_title = $ai_response['title'] ?? 'AI Generated Page: ' . substr($description, 0, 50);
    $components = $ai_response['components'] ?? [];

    if (empty($components)) {
      $this->drupalxAiLogger->warning(
        'No components returned by AI for description: "@desc". Title suggested was "@title".',
        [
          '@desc' => $description,
          '@title' => $page_title,
        ]
      );
      return;
    }

    $uid = $options['uid'];
    if ($uid !== NULL) {
      $user = User::load($uid);
      if (!$user) {
        $this->logger()->error('Invalid user ID provided: @uid. Page will be created by user 1.', ['@uid' => $uid]);
        $uid = 1;
      }
    }
    else {
      // Default to current Drush user, or fallback to user 1 (admin).
      $uid = $this->currentUser->id() ?: 1;
    }

    try {
      $node = Node::create([
        'type' => 'landing',
        'title' => $page_title,
        'uid' => $uid,
        'status' => Node::PUBLISHED,
      ]);
      // Set field_hide_page_title to TRUE for 'landing' nodes.
      if ($node->bundle() === 'landing' && $node->hasField('field_hide_page_title')) {
        $node->set('field_hide_page_title', TRUE);
      }
      $node->save();

      $result = $this->paragraphService->saveEntitiesToNode($node->id(), $components);

      if (isset($result['error'])) {
        $this->logger()->error(
          'Error saving entities to node @nid: @error',
          [
            '@nid' => $node->id(),
            '@error' => $result['error'],
          ]
        );
        $this->drupalxAiLogger->error(
          'Error saving paragraphs to node @nid: @error',
          [
            '@nid' => $node->id(),
            '@error' => $result['error'],
          ]
        );
      }
      else {
        // Define color and icon for this log message.
        $color_green = "\033[0;32m";
        $icon_success = "✅";
        $color_reset = "\033[0m";

        $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['absolute' => TRUE])->toString();
        $this->output()->writeln(dt($color_green . $icon_success . ' Successfully created page "@title" (NID: @nid).' . $color_reset, [
          '@title' => $page_title,
          '@nid' => $node->id(),
        ]));
        // It might be best to leave the "Edit at:" URL without color/icon to keep it clean for copying.
        $this->output()->writeln(dt('Edit at: @url', [
          '@url' => $edit_url,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error(
        'Error creating landing page: @error',
        [
          '@error' => $e->getMessage(),
        ]
      );
      $this->drupalxAiLogger->error(
        'Error creating landing page: @error',
        [
          '@error' => $e->getMessage(),
          '@trace' => $e->getTraceAsString(),
        ]
      );
    }
  }

}
