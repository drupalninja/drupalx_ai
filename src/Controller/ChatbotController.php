<?php

namespace Drupal\drupalx_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupalx_ai\Service\AIService;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Drupal\Component\Serialization\Json;

/**
 * Provides a controller for chatbot interactions.
 */
class ChatbotController extends ControllerBase {

  /**
   * The AI service.
   *
   * @var \Drupal\drupalx_ai\Service\AIService
   */
  protected AIService $aiService;


  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ChatbotController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\drupalx_ai\Service\AIService $ai_service
   *   The AI service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AIService $ai_service,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->aiService = $ai_service;
    $this->logger = $logger_factory->get('drupalx_ai');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('drupalx_ai.ai_service'),
      $container->get('logger.factory')
    );
  }

  /**
   * Processes a message from the chatbot.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  public function processMessage(Request $request): JsonResponse {
    $data = Json::decode($request->getContent());
    $description = $data['message'] ?? NULL;

    if (empty($description)) {
      return new JsonResponse(['error' => 'No description provided.'], 400);
    }

    $ai_response = $this->aiService->getComponents($description);

    if (!empty($ai_response['error'])) {
      $this->logger->error(
        'Error from AIService: @error. Raw: @raw',
        [
          '@error' => $ai_response['error'],
          '@raw' => $ai_response['raw_response'] ?? 'N/A',
        ]
      );
      return new JsonResponse([
        'error' => $ai_response['error'],
        'reply' => $ai_response['error'],
      ], 500);
    }

    $page_title = $ai_response['title'] ?? 'Generated Page by Chatbot';
    $components = $ai_response['components'] ?? [];

    if (empty($components)) {
      $this->logger->warning(
        'AIService returned no components for description: @desc',
        ['@desc' => $description]
      );
      return new JsonResponse([
        'message' => 'No components were generated. Try a different description.',
        'title' => $page_title,
      ], 200);
    }

    // Use the current user or a default user if needed.
    $current_user = $this->currentUser();
    $uid = $current_user->id() ?: 1;

    try {
      // Use the same workflow as drush command - create node with proper filtering
      $full_structure = $this->aiService->createNodeWithComponents($page_title, $components, $uid);
      $import_result = $this->aiService->importComponentsAsJsonImport($full_structure, FALSE);

      if (isset($import_result['error'])) {
        $this->logger->error(
          'Error importing components: @error',
          ['@error' => $import_result['error']]
        );
        throw new \Exception($import_result['error']);
      }

      // Log the import results
      if (!empty($import_result['summary'])) {
        foreach ($import_result['summary'] as $message) {
          $this->logger->info('JSON Import: @message', ['@message' => $message]);
        }
      }
      if (!empty($import_result['warnings'])) {
        foreach ($import_result['warnings'] as $warning) {
          $this->logger->warning('JSON Import Warning: @warning', ['@warning' => $warning]);
        }
      }

      // Get the created node - it should be the most recently created landing page
      $node_storage = $this->entityTypeManager()->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('type', 'landing')
        ->condition('created', time() - 60, '>=') // Created in the last minute
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->accessCheck(TRUE);

      $node_ids = $query->execute();

      if (empty($node_ids)) {
        throw new \Exception('Failed to create or locate the page node');
      }

      $node = Node::load(reset($node_ids));

      if (!$node) {
        throw new \Exception('Failed to load the created page node');
      }

      $this->logger->info(
        'Created new landing page node @nid with title "@title" using proper workflow.',
        [
          '@nid' => $node->id(),
          '@title' => $page_title,
        ]
      );

      $page_link = $node->toUrl('edit-form', ['absolute' => TRUE])->toString();
      // Render the translatable string to plain text for the JSON response.
      $reply_message = $this->t("Okay, I've created the page \"@title\". You can edit it at @link", [
        '@title' => $page_title,
        '@link' => $page_link,
      ])->render();

      return new JsonResponse([
        'reply' => $reply_message,
        'page_link' => $page_link,
        'title' => $page_title,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to create node or save entities: @message',
        ['@message' => $e->getMessage()]
      );
      // Ensure the error response also uses the 'reply' key.
      // The current JS error handler looks for xhr.responseJSON.reply OR errorData.message.
      return new JsonResponse([
        'error' => 'Failed to create page: ' . $e->getMessage(),
        'reply' => 'Sorry, I encountered an error while trying to create the page.',
      ], 500);
    }
  }

}
