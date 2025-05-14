<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for validating AI-generated components.
 */
class ValidationService {
  use StringTranslationTrait;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a ValidationService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * Validates generated components against sample components.
   * (Previously validate_components in validation.inc)
   */
  protected function validateComponents(array $components, array $sample_components_by_type): array {
    $results = [
      'errors' => [],
      'warnings' => [],
    ];
    $original_components = unserialize(serialize($components));
    $components = array_values($original_components);

    foreach ($components as $index => $component) {
      $component_number = intval($index) + 1;
      $component_for_reporting = $original_components[$index];

      if (!isset($component['type'])) {
        $results['errors'][] = [
          'error_type' => 'missing_type',
          'message' => $this->t("Component #@num is missing a 'type' field.", ['@num' => $component_number]),
          'component' => $component_for_reporting,
        ];
        continue;
      }
      $type = $component['type'];
      if (!isset($sample_components_by_type[$type])) {
        $results['errors'][] = [
          'error_type' => 'invalid_type',
          'message' => $this->t("Component #@num has an invalid type: '@type'.", ['@num' => $component_number, '@type' => $type]),
          'component' => $component_for_reporting,
        ];
        continue;
      }
      $sample_component = $sample_components_by_type[$type];
      foreach ($sample_component as $field => $sample_value) {
        if ($field === 'type') {
          continue;
        }
        $is_required_field = $this->detectRequiredField($field, $sample_component);
        if ($is_required_field && !isset($component[$field])) {
          $results['errors'][] = [
            'error_type' => 'missing_required_field',
            'component_index' => $index,
            'field' => $field,
            'message' => $this->t("Component #@num (type: '@type') is missing required field: '@field'.", ['@num' => $component_number, '@type' => $type, '@field' => $field]),
            'component' => $component_for_reporting,
          ];
        }
        if (!isset($component[$field])) {
          continue;
        }
        if (is_array($sample_value) && is_array($component[$field])) {
          $this->validateNestedField($field, $component[$field], $sample_value, $results, $component_number, $type, $index, $component_for_reporting);
        }
        elseif (is_array($sample_value) && !is_array($component[$field])) {
          $results['errors'][] = [
            'error_type' => 'wrong_field_type',
            'component_index' => $index,
            'field' => $field,
            'message' => $this->t("Component #@num (type: '@type'): field '@field' should be an array, but is a scalar value.", ['@num' => $component_number, '@type' => $type, '@field' => $field]),
            'component' => $component_for_reporting,
          ];
        }
      }
      $this->detectHallucinatedFields($component, $sample_component, [], $results, $component_number, $type, $index, $component_for_reporting);
    }
    return $results;
  }

  /**
   * Validates nested fields in components.
   * (Previously validate_nested_field in validation.inc)
   */
  protected function validateNestedField(string $field_path, array $component_field, array $sample_field, array &$results, int $component_number, string $type, int $index, array $component_for_reporting, array $path = []): void {
    if ($this->detectIsList($sample_field)) {
      $new_path = array_merge($path, [$field_path]);
      if (empty($sample_field)) {
        return;
      }
      $sample_item = $sample_field[0];
      if (!is_array($sample_item)) {
        return;
      }
      foreach ($component_field as $item_index => $component_item) {
        if (!is_array($component_item)) {
          $results['warnings'][] = [
            'component_index' => $index,
            'path' => $new_path,
            'index' => $item_index,
            'message' => $this->t("Component #@num (type: '@type'): item at index @item_idx in '@field_path' should be an object, but is a scalar value.", ['@num' => $component_number, '@type' => $type, '@item_idx' => $item_index, '@field_path' => $field_path]),
            'component' => $component_for_reporting,
          ];
          continue;
        }
        $this->detectHallucinatedFields($component_item, $sample_item, array_merge($path, [$field_path, $item_index]), $results, $component_number, $type, $index, $component_for_reporting);
        foreach ($sample_item as $sub_field => $sub_sample_value) {
          $is_sub_field_required = $this->detectRequiredField($sub_field, $sample_item);
          if ($is_sub_field_required && !isset($component_item[$sub_field])) {
            $results['errors'][] = [
              'error_type' => 'missing_required_field',
              'component_index' => $index,
              'path' => array_merge($new_path, [$item_index]),
              'field' => $sub_field,
              'message' => $this->t("Component #@num (type: '@type'): item at index @item_idx in '@field_path' is missing required field '@sub_field'.", ['@num' => $component_number, '@type' => $type, '@item_idx' => $item_index, '@field_path' => $field_path, '@sub_field' => $sub_field]),
              'component' => $component_for_reporting,
            ];
          }
          if (!isset($component_item[$sub_field])) {
            continue;
          }
          if (is_array($sub_sample_value) && is_array($component_item[$sub_field])) {
            $this->validateNestedField($sub_field, $component_item[$sub_field], $sub_sample_value, $results, $component_number, $type, $index, $component_for_reporting, array_merge($new_path, [$item_index]));
          }
        }
      }
    }
    elseif ($this->detectIsObject($sample_field)) {
      $new_path = array_merge($path, [$field_path]);
      $this->detectHallucinatedFields($component_field, $sample_field, $new_path, $results, $component_number, $type, $index, $component_for_reporting);
      foreach ($sample_field as $sub_field => $sub_sample_value) {
        $is_sub_field_required = $this->detectRequiredField($sub_field, $sample_field);
        if ($is_sub_field_required && !isset($component_field[$sub_field])) {
          $results['errors'][] = [
            'error_type' => 'missing_required_field',
            'component_index' => $index,
            'path' => $new_path,
            'field' => $sub_field,
            'message' => $this->t("Component #@num (type: '@type'): object '@field_path' is missing required field '@sub_field'.", ['@num' => $component_number, '@type' => $type, '@field_path' => $field_path, '@sub_field' => $sub_field]),
            'component' => $component_for_reporting,
          ];
        }
        if (!isset($component_field[$sub_field])) {
          continue;
        }
        if (is_array($sub_sample_value) && is_array($component_field[$sub_field])) {
          $this->validateNestedField($sub_field, $component_field[$sub_field], $sub_sample_value, $results, $component_number, $type, $index, $component_for_reporting, $new_path);
        }
      }
    }
  }

  /**
   * Detects hallucinated fields in a component.
   * (Previously detect_hallucinated_fields in validation.inc)
   */
  protected function detectHallucinatedFields(array $component, array $sample, array $path, array &$results, int $component_number, string $type, int $index, array $component_for_reporting): void {
    foreach ($component as $field => $value) {
      if (!isset($sample[$field])) {
        $hallucination_path_string = implode(' -> ', array_merge($path, [$field]));
        $results['errors'][] = [
          'error_type' => 'hallucinated',
          'component_index' => $index,
          'path' => $path,
          'field' => $field,
          'message' => $this->t("Component #@num (type: '@type'): found unexpected field '@path_string'.", ['@num' => $component_number, '@type' => $type, '@path_string' => $hallucination_path_string]),
          'component' => $component_for_reporting,
        ];
      }
    }
  }

  /**
   * Determines if a field in a sample component is required.
   * (Previously detect_required_field in validation.inc)
   */
  protected function detectRequiredField(string $field_name, array $sample_component): bool {
    return isset($sample_component[$field_name]);
  }

  /**
   * Checks if an array is a list (sequential numeric keys).
   * (Previously detect_is_list in validation.inc)
   */
  protected function detectIsList(array $array): bool {
    if (empty($array)) {
      return FALSE;
    }
    return array_keys($array) === range(0, count($array) - 1);
  }

  /**
   * Checks if an array is an associative array (object-like structure).
   * (Previously detect_is_object in validation.inc)
   */
  protected function detectIsObject(array $array): bool {
    return !empty($array) && !$this->detectIsList($array);
  }

  /**
   * Prepares sample components indexed by their type.
   * (Previously drupalx_ai_prepare_samples_by_type in validation.inc)
   */
  protected function prepareSamplesByType(array $all_sample_components_array): array {
    $sample_components_by_type = [];
    if (empty($all_sample_components_array)) {
      return [];
    }
    foreach ($all_sample_components_array as $sample_component_item) {
      if (isset($sample_component_item['type']) && is_string($sample_component_item['type'])) {
        $sample_components_by_type[$sample_component_item['type']] = $sample_component_item;
      }
    }
    return $sample_components_by_type;
  }

  /**
   * Performs a full validation of AI-generated components.
   * (Previously drupalx_ai_perform_full_validation in validation.inc)
   * This is the main public method to be called by other services.
   */
  public function performFullValidation(array $ai_components_to_validate): array {
    $default_return = [
      'status' => 'success',
      'message' => $this->t('Validation completed.'),
      'results' => ['errors' => [], 'warnings' => []],
    ];

    $module_extension = $this->moduleHandler->getModule('drupalx_ai');
    if (!$module_extension) {
        $default_return['status'] = 'error_module_path';
        $default_return['message'] = $this->t('Could not load drupalx_ai module extension.');
        return $default_return;
    }
    $module_path = $module_extension->getPath();

    $sample_json_path = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $module_path . '/files/sample-components.json';

    if (!file_exists($sample_json_path)) {
      $default_return['status'] = 'error_loading_samples';
      $default_return['message'] = $this->t('Sample components JSON file not found at @path', ['@path' => $sample_json_path]);
      return $default_return;
    }
    $sample_json_content = @file_get_contents($sample_json_path);
    if ($sample_json_content === FALSE) {
      $default_return['status'] = 'error_loading_samples';
      $default_return['message'] = $this->t('Failed to read sample components JSON file from @path', ['@path' => $sample_json_path]);
      return $default_return;
    }
    $all_sample_components_array = json_decode($sample_json_content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $default_return['status'] = 'error_decoding_samples';
      $default_return['message'] = $this->t('Failed to decode sample components JSON: @error (Path: @path)', ['@error' => json_last_error_msg(), '@path' => $sample_json_path]);
      return $default_return;
    }

    $sample_components_by_type = $this->prepareSamplesByType($all_sample_components_array);
    if (empty($sample_components_by_type) && !empty($all_sample_components_array)) {
      $default_return['status'] = 'error_preparing_samples';
      $default_return['message'] = $this->t('Sample components could not be prepared (e.g., missing type fields in sample JSON), though the file was loaded and decoded.');
    }

    $default_return['results'] = $this->validateComponents($ai_components_to_validate, $sample_components_by_type);
    if (!empty($default_return['results']['errors']) || !empty($default_return['results']['warnings'])) {
      $default_return['message'] = $this->t('Validation completed with errors/warnings.');
    }
    return $default_return;
  }

}
