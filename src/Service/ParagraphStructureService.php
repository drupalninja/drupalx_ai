<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Service for retrieving paragraph structure information.
 */
class ParagraphStructureService
{

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
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Get the structure of all paragraph bundles.
   *
   * @return array
   *   An array of paragraph bundle structures.
   */
  public function getParagraphStructures()
  {
    $paragraph_types = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    $output = [];

    foreach ($paragraph_types as $paragraph_type) {
      $bundle_id = $paragraph_type->id();
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
  protected function getListStringOptions($field_definition)
  {
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
  protected function getExampleValue($field_definition)
  {
    $field_type = $field_definition->getType();
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
                'value' => $this->getExampleValue($target_field_definition)
              ];
            }
          }
          return (object) [
            'bundle' => $target_bundle,
            'fields' => $example_fields
          ];
        }
        return null;
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
  public function getMaterialIconNames()
  {
    return [
      'home',
      'search',
      'menu',
      'close',
      'arrow_back',
      'arrow_forward',
      'settings',
      'account_circle',
      'add',
      'delete',
      'edit',
      'favorite',
      'star',
      'check',
      'mail',
      'notification_important',
      'person',
      'lock',
      'calendar_today',
      'file_download',
      'file_upload',
      'cloud_upload',
      'cloud_download',
      'share',
      'thumb_up',
      'thumb_down',
      'visibility',
      'visibility_off',
      'refresh',
      'error',
      'warning',
      'info',
      'help',
      'phone',
      'email',
      'message',
      'chat',
      'location_on',
      'map',
      'directions',
      'access_time',
      'date_range',
      'shopping_cart',
      'credit_card',
      'attach_file',
      'link',
      'play_arrow',
      'pause',
      'stop',
      'skip_next',
      'skip_previous',
      'volume_up',
      'volume_down',
      'volume_mute',
      'mic',
      'camera',
      'photo',
      'video_camera',
      'bluetooth',
      'wifi',
      'battery_full',
      'power',
      'more_vert',
      'more_horiz',
      'add_circle',
      'remove_circle',
      'check_circle',
      'cancel',
      'clear',
      'done',
      'undo',
      'redo',
      'print',
      'save',
      'bookmark',
      'flag',
      'language',
      'translate',
      'build',
      'code',
      'dashboard',
      'report',
      'trending_up',
      'trending_down',
      'pie_chart',
      'bar_chart',
      'timeline',
      'list',
      'grid_view',
      'table_view',
      'filter_list',
      'sort',
      'zoom_in',
      'zoom_out',
      'fullscreen',
      'exit_to_app',
      'apps',
      'category',
      'tag',
      'label'
    ];
  }
}
