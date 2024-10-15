<?php

namespace Drupal\drupalx_ai\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupalx_ai\Service\AiModelApiService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Provides Drush commands for updating Tailwind theme using AI.
 */
final class UpdateTailwindThemeCommands extends DrushCommands {

  /**
   * The Symfony filesystem service.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  private Filesystem $filesystem;

  /**
   * Constructs an UpdateTailwindThemeCommands object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\drupalx_ai\Service\AiModelApiService $aiModelApiService
   *   The AI Model API service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiModelApiService $aiModelApiService,
  ) {
    parent::__construct();
    $this->filesystem = new Filesystem();
  }

  /**
   * Update the Tailwind theme interactively using AI.
   *
   * @command drupalx:update-tailwind-theme
   * @aliases dutt
   * @usage drush drupalx:update-tailwind-theme
   *
   * @throws \RuntimeException
   *   Throws an exception if there's an error reading or writing files,
   *   or if the AI fails to generate an updated theme.
   */
  public function updateTailwindTheme(InputInterface $input, OutputInterface $output): void {
    $globalsPath = DRUPAL_ROOT . '/../nextjs/app/globals.css';

    if (!$this->filesystem->exists($globalsPath)) {
      throw new \RuntimeException("globals.css file not found at {$globalsPath}");
    }

    $currentCss = file_get_contents($globalsPath);

    if ($currentCss === FALSE) {
      throw new \RuntimeException("Failed to read the contents of {$globalsPath}");
    }

    $question = new Question('Enter your request for updating the Tailwind theme (e.g., "Make the color scheme more vibrant and increase contrast"):');
    $question->setValidator(function ($answer) {
      if (empty($answer)) {
        throw new \RuntimeException('The prompt cannot be empty.');
      }
      return $answer;
    });

    $prompt = $this->io()->askQuestion($question);

    $aiPrompt = $this->buildAiPrompt($currentCss, $prompt);
    $tools = $this->defineAiTools();

    $output->writeln("Generating updated Tailwind theme based on your input...");

    try {
      $result = $this->aiModelApiService->callAiApi($aiPrompt, $tools, 'update_tailwind_theme');

      if (!is_array($result) || !isset($result['updated_css']) || !isset($result['changes_summary'])) {
        throw new \RuntimeException("AI failed to generate an updated Tailwind theme or returned unexpected result");
      }

      $this->writeGlobalsCss($globalsPath, $result['updated_css']);

      $this->io()->success("Tailwind theme has been updated successfully in globals.css.");
      $output->writeln("Summary of changes:");

      // Handle both array and string responses for changes_summary.
      $changesSummary = $result['changes_summary'];
      if (is_array($changesSummary)) {
        foreach ($changesSummary as $change) {
          $output->writeln("- $change");
        }
      }
      elseif (is_string($changesSummary)) {
        $output->writeln("$changesSummary");
      }
      else {
        $output->writeln("No changes summary provided.");
      }
    }
    catch (\Exception $e) {
      $this->logger()->error("Error while updating Tailwind theme: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Builds the AI prompt for updating the Tailwind theme.
   *
   * @param string $currentCss
   *   The current CSS content.
   * @param string $userPrompt
   *   The user's request for updating the theme.
   *
   * @return string
   *   The constructed AI prompt.
   */
  private function buildAiPrompt(string $currentCss, string $userPrompt): string {
    return <<<EOT
Here's the current Tailwind theme configuration in globals.css:

{$currentCss}

Update this Tailwind configuration based on the following request: {$userPrompt}.
Focus on modifying color schemes, spacing, or any other Tailwind-specific properties.
Do not change the font-fmaily.
Provide a summary of key changes made to the configuration.
EOT;
  }

  /**
   * Defines the AI tools for updating the Tailwind theme.
   *
   * @return array
   *   The AI tools configuration.
   */
  private function defineAiTools(): array {
    return [
      [
        'name' => 'update_tailwind_theme',
        'description' => 'Update the Tailwind theme configuration',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'updated_css' => [
              'type' => 'string',
              'description' => 'The updated Tailwind CSS configuration',
            ],
            'changes_summary' => [
              'type' => 'array',
              'items' => [
                'type' => 'string',
              ],
              'description' => 'A list of key changes made to the Tailwind configuration',
            ],
          ],
          'required' => ['updated_css', 'changes_summary'],
        ],
      ],
    ];
  }

  /**
   * Writes the updated CSS to the globals.css file.
   *
   * @param string $path
   *   The path to the globals.css file.
   * @param string $content
   *   The updated CSS content.
   *
   * @throws \RuntimeException
   *   If writing to the file fails.
   */
  protected function writeGlobalsCss(string $path, string $content): void {
    if (file_put_contents($path, $content) === FALSE) {
      throw new \RuntimeException("Failed to write the updated Tailwind theme to {$path}");
    }
  }

}
