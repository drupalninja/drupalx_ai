<?php

namespace Drupal\drupalx_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for chatbot interactions.
 */
class ChatbotController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ChatbotController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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
  public function processMessage(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $message = $data['message'] ?? 'No message received';

    // Simulate AI processing and node creation for now.
    // In a real implementation, this would interact with the OpenAI API.

    $node_title = 'AI Stub Page: ' . substr(htmlspecialchars($message), 0, 50);
    $node_body = 'Generated based on chatbot message: ' . htmlspecialchars($message);

    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $node = $node_storage->create([
        'type' => 'landing_page',
        'title' => $node_title,
        'body' => [
          'value' => $node_body,
          'format' => 'basic_html',
        ],
        'status' => Node::PUBLISHED,
        'uid' => 1,
      ]);
      $node->save();
      $nid = $node->id();
      $reply = 'Created a stub landing page (NID: ' . $nid . ') based on your message: "' . $message . '"';
    }
    catch (\Exception $e) {
      $this->getLogger('drupalx_ai')->error('Failed to create landing page: @message', ['@message' => $e->getMessage()]);
      $reply = 'Sorry, I encountered an error trying to create a page for that.';
    }

    return new JsonResponse([
      'reply' => $reply,
    ]);
  }

}
