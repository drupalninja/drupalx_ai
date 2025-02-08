<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Provides component import functionality for Next.js and Drupal themes.
 */
class ComponentReaderService {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a ComponentReaderService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Prompts user to select a component folder.
   *
   * @param \Symfony\Component\Console\Style\StyleInterface $io
   *   The Symfony console style interface.
   * @param bool $auto_confirm
   *   Whether to automatically select the first option.
   *
   * @return string
   *   The selected component folder name.
   */
  public function askComponentFolder(StyleInterface $io, bool $auto_confirm = FALSE): string {
    // Get theme configuration.
    $config = $this->configFactory->get('drupalx_ai.settings');
    $is_nextjs = $config->get('is_nextjs');

    // Set component directory based on theme type.
    if ($is_nextjs) {
      $component_dir = '../nextjs/components/';
    }
    else {
      $component_dir = \Drupal::service('theme.manager')->getActiveTheme()->getPath() . '/components/';
    }

    // Get list of component folders.
    $components = scandir($component_dir);
    $components = array_filter(
      $components,
      function ($file) use ($component_dir) {
        return is_dir($component_dir . $file) && !in_array($file, ['.', '..']);
      }
    );

    if ($auto_confirm) {
      return reset($components);
    }

    return $components[$io->choice('Select a component folder to import', $components)];
  }

  /**
   * Reads component files from the selected folder.
   *
   * @param string $component_folder_name
   *   The name of the folder containing the component files.
   * @param \Symfony\Component\Console\Style\StyleInterface $io
   *   The Symfony console style interface.
   * @param bool $auto_confirm
   *   Whether to automatically select the first option.
   *
   * @return array
   *   An array containing:
   *   - string|false $component_name: The name of the component.
   *   - string|false $component_content: The content of the component file.
   *   - string|false $story_content: The content of the story file.
   */
  public function readComponentFiles(string $component_folder_name, StyleInterface $io, bool $auto_confirm = FALSE): array {
    $logger = $this->loggerFactory->get('drupalx_ai');

    // Get theme configuration.
    $config = $this->configFactory->get('drupalx_ai.settings');
    $is_nextjs = $config->get('is_nextjs');

    // Set component path based on theme type.
    if ($is_nextjs) {
      $component_path = "../nextjs/components/{$component_folder_name}";
    }
    else {
      $component_path = \Drupal::service('theme.manager')->getActiveTheme()->getPath() . "/components/{$component_folder_name}";
    }

    // Verify component directory exists.
    if (!is_dir($component_path)) {
      $logger->error('Component directory not found: @path', ['@path' => $component_path]);
      return [FALSE, FALSE, FALSE];
    }

    // Get list of component files based on theme type.
    if ($is_nextjs) {
      // For Next.js, look for .tsx files that aren't stories.
      $component_files = array_filter(
        scandir($component_path),
        function ($file) {
          return pathinfo($file, PATHINFO_EXTENSION) === 'tsx'
            && !str_contains($file, '.stories.tsx');
        }
      );
    }
    else {
      // For Drupal, look for .twig files.
      $component_files = array_filter(
        scandir($component_path),
        function ($file) {
          return pathinfo($file, PATHINFO_EXTENSION) === 'twig';
        }
      );
    }

    // Check if any suitable files were found.
    if (empty($component_files)) {
      $logger->warning('No suitable @type files found in the @folder component directory.', [
        '@type' => $is_nextjs ? 'TSX' : 'Twig',
        '@folder' => $component_folder_name,
      ]);
      return [FALSE, FALSE, FALSE];
    }

    // Select the first file if auto-confirm is true, otherwise let user choose.
    $selected_file = $auto_confirm ? reset($component_files) : $io->choice(
      "Select a file from the {$component_folder_name} component",
      array_combine($component_files, $component_files)
    );

    // Get component name and build file paths.
    $component_name = pathinfo($selected_file, PATHINFO_FILENAME);
    $component_file_path = "{$component_path}/{$selected_file}";

    // Set story file path based on theme type.
    if ($is_nextjs) {
      $story_file_path = "{$component_path}/{$component_name}.stories.tsx";
    }
    else {
      $story_file_path = "{$component_path}/{$component_name}.stories.js";
    }

    // Verify component file is readable.
    if (!is_readable($component_file_path)) {
      $logger->error('Unable to read the selected component file: @path', [
        '@path' => $component_file_path,
      ]);
      return [FALSE, FALSE, FALSE];
    }

    // Read component file.
    $component_content = file_get_contents($component_file_path);

    // Try to read story file if it exists.
    $story_content = FALSE;
    if (is_readable($story_file_path)) {
      $story_content = file_get_contents($story_file_path);
    }
    else {
      $logger->warning('Story file not found or not readable: @path', [
        '@path' => $story_file_path,
      ]);
    }

    return [
      $component_name,
      $component_content,
      $story_content,
    ];
  }

}
