<?php

/**
 * @file
 * Contains validation functions for generated components.
 */

/**
 * Validates generated components against sample components.
 *
 * @param array $components
 *   The array of components to validate.
 * @param array $sample_components_by_type
 *   Sample components organized by type.
 *
 * @return array
 *   Validation results with errors and warnings.
 */
function validate_components(array $components, array $sample_components_by_type) {
  $results = [
    'errors' => [],
    'warnings' => [],
  ];

  // Keep original for error reporting.
  $original_components = unserialize(serialize($components));
  $components = array_values($original_components);

  foreach ($components as $index => $component) {
    $component_number = intval($index) + 1;
    // Use original for context.
    $component_for_reporting = $original_components[$index];

    if (!isset($component['type'])) {
      $results['errors'][] = [
        'error_type' => 'missing_type',
        'message' => "Component #" . $component_number . " is missing a 'type' field.",
        'component' => $component_for_reporting,
      ];
      continue;
    }

    $type = $component['type'];

    if (!isset($sample_components_by_type[$type])) {
      $results['errors'][] = [
        'error_type' => 'invalid_type',
        'message' => "Component #" . $component_number . " has an invalid type: '" . $type . "'.",
        'component' => $component_for_reporting,
      ];
      continue;
    }

    // We have a valid type, compare with sample.
    $sample_component = $sample_components_by_type[$type];

    // Check for missing required fields.
    foreach ($sample_component as $field => $sample_value) {
      // Skip type field, we already validated it.
      if ($field === 'type') {
        continue;
      }

      // Check for required field.
      $is_required_field = detect_required_field($field, $sample_component);

      if ($is_required_field && !isset($component[$field])) {
        $results['errors'][] = [
          'error_type' => 'missing_required_field',
          'component_index' => $index,
          'field' => $field,
          'message' => "Component #" . $component_number . " (type: '" . $type . "') is missing required field: '" . $field . "'.",
          'component' => $component_for_reporting,
        ];
      }

      // Skip further validation if field not present in component.
      if (!isset($component[$field])) {
        continue;
      }

      // Check for array types and recursively validate nested structure.
      if (is_array($sample_value) && is_array($component[$field])) {
        validate_nested_field($field, $component[$field], $sample_value, $results, $component_number, $type, $index, $component_for_reporting);
      }
      elseif (is_array($sample_value) && !is_array($component[$field])) {
        $results['errors'][] = [
          'error_type' => 'wrong_field_type',
          'component_index' => $index,
          'field' => $field,
          'message' => "Component #" . $component_number . " (type: '" . $type . "'): field '" . $field . "' should be an array, but is a scalar value.",
          'component' => $component_for_reporting,
        ];
      }
    }

    // Check for hallucinated fields (present in component but not in sample).
    detect_hallucinated_fields($component, $sample_component, [], $results, $component_number, $type, $index);
  }

  return $results;
}

/**
 * Validates nested fields in components.
 *
 * @param string $field_path
 *   The field path.
 * @param array $component_field
 *   The component field to validate.
 * @param array $sample_field
 *   The sample field to validate against.
 * @param array $results
 *   The validation results array.
 * @param int $component_number
 *   The component number.
 * @param string $type
 *   The component type.
 * @param int $index
 *   The component index.
 * @param array $component_for_reporting
 *   The component for reporting.
 * @param array $path
 *   The current path in the component.
 */
function validate_nested_field($field_path, array $component_field, array $sample_field, array &$results, $component_number, $type, $index, array $component_for_reporting, array $path = []) {
  // Special handling for array types.
  if (detect_is_list($sample_field)) {
    // This is a list of items, such as field_provus_items or field_provus_links.
    $new_path = array_merge($path, [$field_path]);

    // If sample is empty, any array is valid.
    if (empty($sample_field)) {
      return;
    }

    // Get the first sample item as template.
    $sample_item = $sample_field[0];
    if (!is_array($sample_item)) {
      // If sample item isn't an array, no further validation needed.
      return;
    }

    // Validate each item in the component list.
    foreach ($component_field as $item_index => $component_item) {
      if (!is_array($component_item)) {
        $results['warnings'][] = [
          'component_index' => $index,
          'path' => $new_path,
          'index' => $item_index,
          'message' => "Component #" . $component_number . " (type: '" . $type . "'): item at index " . $item_index . " in '" . $field_path . "' should be an object, but is a scalar value.",
          'component' => $component_for_reporting,
        ];
        continue;
      }

      // Recursively detect hallucinated fields in this item.
      detect_hallucinated_fields(
        $component_item,
        $sample_item,
        array_merge($path, [$field_path, $item_index]),
        $results,
        $component_number,
        $type,
        $index
      );

      // Recursively validate required fields in this item.
      foreach ($sample_item as $sub_field => $sub_sample_value) {
        $is_sub_field_required = detect_required_field($sub_field, $sample_item);

        if ($is_sub_field_required && !isset($component_item[$sub_field])) {
          $msg_part1 = "Component #" . $component_number . " (type: '" . $type . "'): ";
          $msg_part2 = "item at index " . $item_index . " in '" . $field_path . "' ";
          $msg_part3 = "is missing required field '" . $sub_field . "'.";
          $results['errors'][] = [
            'error_type' => 'missing_required_field',
            'component_index' => $index,
            'path' => array_merge($new_path, [$item_index]),
            'field' => $sub_field,
            'message' => $msg_part1 . $msg_part2 . $msg_part3,
            'component' => $component_for_reporting,
          ];
        }

        // Skip further validation if field not present in component item.
        if (!isset($component_item[$sub_field])) {
          continue;
        }

        // Recursive validation for nested arrays.
        if (is_array($sub_sample_value) && is_array($component_item[$sub_field])) {
          validate_nested_field($sub_field, $component_item[$sub_field], $sub_sample_value, $results, $component_number, $type, $index, $component_for_reporting, array_merge($new_path, [$item_index]));
        }
      }
    }
  }
  elseif (detect_is_object($sample_field)) {
    // This is a nested object, validate its fields.
    $new_path = array_merge($path, [$field_path]);

    // Recursively detect hallucinated fields.
    detect_hallucinated_fields($component_field, $sample_field, $new_path, $results, $component_number, $type, $index);

    // Validate each field in the nested object.
    foreach ($sample_field as $sub_field => $sub_sample_value) {
      $is_sub_field_required = detect_required_field($sub_field, $sample_field);

      if ($is_sub_field_required && !isset($component_field[$sub_field])) {
        $results['errors'][] = [
          'error_type' => 'missing_required_field',
          'component_index' => $index,
          'path' => $new_path,
          'field' => $sub_field,
          'message' => "Component #" . $component_number . " (type: '" . $type . "'): object '" . $field_path . "' is missing required field '" . $sub_field . "'.",
          'component' => $component_for_reporting,
        ];
      }

      // Skip further validation if field not present in component.
      if (!isset($component_field[$sub_field])) {
        continue;
      }

      // Recursive validation for nested arrays.
      if (is_array($sub_sample_value) && is_array($component_field[$sub_field])) {
        validate_nested_field($sub_field, $component_field[$sub_field], $sub_sample_value, $results, $component_number, $type, $index, $component_for_reporting, $new_path);
      }
    }
  }
}

/**
 * Detects hallucinated fields in a component.
 *
 * @param array $component
 *   The component to check.
 * @param array $sample
 *   The sample component.
 * @param array $path
 *   The current path in the component.
 * @param array $results
 *   The validation results array.
 * @param int $component_number
 *   The component number.
 * @param string $type
 *   The component type.
 * @param int $index
 *   The component index.
 */
function detect_hallucinated_fields(array $component, array $sample, array $path, array &$results, $component_number, $type, $index) {
  foreach ($component as $field => $value) {
    // If field is not in sample, mark as hallucinated.
    if (!isset($sample[$field])) {
      // Add hallucination error.
      $hallucination_path = $path;
      $hallucination_field = $field;

      $results['errors'][] = [
        'error_type' => 'hallucinated',
        'component_index' => $index,
        'path' => $hallucination_path,
        'field' => $hallucination_field,
        'message' => "Component #" . $component_number . " (type: '" . $type . "'): found unexpected field '" . implode(' -> ', array_merge($hallucination_path, [$hallucination_field])) . "'.",
      ];
    }
  }
}

/**
 * Determines if a field in a sample component is required.
 *
 * This function can be customized to define logic for required fields.
 * For instance, a field might be considered required if it does not have a
 * default value, or based on specific naming conventions or annotations in the
 * sample data (not implemented here).
 *
 * @param string $field_name
 *   The name of the field to check.
 * @param array $sample_component
 *   The sample component structure.
 *
 * @return bool
 *   TRUE if the field is considered required, FALSE otherwise.
 */
function detect_required_field($field_name, array $sample_component) {
  // Basic example: consider all fields present in the sample as required.
  // Customize this logic based on your specific needs.
  // For example, you might have a naming convention like '_required_'
  // or a specific key in your sample indicating requirement.
  // Another common approach is to assume fields are required unless they
  // have a default value or are explicitly marked optional.
  return isset($sample_component[$field_name]);
}

/**
 * Checks if an array is a list (sequential numeric keys).
 *
 * @param array $array
 *   The array to check.
 *
 * @return bool
 *   TRUE if the array is a list, FALSE otherwise.
 */
function detect_is_list(array $array) {
  if (empty($array)) {
    // Or TRUE, depending on how you want to treat empty arrays.
    return FALSE;
  }
  return array_keys($array) === range(0, count($array) - 1);
}

/**
 * Checks if an array is an associative array (object-like structure).
 *
 * @param array $array
 *   The array to check.
 *
 * @return bool
 *   TRUE if the array is an object-like structure, FALSE otherwise.
 */
function detect_is_object(array $array) {
  // An array is considered an object if it's not empty and not a list.
  return !empty($array) && !detect_is_list($array);
}

/**
 * Prepares sample components indexed by their type.
 *
 * @param array $all_sample_components_array
 *   The decoded array from sample-components.json.
 *
 * @return array
 *   An associative array of sample components, keyed by component type.
 *   Returns an empty array if the input is invalid or empty.
 */
function drupalx_ai_prepare_samples_by_type(array $all_sample_components_array): array {
  $sample_components_by_type = [];
  if (empty($all_sample_components_array)) {
    // It's useful to log this situation if it's unexpected.
    // However, actual logging should be handled by the calling service
    // which has access to a logger and more context.
    return [];
  }

  foreach ($all_sample_components_array as $sample_component_item) {
    if (isset($sample_component_item['type']) && is_string($sample_component_item['type'])) {
      $sample_components_by_type[$sample_component_item['type']] = $sample_component_item;
    }
    // Optionally, log or handle items without a type if that's an error condition.
  }

  return $sample_components_by_type;
}

/**
 * Performs a full validation of AI-generated components.
 *
 * This function orchestrates loading sample components, preparing them,
 * and then validating the provided AI components against these samples.
 *
 * @param array $ai_components_to_validate
 *   The array of AI-generated components that need validation.
 *
 * @return array
 *   An associative array with the following keys:
 *   - 'status': (string) Overall status: 'success', 'error_module_path',
 *     'error_loading_samples', 'error_decoding_samples',
 *     'error_preparing_samples', 'error_validation_function_missing'.
 *   - 'message': (string) A descriptive message, especially in case of an error status.
 *   - 'results': (array) The raw results from validate_components() or an empty
 *     array ('errors' => [], 'warnings' => []) if validation couldn't run.
 */
function drupalx_ai_perform_full_validation(array $ai_components_to_validate): array {
  $default_return = [
    'status' => 'success',
    'message' => 'Validation completed.',
    'results' => ['errors' => [], 'warnings' => []],
  ];

  $module_path = NULL;
  try {
    $module = \Drupal::moduleHandler()->getModule('drupalx_ai');
    if ($module) {
      $module_path = $module->getPath();
    }
  }
  catch (\Exception $e) {
    $default_return['status'] = 'error_module_path';
    $default_return['message'] = 'Failed to get drupalx_ai module path: ' . $e->getMessage();
    return $default_return;
  }

  if (!$module_path) {
    $default_return['status'] = 'error_module_path';
    $default_return['message'] = 'Could not determine module path for drupalx_ai.';
    return $default_return;
  }

  $sample_json_path_relative = $module_path . '/files/sample-components.json';
  $sample_json_path = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $sample_json_path_relative;

  if (!file_exists($sample_json_path)) {
    $default_return['status'] = 'error_loading_samples';
    $default_return['message'] = 'Sample components JSON file not found at ' . $sample_json_path;
    return $default_return;
  }

  $sample_json_content = file_get_contents($sample_json_path);
  if ($sample_json_content === FALSE) {
    $default_return['status'] = 'error_loading_samples';
    $default_return['message'] = 'Failed to read sample components JSON file from ' . $sample_json_path;
    return $default_return;
  }

  $all_sample_components_array = json_decode($sample_json_content, TRUE);
  if (json_last_error() !== JSON_ERROR_NONE) {
    $default_return['status'] = 'error_decoding_samples';
    $default_return['message'] = 'Failed to decode sample components JSON: ' . json_last_error_msg() . ' (Path: ' . $sample_json_path . ')';
    return $default_return;
  }

  if (!function_exists('drupalx_ai_prepare_samples_by_type')) {
    // This should not happen if validation.inc is included correctly.
    $default_return['status'] = 'error_preparing_samples';
    $default_return['message'] = 'Function drupalx_ai_prepare_samples_by_type() not found.';
    return $default_return;
  }
  $sample_components_by_type = drupalx_ai_prepare_samples_by_type($all_sample_components_array);

  if (empty($sample_components_by_type) && !empty($all_sample_components_array)) {
    // This indicates an issue with the sample file format (e.g. missing 'type').
    $default_return['status'] = 'error_preparing_samples';
    $default_return['message'] = 'Sample components could not be prepared (e.g. missing type fields in sample JSON), though the file was loaded and decoded.';
    // We might still proceed to validate_components if $ai_components_to_validate is also empty.
    // Or, decide to return here. For now, let's allow validate_components to run with empty samples if needed.
  }

  if (!function_exists('validate_components')) {
    $default_return['status'] = 'error_validation_function_missing';
    $default_return['message'] = 'Core validation function validate_components() not found.';
    return $default_return;
  }

  $default_return['results'] = validate_components($ai_components_to_validate, $sample_components_by_type);

  if (!empty($default_return['results']['errors']) || !empty($default_return['results']['warnings'])) {
    $default_return['message'] = 'Validation completed with errors/warnings.';
  }

  return $default_return;
}
