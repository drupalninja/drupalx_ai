<?php

namespace Drupal\drupalx_ai\Plugin\AiAgent;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agents\Attribute\AiAgent;
use Drupal\ai_agents\Exception\AgentProcessingException;
use Drupal\ai_agents\PluginBase\AiAgentBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\drupalx_ai\Service\AiLandingPageService;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\drupalx_ai\Service\ParagraphStructureService;
use Drupal\drupalx_ai\Service\MockLandingPageService;

/**
 * Landing page generator AI agent plugin.
 *
 * This agent uses AI to generate complete landing pages with structured content,
 * including various paragraph types and automatically generated media content.
 * It handles task determination, content generation, and node creation through
 * sub-agents and the AI landing page service.
 */
#[AiAgent(
  id: 'landing_page_generator_agent',
  label: new TranslatableMarkup('Landing Page Generator Agent'),
)]
/**
 * Provides a landing page generator AI agent.
 */
class LandingPageGenerator extends AiAgentBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * The paragraph structure service.
   *
   * @var \Drupal\drupalx_ai\Service\ParagraphStructureService
   */
  protected $paragraphStructureService;

  /**
   * The mock landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\MockLandingPageService
   */
  protected $mockLandingPageService;

  /**
   * Questions to ask back to the user.
   *
   * @var array
   */
  protected $questions = [];

  /**
   * The task data.
   *
   * @var array
   */
  protected $data;

  /**
   * The task type.
   *
   * @var string
   */
  protected $taskType;

  /**
   * The operation results.
   *
   * @var array
   */
  protected $result;

  /**
   * The created landing pages.
   *
   * @var array
   */
  protected $createdPages;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->aiLandingPageService = $container->get('drupalx_ai.ai_landing_page_service');
    $instance->paragraphStructureService = $container->get('drupalx_ai.paragraph_structure');
    $instance->mockLandingPageService = $container->get('drupalx_ai.mock_landing_page');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'landing_page_generator_agent';
  }

  /**
   * {@inheritdoc}
   */
  public function agentsNames() {
    return [
      'Landing Page Generator Agent',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function agentsCapabilities() {
    return [
      'landing_page_generator_agent' => [
        'name' => 'Landing Page Generator Agent',
        'description' => "This agent generates complete landing pages using AI. It creates structured content with various paragraph types and automatically generates appropriate media content.",
        'inputs' => [
          'free_text' => [
            'name' => 'Prompt',
            'type' => 'string',
            'description' => 'A detailed description of the landing page you want to create, including purpose, target audience, and key content areas.',
            'default_value' => '',
          ],
        ],
        'outputs' => [
          'url' => [
            'description' => 'The URL of the generated landing page.',
            'type' => 'string',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setData($data) {
    $this->data = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getData() {
    return $this->data;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return $this->agentHelper->isModuleEnabled('drupalx_ai');
  }

  /**
   * {@inheritdoc}
   */
  public function isNotAvailableMessage() {
    return $this->t('You need to enable the drupalx_ai module to use the landing page generator.');
  }

  /**
   * {@inheritdoc}
   */
  public function getRetries() {
    return 2;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess() {
    if (!$this->currentUser->hasPermission('create landing content')) {
      return AccessResult::forbidden();
    }
    return parent::hasAccess();
  }

  /**
   * {@inheritdoc}
   */
  public function determineSolvability() {
    parent::determineSolvability();
    $this->taskType = $this->determineTypeOfTask();

    switch ($this->taskType) {
      case 'generate':
        return AiAgentInterface::JOB_SOLVABLE;

      case 'question':
        return AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION;

      case 'information':
        return AiAgentInterface::JOB_NEEDS_ANSWERS;

      case 'fail':
        return AiAgentInterface::JOB_NOT_SOLVABLE;
    }

    return AiAgentInterface::JOB_NOT_SOLVABLE;
  }

  /**
   * {@inheritdoc}
   */
  public function solve() {
    parent::solve();

    \Drupal::logger('drupalx_ai')->debug('Starting solve() method. Task type: @type, Data: @data', [
      '@type' => $this->taskType,
      '@data' => print_r($this->data, TRUE),
    ]);

    try {
      switch ($this->taskType) {
        case 'generate':
          if (!isset($this->data[0]['description'])) {
            throw new AgentProcessingException('No description provided by the sub-agent.');
          }

          $this->generateLandingPage([
            'page_title' => $this->data[0]['page_title'] ?? 'AI Generated Landing Page',
            'description' => $this->data[0]['description'],
          ]);
          break;

        case 'question':
          return $this->answerQuestion();

        default:
          return $this->t('We could not figure out what you wanted to do.');
      }
    }
    catch (\Exception $e) {
      return $this->t('There was an error generating the landing page: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    if (empty($this->createdPages)) {
      return $this->t('No landing pages were created.');
    }

    $pageLines = [];
    foreach ($this->createdPages as $page) {
      $pageLines[] = $this->t('@title - @url', [
        '@title' => $page['title'],
        '@url' => $page['url'],
      ]);
    }

    return $this->t("The following landing pages were created:\n@pages", [
      '@pages' => implode("\n", $pageLines),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    if (!$this->agentHelper->isModuleEnabled('paragraphs')) {
      throw new AgentProcessingException('The paragraphs module is required for landing page generation.');
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function inform() {
    return $this->information;
  }

  /**
   * Generates a landing page based on the provided data.
   *
   * @param array $data
   *   The data containing the landing page description.
   */
  protected function generateLandingPage(array $data) {
    \Drupal::logger('drupalx_ai')->debug('Landing page generation data: @data', [
      '@data' => print_r($data, TRUE),
    ]);

    if (empty($data['description'])) {
      throw new AgentProcessingException('No description provided for the landing page.');
    }

    // Get the paragraph structures and allowed types.
    $paragraphStructures = $this->paragraphStructureService->getParagraphStructures(TRUE);
    $allowedParagraphTypes = $this->mockLandingPageService->getAllowedParagraphTypes('node', 'landing', 'field_content');

    \Drupal::logger('drupalx_ai')->debug('Allowed paragraph types: @types', [
      '@types' => implode(', ', $allowedParagraphTypes),
    ]);

    // Run the generateLandingPage sub-agent to get structured content.
    $response = $this->agentHelper->runSubAgent('generateLandingPage', [
      'description' => $data['description'],
      'allowed_paragraph_types' => implode(',', $allowedParagraphTypes),
      'paragraph_structures' => json_encode($paragraphStructures, JSON_PRETTY_PRINT),
    ]);

    if (empty($response)) {
      \Drupal::logger('drupalx_ai')->error('Empty response from sub-agent.');
      throw new AgentProcessingException('Failed to generate landing page content structure.');
    }

    \Drupal::logger('drupalx_ai')->debug('Response type: @type', [
      '@type' => is_array($response) ? 'array' : get_class($response),
    ]);

    // Handle both ChatMessage and array responses.
    if (!is_array($response)) {
      // If it's a ChatMessage, get the JSON string and decode it.
      $text = $response->getText();
      \Drupal::logger('drupalx_ai')->debug('Raw ChatMessage text before JSON decode: @text', [
        '@text' => $text,
      ]);

      // More thorough cleaning of the text.
      $text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $text);
      $text = preg_replace('/\s+/', ' ', $text);
      $text = trim($text);

      // Validate JSON structure.
      if (!preg_match('/^\[.*\]$/', $text)) {
        \Drupal::logger('drupalx_ai')->error('Invalid JSON structure: @text', [
          '@text' => $text,
        ]);
        throw new AgentProcessingException('Invalid JSON structure: Expected array');
      }

      \Drupal::logger('drupalx_ai')->debug('Cleaned text before JSON decode: @text', [
        '@text' => $text,
      ]);

      // Try to decode with error checking.
      $content = json_decode($text, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to clean any potential UTF-8 issues.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $content = json_decode($text, TRUE);

        if (json_last_error() !== JSON_ERROR_NONE) {
          \Drupal::logger('drupalx_ai')->error('JSON decode error: @error, Text: @text', [
            '@error' => json_last_error_msg(),
            '@text' => $text,
          ]);
          throw new AgentProcessingException('Failed to decode JSON response: ' . json_last_error_msg());
        }
      }
    }
    else {
      // If it's already an array, use the first item if it exists.
      if (!empty($response[0]) && is_array($response[0])) {
        $content = $response[0];
        \Drupal::logger('drupalx_ai')->debug('Using first item from array response.');
      }
      else {
        $content = $response;
        \Drupal::logger('drupalx_ai')->debug('Using full array response.');
      }
    }

    \Drupal::logger('drupalx_ai')->debug('Landing page generation content: @content', [
      '@content' => print_r($content, TRUE),
    ]);

    // Check if content is still nested in [0].
    if (empty($content['page_title']) && !empty($content[0]['page_title'])) {
      $content = $content[0];
      \Drupal::logger('drupalx_ai')->debug('Extracted content from nested array.');
    }

    if (empty($content['page_title']) || empty($content['paragraphs'])) {
      \Drupal::logger('drupalx_ai')->error('Missing required fields in content: @content', [
        '@content' => print_r($content, TRUE),
      ]);
      throw new AgentProcessingException('Generated content is missing required fields.');
    }

    try {
      // Create the landing page node with the generated content.
      $url = $this->aiLandingPageService->createLandingNodeWithAiContent(
        $content['page_title'],
        $content['paragraphs'],
        $allowedParagraphTypes
      );

      if (!$url) {
        \Drupal::logger('drupalx_ai')->error('Node creation failed with no URL returned.');
        throw new AgentProcessingException('Failed to create landing page node.');
      }

      // Add to the result array instead of replacing it.
      $this->result[] = sprintf('Successfully created landing page: %s', $url);

      // Store the page info for later use.
      $this->createdPages[] = [
        'title' => $content['page_title'],
        'url' => $url,
      ];

      \Drupal::logger('drupalx_ai')->info('Successfully created landing page: @url', [
        '@url' => $url,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('drupalx_ai')->error('Error creating landing page node: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new AgentProcessingException('Failed to create landing page node: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function askQuestions() {
    return $this->questions;
  }

  /**
   * {@inheritdoc}
   */
  public function answerQuestion() {
    if (!$this->currentUser->hasPermission('create landing content')) {
      return $this->t('You do not have permission to do this.');
    }

    // Get paragraph structures and allowed types for context.
    $paragraphStructures = $this->paragraphStructureService->getParagraphStructures(TRUE);
    $allowedParagraphTypes = $this->mockLandingPageService->getAllowedParagraphTypes('node', 'landing', 'field_content');

    // Run the sub-agent with context.
    $response = $this->agentHelper->runSubAgent('answerQuestion', [
      'description' => $this->data['free_text'] ?? '',
      'task_type' => $this->taskType,
      'paragraph_structures' => json_encode($paragraphStructures, JSON_PRETTY_PRINT),
      'allowed_paragraph_types' => implode(', ', $allowedParagraphTypes),
    ]);

    $answer = '';
    if (isset($response[0]['answer'])) {
      foreach ($response as $dataPoint) {
        $answer .= $dataPoint['answer'] . "\n";
      }
      return rtrim($answer);
    }

    return $this->t("Sorry, I got no answers for you.");
  }

  /**
   * Determine the type of task from the input.
   *
   * @return string
   *   The determined task type.
   */
  protected function determineTypeOfTask() {
    try {
      $response = $this->agentHelper->runSubAgent('determineLandingPageTask', [
        'description' => $this->data['free_text'] ?? '',
      ]);

      if (!is_array($response)) {
        $text = $response->getText();
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);
        $data = json_decode($text, TRUE);

        if (json_last_error() !== JSON_ERROR_NONE) {
          throw new AgentProcessingException('Invalid JSON response: ' . json_last_error_msg());
        }
      }
      else {
        $data = $response;
      }

      // Check if we got landing page content directly.
      if (!empty($data[0]['page_title']) && !empty($data[0]['paragraphs'])) {
        $this->data = $data;
        return 'generate';
      }

      // Otherwise check for action field.
      if (empty($data[0]['action'])) {
        return 'fail';
      }

      $action = $data[0]['action'];
      if (!in_array($action, ['generate', 'question', 'information', 'fail'])) {
        throw new AgentProcessingException('Invalid action type');
      }

      $this->data = $data;
      return $action;
    }
    catch (\Exception $e) {
      \Drupal::logger('drupalx_ai')->error('Error in determineTypeOfTask: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 'fail';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getHelp() {
    return $this->t('This agent generates complete landing pages using AI. Provide a detailed description of the landing page you want, including its purpose, target audience, and key content areas. The agent will create a structured page with appropriate sections and content.');
  }

}
