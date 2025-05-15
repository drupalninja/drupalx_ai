<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupalx_ai\Service\AIService;
use Drupal\drupalx_ai\Service\EntitySaveService;
use Drupal\drupalx_ai\Service\OutputFormatterService;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
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
   * The output formatter service.
   *
   * @var \Drupal\drupalx_ai\Service\OutputFormatterService
   */
  protected OutputFormatterService $outputFormatter;

  /**
   * Constructs a DrupalxAiCommands object.
   *
   * @param \Drupal\drupalx_ai\Service\AIService $ai_service
   *   The AI service.
   * @param \Drupal\drupalx_ai\Service\EntitySaveService $entity_save_service
   *   The entity save service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AIService $ai_service,
    EntitySaveService $entity_save_service,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    OutputFormatterService $output_formatter
  ) {
    parent::__construct();
    $this->aiService = $ai_service;
    $this->entitySaveService = $entity_save_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->drupalxAiLogger = $logger_factory->get('drupalx_ai');
    $this->outputFormatter = $output_formatter;
  }

  /**
   * Generates a landing page using AI based on a description.
   *
   * @command drupalx_ai:generate-page
   * @aliases dxp
   * @param string $description
   *   The description of the page to generate.
   * @option uid The user ID to assign as the author of the page. Defaults to the current Drush user or user 1.
   * @usage drupalx_ai:generate-page "Create a page about sustainable energy solutions for urban environments."
   * @usage dxp "A promotional page for a new tech startup focused on AI-driven analytics." --uid=1
   */
  public function generatePage(string $description, array $options = ['uid' => NULL]): void {
    // Create visual separator at the beginning of the command.
    $this->output()->writeln(
      "<fg=blue>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n<fg=magenta;options=bold>                       DRUPALX AI PAGE GENERATOR                       </>\n<fg=blue>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>\n"
    );

    $this->output()->writeln(dt('<fg=cyan;options=bold>🗨 Prompt:</> <fg=green>"@desc"</>', ['@desc' => $description]));
    $this->output()->writeln("");
    $this->output()->writeln("");

    $this->drupalxAiLogger->info('Attempting to generate page with description: "@desc" [...]', ['@desc' => substr($description, 0, 50)]);

    $this->output()->writeln("<fg=yellow;options=bold>🤖 AI is generating content, please wait...</>");
    $this->output()->writeln("");

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
      $this->logger()->warning('AIService returned no components for description: @desc', ['@desc' => $description]);
      $this->drupalxAiLogger->warning(
        'No components returned by AI for description: "@desc". Title suggested was "@title".',
        [
          '@desc' => $description,
          '@title' => $page_title,
        ]
      );
      $this->output()->writeln(dt('<fg=yellow;options=bold>⚠️ Warning:</> No components were generated by the AI.
Suggested title was: <fg=green>"{@title}"</>.
<fg=cyan>Try a different description.</>', ['@title' => $page_title]));
      return;
    }

    // Preprocess components to detect common structural issues.
    $components = $this->preprocessAIGeneratedComponents($components);

    // Output a summary of components instead of verbose logs.
    $component_types = [];
    foreach ($components as $component) {
      if (isset($component['type'])) {
        $type = $component['type'];
        $component_types[$type] = isset($component_types[$type]) ? $component_types[$type] + 1 : 1;
      }
    }

    $this->output()->writeln("");
    $this->output()->writeln("<fg=cyan;options=bold>📦 Components detected:</>");
    foreach ($component_types as $type => $count) {
      $icon = $this->getComponentIcon($type);
      $this->output()->writeln("  <fg=green>$icon</> <fg=yellow>$type</> <fg=white>× $count</>");
    }
    $this->output()->writeln("");

    $this->output()->writeln(dt('<fg=cyan;options=bold>🔍 AI suggested page title</> <fg=green;options=bold>"{@title}"</> and <fg=yellow;options=bold>{@count}</> components.', [
      '@title' => $page_title,
      '@count' => count($components),
    ]));

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
      $node->save();
      $this->drupalxAiLogger->info(
        'Created new landing page node @nid with title "@title" by user @uid.',
        [
          '@nid' => $node->id(),
          '@title' => $page_title,
          '@uid' => $uid,
        ]
      );
      $this->output()->writeln(dt('<fg=green;options=bold>✅ Successfully created</> new landing page node <fg=yellow;options=bold>@nid</> with title <fg=green;options=bold>"{@title}"</>.', [
        '@nid' => $node->id(),
        '@title' => $page_title,
      ]));

      // Display a nice formatted component preview before saving.
      $this->showFormattedComponentLogs($components);

      // Pass the already_preprocessed flag to avoid redundant logging.
      $this->output()->writeln(dt('<fg=cyan;options=bold>🧩 Adding components to node:</> <fg=yellow>@nid</>', ['@nid' => $node->id()]));

      // Show a nice colorful progress bar.
      $this->formatProgressBar(count($components));

      $result = $this->entitySaveService->saveEntitiesToNode($node->id(), $components, TRUE);

      if (isset($result['error'])) {
        $this->logger()->error(
          'Error saving entities to node @nid: @error',
          [
            '@nid' => $node->id(),
            '@error' => $result['error'],
          ]
        );
        $this->drupalxAiLogger->error(
          'EntitySaveService failed for node @nid: @error',
          [
            '@nid' => $node->id(),
            '@error' => $result['error'],
          ]
        );
        $this->output()->writeln(dt('<fg=yellow;options=bold>⚠️ Warning:</> Page node <fg=cyan>@nid</> created, but content generation failed: <fg=red>@error</>', [
          '@nid' => $node->id(),
          '@error' => $result['error'],
        ]));
      }
      else {
        $this->drupalxAiLogger->info(
          'Successfully saved @count components to node @nid.',
          [
            '@count' => count($components),
            '@nid' => $node->id(),
          ]
        );

        // Add a fancy completion banner.
        $this->output()->writeln("");
        $this->output()->writeln(
          "<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n<fg=green;options=bold>                LANDING PAGE SUCCESSFULLY CREATED!                 </>\n<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>"
        );
        $this->output()->writeln("");
        $this->output()->writeln('<fg=blue;options=bold>📝 Title:</> <fg=green>"' . $page_title . '"</>');
        $this->output()->writeln('<fg=blue;options=bold>🆔 Node ID:</> <fg=yellow>' . $node->id() . '</>');
        $this->output()->writeln('<fg=blue;options=bold>📋 Components:</> <fg=yellow>' . count($components) . '</>');
        $this->output()->writeln('<fg=blue;options=bold>🔗 URL:</> <fg=cyan>' . Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString() . '</>');
        $this->output()->writeln("");
        $this->output()->writeln('<fg=magenta;options=bold>💻 View the page in your browser with:</> <fg=white;options=bold>ddev drush uli "--destination=' . Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString() . '"</>');
        $this->output()->writeln("");
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
      $this->output()->writeln(dt('<fg=red;options=bold>❌ Error:</> Creating landing page failed: <fg=red>@error</>', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Preprocesses AI-generated components to fix common structural issues.
   *
   * Delegates to EntitySaveService for preprocessing to maintain consistency
   * across the codebase and avoid duplicate logic.
   *
   * @param array $components
   *   The raw components data from the AI service.
   *
   * @return array
   *   The preprocessed components data.
   */
  protected function preprocessAIGeneratedComponents(array $components): array {
    // Log original components for debugging
    $this->drupalxAiLogger->notice('DrushCommand: Starting preprocessAIGeneratedComponents with @count components', [
      '@count' => count($components),
    ]);

    // Delegate preprocessing to EntitySaveService for consistency
    $preprocessed = $this->entitySaveService->preprocessComponents($components, TRUE);

    return $preprocessed;
  }

  /**
   * Returns an appropriate icon based on component type.
   *
   * @param string $type
   *   The component type.
   *
   * @return string
   *   An emoji icon representing the component type.
   */
  protected function getComponentIcon(string $type): string {
    return $this->outputFormatter->getComponentIcon($type);
  }

  /**
   * Override the standard logger to use our formatted output for the console.
   *
   * This will intercept messages that would normally go to the Drupal logger
   * and display them with colorful formatting in the console.
   *
   * @param string $channel
   *   The logger channel to use.
   * @param string $message
   *   The message to log.
   * @param array $context
   *   The context for the message.
   * @param string $level
   *   The log level (notice, warning, error, etc).
   */
  protected function logWithFormatting(string $channel, string $message, array $context = [], string $level = 'notice'): void {
    // Still log to Drupal's logger for database logging.
    $this->drupalxAiLogger->$level($message, $context);

    // Apply our custom formatting for console output.
    $formatted = $this->outputFormatter->formatLogMessage($message, $level, $context);
    $this->output()->writeln($formatted);
  }

  /**
   * Displays component creation logs with formatting.
   *
   * This method formats the output of the component creation process with
   * colorful, icon-based formatting for better readability.
   *
   * @param array $components
   *   The components data to log.
   */
  protected function showFormattedComponentLogs(array $components): void {
    // Start with a header.
    $this->output()->writeln('');
    $this->output()->writeln('<fg=cyan;options=bold>📦 Component Creation Details:</>');

    foreach ($components as $component) {
      if (isset($component['type'])) {
        $type = $component['type'];
        $icon = $this->outputFormatter->getComponentIcon($type);

        // Format the component header.
        $this->output()->writeln(
          "<fg=green>$icon <fg=yellow;options=bold>$type</> component:</>"
        );

        // Show key fields with proper formatting.
        if (isset($component['field_title'])) {
          $this->output()->writeln("  <fg=blue>🔤 Title:</> <fg=white>{$component['field_title']}</>");
        }

        if (isset($component['field_media'])) {
          $mediaType = $component['field_media']['type'] ?? 'unknown';
          $mediaIcon = $this->outputFormatter->getComponentIcon($mediaType);
          $this->output()->writeln("  <fg=blue>$mediaIcon Media:</> <fg=white>$mediaType</>");
        }

        if (isset($component['field_card']) && is_array($component['field_card'])) {
          $cardCount = count($component['field_card']);
          $this->output()->writeln("  <fg=blue>🃏 Cards:</> <fg=yellow>$cardCount</>");
        }

        $this->output()->writeln('');
      }
    }
  }

  /**
   * Displays a colorful progress bar for component creation.
   *
   * @param int $totalComponents
   *   The total number of components to be processed.
   */
  protected function formatProgressBar(int $totalComponents): void {
    $this->output()->writeln('');
    $this->output()->writeln('<fg=blue;options=bold>⏳ Creating components...</>');

    // Simple progress bar
    $barWidth = 50;
    $colors = ['red', 'yellow', 'green', 'cyan', 'blue', 'magenta'];

    for ($i = 0; $i <= $barWidth; $i++) {
      $percent = floor(($i / $barWidth) * 100);
      $colorIndex = $i % count($colors);
      $color = $colors[$colorIndex];

      // Create the progress bar
      $progress = str_repeat('=', $i);
      $remaining = str_repeat(' ', $barWidth - $i);

      // Add some dynamic icons based on progress
      $icons = ['📌', '🏢', '🏔️', '🃏', '💬', '✨'];
      $icon = $icons[$i % count($icons)];

      // Display the progress bar with color
      $this->output()->write("\r<fg=$color>$icon [$progress>$remaining] $percent%</>");

      // Simulate the process for the progress bar
      usleep(20000); // 20ms delay
    }

    $this->output()->writeln('');
    $this->output()->writeln('<fg=green;options=bold>✅ Ready to add components!</>');
    $this->output()->writeln('');
  }

}
