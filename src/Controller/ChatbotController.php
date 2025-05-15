<?php

namespace Drupal\drupalx_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\drupalx_ai\Service\AIService;
use Drupal\drupalx_ai\Service\EntitySaveService;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
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
   * The entity save service.
   *
   * @var \Drupal\drupalx_ai\Service\EntitySaveService
   */
  protected EntitySaveService $entitySaveService;

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
   * @param \Drupal\drupalx_ai\Service\EntitySaveService $entity_save_service
   *   The entity save service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AIService $ai_service,
    EntitySaveService $entity_save_service,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->aiService = $ai_service;
    $this->entitySaveService = $entity_save_service;
    $this->logger = $logger_factory->get('drupalx_ai');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('drupalx_ai.ai_service'),
      $container->get('drupalx_ai.entity_save_service'),
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
      return new JsonResponse(['error' => $ai_response['error']], 500);
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

    // Create a new node.
    // Use the current user or a default user if needed.
    $current_user = $this->currentUser();
    $uid = $current_user->id() ?: 1;

    try {
      $node = Node::create([
        'type' => 'landing',
        // Your landing page content type.
        'title' => $page_title,
        'uid' => $uid,
        'status' => 1,
        // Published.
        'field_hide_page_title' => TRUE,
      ]);
      $node->save();
      $this->logger->info(
        'Created new landing page node @nid with title "@title".',
        [
          '@nid' => $node->id(),
          '@title' => $page_title,
        ]
      );

      // Save entities to the node.
      $result = $this->entitySaveService->saveEntitiesToNode($node->id(), $components);

      if (isset($result['error'])) {
        $this->logger->error('Error saving entities: @error', ['@error' => $result['error']]);
        // Node was created, but paragraphs failed.
        // Decide on cleanup or user message.
        return new JsonResponse([
          'error' => 'Page created, but content generation failed: ' . $result['error'],
        ], 500);
      }

      $page_link = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      return new JsonResponse([
        'message' => 'Landing page created successfully!',
        'page_link' => $page_link,
        'title' => $page_title,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to create node or save entities: @message',
        ['@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to create page: ' . $e->getMessage()], 500);
    }
  }

}
