<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

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
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ValidationService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->moduleHandler = $module_handler;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->logger = $logger_factory->get('drupalx_ai');
  }

  /**
   * Validates generated components against sample components.
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
   */
  protected function detectHallucinatedFields(array $component, array $sample, array $path, array &$results, int $component_number, string $type, int $index, array $component_for_reporting): void {
    foreach ($component as $field => $value) {
      if ($field === 'type') {
        continue;
      }
      $current_path = array_merge($path, [$field]);
      $path_string = implode('.', $current_path);
      // Skip empty/non-hallucinated fields.
      if (isset($sample[$field])) {
        if (is_array($value) && is_array($sample[$field])) {
          $this->detectHallucinatedFields($value, $sample[$field], $current_path, $results, $component_number, $type, $index, $component_for_reporting);
        }
        continue;
      }
      $results['warnings'][] = [
        'error_type' => 'hallucinated_field',
        'component_index' => $index,
        'field_path' => $path_string,
        'message' => $this->t("Component #@num (type: '@type') contains a field '@field' that is not defined in sample components.", ['@num' => $component_number, '@type' => $type, '@field' => $path_string]),
        'component' => $component_for_reporting,
      ];
    }
  }

  /**
   * Determines if a field in a sample component is required.
   */
  protected function detectRequiredField(string $field_name, array $sample_component): bool {
    return isset($sample_component[$field_name]);
  }

  /**
   * Checks if an array is a list (sequential numeric keys).
   */
  protected function detectIsList(array $array): bool {
    if (empty($array)) {
      return FALSE;
    }
    return array_keys($array) === range(0, count($array) - 1);
  }

  /**
   * Checks if an array is an associative array (object-like structure).
   */
  protected function detectIsObject(array $array): bool {
    return !empty($array) && !$this->detectIsList($array);
  }

  /**
   * Provides mapping of AI component types to Drupal paragraph bundle types.
   *
   * @return array
   *   The type mapping array where keys are AI component types and values are
   *   corresponding Drupal paragraph bundle machine names.
   */
  protected function getComponentTypeMapping(): array {
    return [
      // Direct component type mappings based on sample-components.json.
      'hero' => 'hero',
      'card_group' => 'card_group',
      'quote' => 'quote',
      'logo_collection' => 'logo_collection',
      'newsletter' => 'newsletter',
      'accordion' => 'accordion',
      'carousel' => 'carousel',
      'gallery' => 'gallery',
      'pricing' => 'pricing',
      'sidebyside' => 'sidebyside',

      // Additional mappings for potential AI-generated variants.
      'banner' => 'hero',
      'slider' => 'carousel',
      'cards' => 'card_group',
      'testimonial' => 'quote',
      'partners' => 'logo_collection',
      'subscribe' => 'newsletter',
      'faq' => 'accordion',
      'image_gallery' => 'gallery',
      'side_by_side' => 'sidebyside',
    ];
  }

  /**
   * Prepares sample components indexed by their type.
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
   * Loads sample components from the specified file.
   *
   * @param string $sample_path
   *   Path to the sample components JSON file. If not provided, will use the
   *   default path.
   *
   * @return array
   *   An array containing 'status', 'message', and 'data'.
   *   Status can be 'success' or an error code.
   *   Data contains the loaded components if successful.
   */
  public function loadSampleComponents(string $sample_path = NULL): array {
    $result = [
      'status' => 'success',
      'message' => $this->t('Sample components loaded successfully.'),
      'data' => [],
    ];
    if ($sample_path === NULL) {
      $module_extension = $this->moduleHandler->getModule('drupalx_ai');
      if (!$module_extension) {
        $result['status'] = 'error_module_path';
        $result['message'] = $this->t('Could not load drupalx_ai module extension.');
        return $result;
      }
      $module_path = $module_extension->getPath();
      $sample_path = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $module_path . '/files/sample-components.json';
    }
    if (!file_exists($sample_path)) {
      $result['status'] = 'error_loading_samples';
      $result['message'] = $this->t('Sample components JSON file not found at @path', ['@path' => $sample_path]);
      return $result;
    }
    $sample_json_content = @file_get_contents($sample_path);
    if ($sample_json_content === FALSE) {
      $result['status'] = 'error_loading_samples';
      $result['message'] = $this->t('Failed to read sample components JSON file from @path', ['@path' => $sample_path]);
      return $result;
    }
    $all_sample_components = json_decode($sample_json_content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $result['status'] = 'error_decoding_samples';
      $result['message'] = $this->t('Failed to decode sample components JSON: @error (Path: @path)',
        ['@error' => json_last_error_msg(), '@path' => $sample_path]);
      return $result;
    }
    $result['data'] = $all_sample_components;
    return $result;
  }

  /**
   * Validates sample components against existing paragraph bundles.
   *
   * @param array $sample_components
   *   The loaded sample components to validate.
   *
   * @return array
   *   An array with validation results.
   */
  public function validateAgainstParagraphBundles(array $sample_components): array {
    $result = [
      'status' => 'success',
      'valid_components' => [],
      'allowed_types' => [],
      'message' => $this->t('Sample components validated successfully.'),
    ];

    // Get available paragraph bundles.
    $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    if (empty($paragraph_bundles)) {
      $result['status'] = 'no_paragraph_bundles';
      $result['message'] = $this->t('No paragraph bundles available.');
      $this->logger->warning('Failed to validate components: No paragraph bundles available.');
      return $result;
    }

    // Get component type mapping for fixing component names.
    $component_type_mapping = $this->getComponentTypeMapping();

    foreach ($sample_components as $key => $component_data) {
      // Handle both associative arrays with type as key and numeric arrays
      // where type is specified in the component data.
      $component_type = '';

      // If the component data is an array and has a 'type' key, use that as the
      // component type.
      if (is_array($component_data) && isset($component_data['type'])) {
        $component_type = $component_data['type'];
      }
      // If the key is not numeric, it might be the component type.
      elseif (!is_numeric($key)) {
        $component_type = $key;
      }
      // Otherwise, use the numeric key as a fallback.
      else {
        $component_type = $key;
      }

      // Check if we need to map this component type to a valid paragraph
      // bundle.
      $mapped_type = $component_type;
      if (isset($component_type_mapping[$component_type])) {
        $mapped_type = $component_type_mapping[$component_type];
      }

      // Check if the mapped type exists as a paragraph bundle.
      if (!isset($paragraph_bundles[$mapped_type])) {
        $this->logger->error(
          'Paragraph bundle @type does not exist. Skipping component: @name',
          ['@type' => $mapped_type, '@name' => $component_type]
        );
        continue;
      }

      // Store with the original key to maintain the same structure.
      $result['valid_components'][$key] = $component_data;
      // If component data has a type field, update it to the mapped type.
      if (is_array($component_data) && isset($component_data['type'])) {
        $result['valid_components'][$key]['type'] = $mapped_type;
      }
      // Store the mapped type in allowed_types for further processing.
      $result['allowed_types'][] = $mapped_type;
    }

    if (empty($result['valid_components'])) {
      $result['status'] = 'no_valid_components';
      $result['message'] = $this->t('No valid components found.');
      $this->logger->warning('Failed to validate components: No valid components found.');
    }

    return $result;
  }

  /**
   * Performs a full validation of AI-generated components.
   *
   * This is the main public method to be called by other services.
   */
  public function performFullValidation(array $ai_components_to_validate): array {
    $default_return = [
      'status' => 'success',
      'message' => $this->t('Validation completed.'),
      'results' => ['errors' => [], 'warnings' => []],
    ];

    // Use the loadSampleComponents method instead of duplicating the logic.
    $samples_result = $this->loadSampleComponents();
    if ($samples_result['status'] !== 'success') {
      $default_return['status'] = $samples_result['status'];
      $default_return['message'] = $samples_result['message'];
      return $default_return;
    }

    $sample_components_by_type = $this->prepareSamplesByType($samples_result['data']);
    if (empty($sample_components_by_type) && !empty($samples_result['data'])) {
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
