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

/**
 * Controller for chatbot interactions.
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
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new ChatbotController object.
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
   * Processes chatbot messages and returns a response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the chatbot's reply.
   */
  public function processMessage(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $message = $data['message'] ?? NULL;

    if (empty($message)) {
      return new JsonResponse([
        'reply' => $this->t('No message received. Please provide a description.')->render(),
      ], 400);
    }

    $components = $this->aiService->getComponents($message);

    if ($components === NULL) {
      $this->logger->error('AI service failed to return components for message: @message', ['@message' => $message]);
      return new JsonResponse([
        'reply' => $this->t('Sorry, I had trouble understanding that or connecting to the AI service. Please try again later.')->render(),
      ], 500);
    }

    if (empty($components)) {
      return new JsonResponse([
        'reply' => $this->t('I understood your message: "@message", but I couldn\'t find any suitable components to build that. Try describing it differently?', [
          '@message' => htmlspecialchars($message),
        ])->render(),
      ]);
    }

    try {
      $node_title = $this->t('AI Generated Page: @snippet', [
        '@snippet' => substr(htmlspecialchars($message), 0, 50),
      ])->render();

      $node_storage = $this->entityTypeManager->getStorage('node');
      // Assuming 'landing' is the target node type.
      // Assign to current user or admin (user 1) if current user is anonymous.
      $current_user_id = $this->currentUser()->id() ?: 1;
      $node = $node_storage->create([
        'type' => 'landing',
        'title' => $node_title,
        'status' => Node::PUBLISHED,
        'uid' => $current_user_id,
      ]);
      $node->save();
      $nid = $node->id();

      $this->logger->info('Created initial landing page node @nid for AI components.', ['@nid' => $nid]);

      $saved_paragraphs = $this->entitySaveService->saveEntitiesToNode($nid, $components);

      if (empty($saved_paragraphs)) {
        // Node was created, but no components/paragraphs were successfully saved.
        // Log this and inform the user.
        $this->logger->warning('Node @nid was created, but no components/paragraphs were successfully saved to it from the AI response.', ['@nid' => $nid]);
        $page_url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
        $reply = $this->t('I created a page based on your message, but had trouble adding specific components. You can find the page here: @link', [
          '@link' => $page_url,
        ])->render();
      }
      else {
        $page_url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
        $reply = $this->t('I have created a new landing page for you with @count component(s)! You can view it here: @link', [
          '@count' => count($saved_paragraphs),
          '@link' => $page_url,
        ])->render();
      }

      return new JsonResponse([
        'reply' => $reply,
        'nid' => $nid,
        'node_url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ]);

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create landing page or process components: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'reply' => $this->t('Sorry, I encountered an error trying to create a page and add components.')->render(),
      ], 500);
    }
  }

}
