<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Service for reading component files.
 */
class ComponentReaderService
{

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructor for ComponentReaderService.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory)
  {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Prompt the user for the component folder name.
   */
  public function askComponentFolder(StyleInterface $io)
  {
    $componentDir = '../nextjs/components/';
    $components = scandir($componentDir);
    $components = array_filter(
      $components,
      function ($file) {
        return is_dir("../nextjs/components/$file") && $file != '.' && $file != '..';
      }
    );

    $selectedIndex = $io->choice('Select a component folder to import', $components);
    return $components[$selectedIndex];
  }

  /**
   * Read the component file, story file, and return their contents.
   */
  public function readComponentFiles($componentFolderName, StyleInterface $io)
  {
    $componentPath = "../nextjs/components/{$componentFolderName}";
    if (!is_dir($componentPath)) {
      return [FALSE, FALSE, FALSE];
    }

    $componentFiles = array_filter(
      scandir($componentPath),
      function ($file) {
        return pathinfo($file, PATHINFO_EXTENSION) === 'tsx' && !preg_match('/\.stories\.tsx$/', $file);
      }
    );

    if (empty($componentFiles)) {
      $this->loggerFactory->get('drupalx_ai')->warning("No suitable .tsx files found in the {$componentFolderName} component directory.");
      return [FALSE, FALSE, FALSE];
    }

    $selectedFile = $io->choice(
      "Select a file from the {$componentFolderName} component",
      array_combine($componentFiles, $componentFiles)
    );

    $componentName = pathinfo($selectedFile, PATHINFO_FILENAME);
    $componentFilePath = "{$componentPath}/{$selectedFile}";
    $storyFilePath = "{$componentPath}/{$componentName}.stories.tsx";

    if (!file_exists($componentFilePath) || !is_readable($componentFilePath)) {
      $this->loggerFactory->get('drupalx_ai')->error("Unable to read the selected component file: {$componentFilePath}");
      return [FALSE, FALSE, FALSE];
    }

    $componentContent = file_get_contents($componentFilePath);

    $storyContent = FALSE;
    if (file_exists($storyFilePath) && is_readable($storyFilePath)) {
      $storyContent = file_get_contents($storyFilePath);
    } else {
      $this->loggerFactory->get('drupalx_ai')->warning("Story file not found or not readable: {$storyFilePath}");
    }

    return [$componentName, $componentContent, $storyContent];
  }
}
