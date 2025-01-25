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

/**
 * Landing page generator AI agent plugin.
 *
 * This agent uses AI to generate complete landing pages with structured content,
 * including various paragraph types and automatically generated media content.
 */
#[AiAgent(
  id: 'landing_page_generator_agent',
  label: new TranslatableMarkup('Landing Page Generator Agent'),
)]
class LandingPageGenerator extends AiAgentBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * Questions to ask back to the user.
   *
   * @var array
   */
  protected $questions;

  /**
   * The task data.
   *
   * @var array
   */
  protected $data = [];

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
  protected $result = [];

  /**
   * The answer context to help answers.
   *
   * @var array
   */
  protected array $answerContext;

  /**
   * Information to inform.
   *
   * @var string
   */
  protected $information;

  /**
   * The created landing pages.
   *
   * @var array
   */
  protected $createdPages = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->aiLandingPageService = $container->get('drupalx_ai.ai_landing_page_service');
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
        'usage_instructions' => "Provide a description of the landing page you want to create, including its purpose and target audience.",
        'inputs' => [
          'description' => [
            'name' => 'Description',
            'type' => 'object',
            'properties' => [
              'page_title' => [
                'type' => 'string',
                'description' => 'Title for the landing page',
              ],
              'paragraphs' => [
                'type' => 'array',
                'items' => [
                  'type' => 'object',
                  'properties' => [
                    'type' => [
                      'type' => 'string',
                      'description' => 'The type of the paragraph',
                    ],
                    'fields' => [
                      'type' => 'object',
                      'additionalProperties' => TRUE,
                      'description' => 'The fields of the paragraph with their values',
                    ],
                  ],
                  'required' => ['type', 'fields'],
                ],
              ],
            ],
            'required' => ['paragraphs', 'page_title'],
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
    // If we get a string, treat it as a description for backward compatibility.
    if (is_string($data)) {
      $this->data = [
        'description' => [
          'page_title' => 'AI Generated Landing Page',
          'paragraphs' => [],
          'raw_description' => $data ?: 'Please provide a description of the landing page you want to create.',
        ],
      ];
    }
    // If we get an array without a description key, treat first value as description.
    elseif (is_array($data) && !isset($data['description']) && !empty($data)) {
      $firstValue = reset($data);
      $this->data = [
        'description' => [
          'page_title' => 'AI Generated Landing Page',
          'paragraphs' => [],
          'raw_description' => is_string($firstValue) ? $firstValue : 'Please provide a description of the landing page you want to create.',
        ],
      ];
    }
    // Otherwise, use the data as is but ensure it's in the correct format.
    else {
      $formattedData = is_array($data) ? $data : ['description' => $data];
      if (!isset($formattedData['description']['page_title'])) {
        $formattedData['description']['page_title'] = 'AI Generated Landing Page';
      }
      if (!isset($formattedData['description']['paragraphs'])) {
        $formattedData['description']['paragraphs'] = [];
      }
      if (isset($formattedData['description']) && is_string($formattedData['description'])) {
        $formattedData['description'] = [
          'page_title' => 'AI Generated Landing Page',
          'paragraphs' => [],
          'raw_description' => $formattedData['description'] ?: 'Please provide a description of the landing page you want to create.',
        ];
      }
      if (empty($formattedData['description']['raw_description'])) {
        $formattedData['description']['raw_description'] = 'Please provide a description of the landing page you want to create.';
      }
      $this->data = $formattedData;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getData() {
    if (empty($this->data)) {
      return [
        'description' => [
          'page_title' => 'AI Generated Landing Page',
          'paragraphs' => [],
          'raw_description' => '',
        ],
      ];
    }
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
    // Log the task type and data at the start of solve.
    \Drupal::logger('drupalx_ai')->debug('Starting solve() method. Task type: @type, Data: @data', [
      '@type' => $this->taskType,
      '@data' => print_r($this->data, TRUE),
    ]);

    parent::solve();
    $messages = [];

    try {
      switch ($this->taskType) {
        case 'generate':
          // Get the sub-agent's response data.
          $response = $this->agentHelper->runSubAgent('determineLandingPageTask', [
            'description' => 'Create a landing page for a new eco-friendly water bottle.',
          ]);

          if (!empty($response[0]['description'])) {
            // Update the data with the sub-agent's response.
            $this->data = [
              'description' => [
                'page_title' => $response[0]['page_title'] ?? 'Eco-Friendly Water Bottle Landing Page',
                'paragraphs' => [],
                'raw_description' => $response[0]['description'],
              ],
            ];

            // Log the updated data.
            \Drupal::logger('drupalx_ai')->debug('Updated data before generating landing page: @data', [
              '@data' => print_r($this->data, TRUE),
            ]);

            $this->generateLandingPage($this->data);
          }
          else {
            throw new AgentProcessingException('No description provided by the sub-agent.');
          }
          break;

        case 'question':
          $answer = $this->answerQuestion();
          if (is_string($answer)) {
            $this->result[] = $answer;
          }
          return $answer;

        default:
          $this->result[] = $this->t('We could not figure out what you wanted to do.');
      }
    }
    catch (\Exception $e) {
      $messages[] = 'There was an error generating the landing page: ' . $e->getMessage();
    }

    // Ensure result is always an array.
    if (!is_array($this->result)) {
      $this->result = [$this->result];
    }

    $parts = [];

    if (!empty($messages)) {
      $parts[] = "The following are errors:\n" . implode("\n", $messages);
    }

    if (!empty($this->result)) {
      $parts[] = "The following are results:\n" . implode("\n", array_filter($this->result));
    }

    if (!empty($this->createdPages)) {
      $pageLines = [];
      foreach ($this->createdPages as $page) {
        $pageLines[] = $page['title'] . ' - ' . $page['url'];
      }
      $parts[] = "The following landing pages were created:\n" . implode("\n", $pageLines);
    }

    return empty($parts) ? 'No results to display.' : implode("\n\n", $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function approveSolution() {
    $this->data[0]['action'] = 'generate';
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
    // Log the incoming data for debugging.
    \Drupal::logger('drupalx_ai')->debug('Landing page generation data: @data', [
      '@data' => print_r($data, TRUE),
    ]);

    if (empty($data['description']) || empty($data['description']['raw_description'])) {
      throw new AgentProcessingException('No description provided for the landing page.');
    }

    $aiContent = $this->aiLandingPageService->generateAiContent($data['description']['raw_description']);
    if (!$aiContent) {
      throw new AgentProcessingException('Failed to generate AI content for the landing page.');
    }

    $url = $this->aiLandingPageService->createLandingNodeWithAiContent(
      $aiContent['page_title'],
      $aiContent['paragraphs']
    );

    if (!$url) {
      throw new AgentProcessingException('Failed to create landing page node.');
    }

    // Add to the result array instead of replacing it.
    $this->result[] = sprintf('Successfully created landing page: %s', $url);

    // Store the page info for later use.
    $this->createdPages[] = [
      'title' => $aiContent['page_title'],
      'url' => $url,
    ];
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
    // Check permissions first.
    if (!$this->currentUser->hasPermission('create landing content')) {
      return $this->t('You do not have permission to do this.');
    }

    // Build context for the answer.
    $context = [];
    $description = $this->data[0]['description'] ?? '';
    if (!empty($description)) {
      $context['Landing Page Request'] = $description;
    }
    if (!empty($this->taskType)) {
      $context['Task Type'] = $this->taskType;
    }

    $response = $this->agentHelper->runSubAgent('answerQuestion', $context);

    // Extract the message content from the ChatMessage object.
    $messageContent = '';
    if (method_exists($response, 'getMessage')) {
      $messageContent = $response->getMessage();
    }
    elseif (method_exists($response, 'getContent')) {
      $messageContent = $response->getContent();
    }
    else {
      throw new \Exception('Unable to extract message content from response.');
    }

    // Parse the JSON content.
    $data = json_decode($messageContent, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['answer'])) {
      return $this->t("I couldn't process the answer about the landing page generation task.");
    }

    $answer = '';
    foreach ($data as $dataPoint) {
      if (isset($dataPoint['answer'])) {
        $answer .= $dataPoint['answer'] . "\n";
      }
    }

    return empty($answer) ?
      $this->t("Sorry, I got no answers for you.") :
      rtrim($answer);
  }

  /**
   * Determine the type of task from the input.
   *
   * @return string
   *   The determined task type.
   */
  protected function determineTypeOfTask() {
    // Log the raw data for debugging.
    \Drupal::logger('drupalx_ai')->debug('Raw data in determineTypeOfTask: @data', [
      '@data' => print_r($this->data, TRUE),
    ]);

    $data = $this->getData();
    $description = '';

    // Handle the case where data is directly a string.
    if (is_string($data)) {
      $description = $data;
    }
    // Handle the case where data is an array with description.
    elseif (isset($data['description'])) {
      if (is_string($data['description'])) {
        $description = $data['description'];
      }
      elseif (isset($data['description']['raw_description'])) {
        $description = $data['description']['raw_description'];
      }
    }
    // Handle legacy array format.
    elseif (!empty($data[0])) {
      if (is_string($data[0])) {
        $description = $data[0];
      }
      elseif (isset($data[0]['description'])) {
        if (is_string($data[0]['description'])) {
          $description = $data[0]['description'];
        }
        elseif (isset($data[0]['description']['raw_description'])) {
          $description = $data[0]['description']['raw_description'];
        }
      }
    }

    // Log the extracted description.
    \Drupal::logger('drupalx_ai')->debug('Extracted description: @description', [
      '@description' => $description,
    ]);

    $response = $this->agentHelper->runSubAgent('determineLandingPageTask', [
      'description' => $description,
    ]);

    // Log the response from the sub-agent.
    \Drupal::logger('drupalx_ai')->debug('Sub-agent response: @response', [
      '@response' => print_r($response, TRUE),
    ]);

    // Return error early if no action is set.
    if (!isset($response[0]['action'])) {
      $this->information = 'Sorry, we could not understand what you wanted to do, please try again.';
      return 'fail';
    }

    $validActions = [
      'generate',
      'question',
      'information',
      'fail',
    ];

    if (in_array($response[0]['action'], $validActions)) {
      if ($response[0]['action'] === 'fail') {
        $this->information = $response[0]['fail_message'] ?? 'Failed to determine task type.';
      }
      return $response[0]['action'];
    }

    throw new \Exception('Invalid action in determining landing page task.');
  }

  /**
   * {@inheritdoc}
   */
  public function getHelp() {
    return $this->t('This agent generates complete landing pages using AI. Provide a detailed description of the landing page you want, including its purpose, target audience, and key content areas. The agent will create a structured page with appropriate sections and content.');
  }

}
