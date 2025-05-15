<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for handling taxonomy terms in the DrupalX AI module.
 */
class TaxonomyService {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Constructs a new TaxonomyService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('drupalx_ai');
    $this->currentUser = $current_user;
  }

  /**
   * Creates a taxonomy term with the given name in a vocabulary and returns ID.
   *
   * @param string $term_name
   *   Name of the term to create.
   * @param string $vocabulary
   *   Machine name of the vocabulary.
   * @param int $owner_id
   *   User ID of the owner.
   *
   * @return int|null
   *   Term ID if successful, NULL otherwise.
   */
  public function createTaxonomyTerm(string $term_name, string $vocabulary, int $owner_id): ?int {
    if (empty($term_name) || empty($vocabulary)) {
      $this->logger->error('Cannot create taxonomy term: missing term name or vocabulary.');
      return NULL;
    }

    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $term = $term_storage->create([
        'name' => $term_name,
        'vid' => $vocabulary,
        'uid' => $owner_id,
      ]);
      $term->save();

      $this->logger->info('Created taxonomy term "@name" (@tid) in vocabulary @vid.', [
        '@name' => $term_name,
        '@tid' => $term->id(),
        '@vid' => $vocabulary,
      ]);

      return $term->id();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create taxonomy term "@name" in vocabulary @vid: @message', [
        '@name' => $term_name,
        '@vid' => $vocabulary,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets a taxonomy term ID by name, creating it if it doesn't exist.
   *
   * @param string $term_name
   *   The name of the taxonomy term.
   * @param array|string $vocabularies
   *   A single vocabulary machine name or an array of vocabulary machine names to search within.
   *
   * @return int|null
   *   The term ID or NULL if not found/created.
   */
  public function getTermIdByName(string $term_name, $vocabularies): ?int {
    if (empty($term_name)) {
      return NULL;
    }
    $vocabularies = (array) $vocabularies;
    if (empty($vocabularies)) {
      $this->logger->warning('No vocabularies specified for taxonomy term lookup: @term', ['@term' => $term_name]);
      return NULL;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $term_storage->getQuery()
      ->condition('name', $term_name)
      ->condition('vid', $vocabularies, 'IN')
      // Perform access check.
      ->accessCheck(TRUE);
    $term_ids = $query->execute();

    if (!empty($term_ids)) {
      return reset($term_ids);
    }
    else {
      $primary_vocabulary = reset($vocabularies);
      $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
      $vocabulary_entity = $vocabulary_storage->load($primary_vocabulary);

      if (!$vocabulary_entity) {
        $log_context = [
          '@term_name' => $term_name,
          '@vid' => $primary_vocabulary,
        ];
        $this->logger->error('Cannot create term "@term_name" because vocabulary "@vid" does not exist.', $log_context);
        return NULL;
      }

      try {
        // Default to admin if anonymous.
        $term_owner_id = $this->currentUser->id() ?: 1;
        $term = $term_storage->create([
          'name' => $term_name,
          'vid' => $primary_vocabulary,
          'uid' => $term_owner_id,
        ]);
        $term->save();
        $log_context = [
          '@name' => $term_name,
          '@tid' => $term->id(),
          '@vid' => $primary_vocabulary,
        ];
        $this->logger->info('Created taxonomy term "@name" (@tid) in vocabulary @vid.', $log_context);
        return $term->id();
      }
      catch (\Exception $e) {
        $log_context = [
          '@name' => $term_name,
          '@vid' => $primary_vocabulary,
          '@message' => $e->getMessage(),
        ];
        $this->logger->error('Failed to create taxonomy term "@name" in vocabulary @vid: @message', $log_context);
        return NULL;
      }
    }
  }

}
