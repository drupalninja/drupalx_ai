<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Service for retrieving paragraph structure information.
 */
class ParagraphStructureService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new ParagraphStructureService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Get the structure of all paragraph bundles.
   *
   * @param bool $exclude_views
   *   Exclude Views paragraph type.
   *
   * @return array
   *   An array of paragraph bundle structures.
   */
  public function getParagraphStructures($exclude_views = FALSE) {
    $paragraph_types = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    $output = [];

    foreach ($paragraph_types as $paragraph_type) {
      $bundle_id = $paragraph_type->id();

      // Skip the 'views' paragraph type if $exclude_views is TRUE.
      if ($exclude_views && $bundle_id === 'views') {
        continue;
      }

      $field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $bundle_id);

      $paragraph_info = (object) [
        'b' => $bundle_id,
        'f' => [],
      ];

      foreach ($field_definitions as $field_name => $field_definition) {
        if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }

        $field_type = $field_definition->getType();

        $field_info = (object) [
          't' => $field_type,
          'n' => $field_name,
          'r' => $field_definition->isRequired(),
          'c' => $field_definition->getFieldStorageDefinition()->getCardinality(),
        ];

        if ($field_type === 'entity_reference') {
          $settings = $field_definition->getSettings();
          $field_info->target_type = $settings['target_type'];
        }

        if ($field_type === 'list_string') {
          $field_info->o = $this->getListStringOptions($field_definition);
        }

        $field_info->e = $this->getExampleValue($field_definition);

        $paragraph_info->f[] = $field_info;
      }

      $output[] = $paragraph_info;
    }

    return $output;
  }

  /**
   * Get the options for a list_string field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   An array of options for the list_string field.
   */
  protected function getListStringOptions(FieldDefinitionInterface $field_definition) {
    $settings = $field_definition->getSettings();
    return $settings['allowed_values'] ?? [];
  }

  /**
   * Get an example value for a given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return mixed
   *   An example value for the field.
   */
  protected function getExampleValue(FieldDefinitionInterface $field_definition) {
    $field_type = $field_definition->getType();
    $field_name = $field_definition->getName();

    // Special handling for field_features_text.
    if ($field_name === 'field_features_text') {
      return "Feature number 1\nFeature number 2\nFeature number 3";
    }

    switch ($field_type) {
      case 'string':
      case 'string_long':
      case 'text':
      case 'text_long':
        return "Example text for {$field_type}";

      case 'integer':
      case 'decimal':
      case 'float':
        return 42;

      case 'boolean':
        return true;

      case 'email':
        return 'example@example.com';

      case 'telephone':
        return '+1234567890';

      case 'datetime':
        return '2023-05-17T12:00:00';

      case 'link':
        return (object) ['url' => 'http://example.com', 'text' => 'Example Link'];

      case 'entity_reference':
        $settings = $field_definition->getSettings();
        if (in_array($settings['target_type'], ['media', 'file'])) {
          return "Technology";
        }
        return "Reference to {$settings['target_type']}";

      case 'entity_reference_revisions':
        $target_bundles = $field_definition->getSetting('handler_settings')['target_bundles'] ?? [];
        if (!empty($target_bundles)) {
          $target_bundle = reset($target_bundles);
          $target_field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $target_bundle);
          $example_fields = [];
          foreach ($target_field_definitions as $target_field_name => $target_field_definition) {
            if (!$target_field_definition->getFieldStorageDefinition()->isBaseField()) {
              $example_fields[] = (object) [
                'name' => $target_field_name,
                'value' => $this->getExampleValue($target_field_definition),
              ];
            }
          }
          return (object) [
            'bundle' => $target_bundle,
            'fields' => $example_fields,
          ];
        }
        return NULL;

      case 'list_string':
        $options = $this->getListStringOptions($field_definition);
        return !empty($options) ? reset($options) : "Example list_string value";

      default:
        return "Example value for {$field_type}";
    }
  }

  /**
   * Get an array of popular Material Icon names.
   *
   * @return array
   *   An array of 100 popular Material Icon names.
   */
  public function getMaterialIconNames() {
    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');
    $file_path = DRUPAL_ROOT . '/' . $module_path . '/files/material-icon-names.txt';
    if (file_exists($file_path)) {
      $icon_names = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      return $icon_names;
    }
    return [];
  }

  /**
   * Finds the best matching Material icon name for a given search term.
   *
   * This function searches for an icon name in the material-icon-names.txt file.
   * It first looks for an exact match, then for a partial match if no exact match
   * is found. If no suitable match is found, it defaults to 'star'.
   *
   * @param string $search_term
   *   The search term to find a matching icon name for.
   *
   * @return string
   *   The best matching icon name, or 'star' if no suitable match is found.
   */
  public function getBestIconMatch($search_term) {
    // Check if the file exists.
    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');
    $filename = DRUPAL_ROOT . '/' . $module_path . '/files/material-icon-names.txt';

    if (!file_exists($filename)) {
      return 'star';
    }

    // Read the file contents.
    $icon_names = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Search for an exact match (case-insensitive).
    $search_term_lower = mb_strtolower($search_term);
    foreach ($icon_names as $icon_name) {
      if (mb_strtolower($icon_name) === $search_term_lower) {
        return $icon_name;
      }
    }

    // If no exact match, find the best partial match.
    $best_match = '';
    $highest_similarity = 0;

    foreach ($icon_names as $icon_name) {
      similar_text($search_term_lower, mb_strtolower($icon_name), $percent);
      if ($percent > $highest_similarity) {
        $highest_similarity = $percent;
        $best_match = $icon_name;
      }
    }

    // If a partial match is found and it's reasonably similar, return it.
    if ($best_match !== '' && $highest_similarity > 50) {
      return $best_match;
    }

    // If no good match found, default to 'star'.
    return 'star';
  }

}
