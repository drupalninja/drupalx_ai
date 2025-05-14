<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupalx_ai\Service\AIService;
use Drupal\drupalx_ai\Service\EntitySaveService;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface; // For our specific logger instance

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
   * The entity save service.
   *
   * @var \Drupal\drupalx_ai\Service\EntitySaveService
   */
  protected EntitySaveService $entitySaveService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Logger for DrupalX AI specific messages.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $drupalxAiLogger;

  /**
   * Constructs a DrupalxAiCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\AIService $ai_service
   *   The AI service.
   * @param \Drupal\drupalx_ai\Service\EntitySaveService $entity_save_service
   *   The entity save service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AIService $ai_service,
    EntitySaveService $entity_save_service,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct();
    $this->aiService = $ai_service;
    $this->entitySaveService = $entity_save_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->drupalxAiLogger = $logger_factory->get('drupalx_ai');
  }

  /**
   * Generates a landing page using AI based on a user description.
   *
   * @param string $description
   *   The description of the page to generate.
   * @param array $options
   *   An associative array of options.
   *
   * @option uid The user ID to assign as the author of the node. Defaults to the current Drush user or user 1.
   *
   * @command drupalx_ai:generate-page
   * @aliases dxp
   * @usage drush drupalx_ai:generate-page "Create a page about sustainable farming with a hero image and a list of benefits."
   * @usage ddev drush dxp "A simple contact page with a map and a form."
   */
  public function generatePage(string $description, array $options = ['uid' => NULL]): void {
    $this->drupalxAiLogger->notice(dt('Starting AI page generation for: "@desc"', ['@desc' => $description]));

    $components = $this->aiService->getComponents($description);

    if ($components === NULL) {
      $this->drupalxAiLogger->error(dt('AI service failed to return components for the description.'));
      return;
    }

    if (empty($components)) {
      $this->drupalxAiLogger->warning(dt('AI service returned no suitable components for the description: "@desc"', ['@desc' => $description]));
      return;
    }

    $this->drupalxAiLogger->notice(dt('AI returned @count component(s). Attempting to create node and paragraphs.', ['@count' => count($components)]));

    try {
      $node_title = 'AI Generated Page: ' . substr(htmlspecialchars($description), 0, 50);

      $author_uid = $options['uid'] ?? $this->currentUser->id();
      if (empty($author_uid)) {
        // Default to admin user.
        $author_uid = 1;
        $this->drupalxAiLogger->notice('No UID provided or current user is anonymous, defaulting to admin (UID 1) for node authorship.');
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      // Assuming 'landing' is the target node type.
      $node = $node_storage->create([
        'type' => 'landing',
        'title' => $node_title,
        'status' => Node::PUBLISHED,
        'uid' => $author_uid,
      ]);
      $node->save();
      $nid = $node->id();

      $this->drupalxAiLogger->notice(dt('Created initial landing page node @nid.', ['@nid' => $nid]));

      $saved_paragraphs = $this->entitySaveService->saveEntitiesToNode($nid, $components);

      if (empty($saved_paragraphs)) {
        $this->drupalxAiLogger->warning(dt('Node @nid was created, but no components/paragraphs were successfully saved.', ['@nid' => $nid]));
        // Use $this->output() for user-facing messages in Drush commands.
        $this->output()->writeln(dt('Page created (NID: @nid), but no components were added. Check Drupal logs for details. Link: @link', [
          '@nid' => $nid,
          '@link' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        ]));
      }
      else {
        $this->drupalxAiLogger->notice(dt('Successfully added @count paragraph(s) to node @nid.', [
          '@count' => count($saved_paragraphs),
          '@nid' => $nid,
        ]));
        $this->output()->writeln(dt('Successfully created page (NID: @nid) with @count component(s)! Link: @link', [
          '@nid' => $nid,
          '@count' => count($saved_paragraphs),
          '@link' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        ]));
      }
    }
    catch (\Exception $e) {
      $this->drupalxAiLogger->error(dt('Failed to create landing page or process components: @message', ['@message' => $e->getMessage()]));
      // Also provide some output to the Drush user.
      $this->output()->writeln(dt('An error occurred during page generation. Check Drupal logs for details.'));
    }
  }

}
