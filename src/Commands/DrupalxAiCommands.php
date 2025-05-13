<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;

/**
 * A Drush commandfile for the DrupalX AI module.
 */
class DrupalxAiCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new DrupalxAiCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Command to create a stub AI landing page.
   *
   * @command drupalx_ai:create-landing-page
   * @aliases dx-clp
   * @usage drupalx_ai:create-landing-page
   *   Creates a new stub landing page node.
   */
  public function createLandingPage() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // IMPORTANT: Assumes a content type 'landing_page' exists.
    $node = $node_storage->create([
      'type' => 'landing_page',
      'title' => 'AI Generated Landing Page (Stub)',
      'body' => [
        'value' => 'This is a placeholder for an AI generated landing page.',
        'format' => 'basic_html',
      ],
      'status' => Node::PUBLISHED,
      // Or an appropriate user ID.
      'uid' => 1,
    ]);

    $node->save();
    $this->logger()->success(dt('Successfully created stub landing page with NID @nid.', ['@nid' => $node->id()]));
  }

}
