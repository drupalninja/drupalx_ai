<?php

namespace Drupal\drupalx_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\graphql_compose_fragments\FragmentManager;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use GraphQL\Type\Definition\ObjectType;

/**
 * Service for importing paragraph types and creating paragraphs.
 */
class ParagraphImporterService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The fragment manager.
   *
   * @var \Drupal\graphql_compose_fragments\FragmentManager
   */
  protected $fragmentManager;

  /**
   * The integration template content.
   *
   * @var string
   */
  protected $integrationTemplate;

  /**
   * Constructs a new ParagraphImporterService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\graphql_compose_fragments\FragmentManager|null $fragment_manager
   *   (optional) The fragment manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    FragmentManager $fragment_manager = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->fileSystem = $file_system;
    $this->fragmentManager = $fragment_manager;
  }

  /**
   * Import a paragraph type.
   *
   * @param object $paragraph_data
   *   The paragraph data.
   *
   * @return string
   *   The output data.
   */
  public function importParagraphType($paragraph_data) {
    try {
      // Validate required top-level properties.
      $required_properties = ['id', 'name', 'description', 'fields'];
      foreach ($required_properties as $property) {
        if (!property_exists($paragraph_data, $property)) {
          throw new \InvalidArgumentException("Missing required property: $property");
        }
      }

      // First create any child paragraph types if they exist.
      $child_types_output = '';
      if (!empty($paragraph_data->child_types)) {
        foreach ($paragraph_data->child_types as $child_type) {
          // Mark this as a child type so we don't create a test page for it.
          $child_type->is_child_type = TRUE;
          $child_type_result = $this->importParagraphType($child_type);
          $child_types_output .= $child_type_result . "\n";
        }
      }

      // Create the paragraph type.
      $paragraph_type = ParagraphsType::create([
        'id' => $paragraph_data->id,
        'label' => $paragraph_data->name,
        'description' => $paragraph_data->description,
      ]);
      $paragraph_type->save();

      // Get config to determine if we're using NextJS.
      $config = $this->configFactory->get('drupalx_ai.settings');
      $is_nextjs = $config->get('is_nextjs');

      if ($is_nextjs) {
        // Update GraphQL Compose configuration for this paragraph.
        $config = $this->configFactory->getEditable('graphql_compose.settings');

        // Enable the paragraph type in GraphQL configuration.
        $config->set("entity_config.paragraph.{$paragraph_data->id}.enabled", TRUE);
        $config->set("entity_config.paragraph.{$paragraph_data->id}.query_load_enabled", TRUE);
        $config->set("entity_config.paragraph.{$paragraph_data->id}.edges_enabled", TRUE);

        $config->save();

        // Clear GraphQL plugin caches.
        \Drupal::service('plugin.manager.graphql.schema')->clearCachedDefinitions();

        // Clear cache tags related to GraphQL.
        \Drupal::service('cache_tags.invalidator')->invalidateTags(['graphql_schema']);
      }

      // Create fields.
      $field_count = 0;
      foreach ($paragraph_data->fields as $field) {
        // Convert field to array if it's an object.
        $field_array = is_object($field) ? get_object_vars($field) : $field;

        // Validate required field properties.
        $required_field_properties = ['name', 'label', 'type'];
        foreach ($required_field_properties as $property) {
          if (!isset($field_array[$property])) {
            throw new \InvalidArgumentException("Missing required field property: $property");
          }
        }

        $this->createField($paragraph_type->id(), $field_array);
        $field_count++;
      }

      // Create a test paragraph on a test landing page only for parent types.
      $result = '';
      if (empty($paragraph_data->is_child_type)) {
        $result = $this->createParagraph($paragraph_data);
      }

      // Create integration files based on configuration.
      if ($is_nextjs) {
        // Clear all caches to rebuild the GraphQL schema.
        drupal_flush_all_caches();

        // For parent types, create both parent and child components.
        if (empty($paragraph_data->is_child_type) && !empty($paragraph_data->child_types)) {
          $result .= "\n" . $this->createParagraphFragment($paragraph_data->id, $paragraph_data);
        }
        // For standalone types (no children).
        elseif (empty($paragraph_data->is_child_type) && empty($paragraph_data->child_types)) {
          $result .= "\n" . $this->createParagraphFragment($paragraph_data->id);
        }
      }
      else {
        $result .= "\n" . $this->createParagraphTemplate($paragraph_data);
      }

      return $child_types_output . "Paragraph type '{$paragraph_data->name}' successfully created with $field_count fields.\n{$result}";
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Error importing paragraph type: @message', ['@message' => $e->getMessage()]);
      return 'Error importing paragraph type: ' . $e->getMessage();
    }
  }

  /**
   * Create a Drupal template file for a paragraph type.
   *
   * @param object $paragraph_data
   *   The paragraph data object.
   *
   * @return string
   *   Status message about template creation.
   */
  protected function createParagraphTemplate($paragraph_data) {
    $component_name = str_replace('_', '-', $paragraph_data->id);

    // Get the active theme name.
    $active_theme = \Drupal::theme()->getActiveTheme();
    $theme_path = $active_theme->getPath();

    // Create the directory structure relative to theme.
    $component_dir = $theme_path . "/components/{$component_name}";
    $template_dir = "{$component_dir}/templates";
    $template_file = $template_dir . "/paragraph--{$component_name}.html.twig";

    // Create component directory if it doesn't exist.
    $this->fileSystem->prepareDirectory($component_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Create templates subdirectory if it doesn't exist.
    $this->fileSystem->prepareDirectory($template_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Generate field variables for the template.
    $field_variables = [];
    foreach ($paragraph_data->fields as $field) {
      $field_name = 'field_' . (is_array($field) ? $field['name'] : $field->name);
      $field_var_name = is_array($field) ? $field['name'] : $field->name;
      $field_variables[] = "        {$field_var_name}: content.{$field_name}|render|trim";
    }
    $field_vars = implode(",\n", $field_variables);

    // Get the active theme name for the include statement.
    $theme_name = $active_theme->getName();

    // Generate template content.
    $template_content = <<<TWIG
{#
/**
 * @file
 * Default theme implementation to display a {$paragraph_data->name} paragraph.
 *
 * @see template_preprocess_paragraph()
 *
 * @ingroup themeable
 */
#}
{%
  set classes = ['container']
%}

<div{{ attributes.addClass(classes) }}>
  {{ title_prefix }}
  {{ title_suffix }}

  {% block content %}
    {%
      include '{$theme_name}:{$component_name}' with {
        {$field_vars}
      } only
    %}
  {% endblock %}
</div>
TWIG;

    try {
      // Save the template file.
      $this->fileSystem->saveData($template_content, $template_file, FileSystemInterface::EXISTS_REPLACE);
      return "Created paragraph template at {$template_file}";
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Error creating template file: @message', ['@message' => $e->getMessage()]);
      return "Error creating template file: " . $e->getMessage();
    }
  }

  /**
   * Create a field for a paragraph type and update form display.
   *
   * @param string $paragraph_type_id
   *   The ID of the paragraph type.
   * @param array $field_data
   *   The field data.
   */
  protected function createField($paragraph_type_id, array $field_data) {
    $field_name = 'field_' . $field_data['name'];
    $field_type = $field_data['type'];

    // Storage configuration.
    $storage_config = [
      'field_name' => $field_name,
      'entity_type' => 'paragraph',
      'type' => $field_type,
      'cardinality' => $field_data['cardinality'] ?? 1,
    ];

    // Add allowed values for list_string field type.
    if ($field_type === 'list_string' && !empty($field_data['options'])) {
      $allowed_values = [];
      foreach ($field_data['options'] as $value) {
        // Use the value as both the key and label.
        $allowed_values[$value] = $value;
      }
      $storage_config['settings']['allowed_values'] = $allowed_values;
    }
    // Add settings for entity_reference_revisions fields.
    elseif ($field_type === 'entity_reference_revisions') {
      $storage_config['settings'] = [
        'target_type' => 'paragraph',
      ];
    }
    // Add settings for media entity reference fields.
    elseif ($field_type === 'entity_reference' && isset($field_data['target_type']) && $field_data['target_type'] === 'media') {
      $storage_config['settings'] = [
        'target_type' => 'media',
      ];
    }

    // Check if field storage already exists.
    if (!FieldStorageConfig::loadByName('paragraph', $field_name)) {
      FieldStorageConfig::create($storage_config)->save();
    }

    // Create the field instance.
    if (!FieldConfig::loadByName('paragraph', $paragraph_type_id, $field_name)) {
      $field_config = [
        'field_name' => $field_name,
        'entity_type' => 'paragraph',
        'bundle' => $paragraph_type_id,
        'label' => $field_data['label'],
        'required' => $field_data['required'] ?? FALSE,
      ];

      // Add handler settings for entity_reference_revisions fields.
      if ($field_type === 'entity_reference_revisions') {
        $field_config['settings'] = [
          'handler' => 'default:paragraph',
          'handler_settings' => [
            'target_bundles' => [
              $field_data['target_bundle'] => $field_data['target_bundle']
            ],
            'negate' => 0,
            'target_bundles_drag_drop' => [
              $field_data['target_bundle'] => [
                'enabled' => TRUE,
                'weight' => 0
              ]
            ],
          ],
        ];
      }
      // Add handler settings for media entity reference fields.
      elseif ($field_type === 'entity_reference' && isset($field_data['target_type']) && $field_data['target_type'] === 'media') {
        $field_config['settings'] = [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'image' => 'image',
              'remote_video' => 'remote_video',
              'video' => 'video',
              'svg' => 'svg',
            ],
            'sort' => [
              'field' => '_none',
            ],
            'auto_create' => FALSE,
            'auto_create_bundle' => '',
          ],
        ];
      }

      FieldConfig::create($field_config)->save();
    }

    // Update GraphQL Compose configuration for this paragraph field.
    $config = $this->configFactory->getEditable('graphql_compose.settings');
    $config->set("field_config.paragraph.{$paragraph_type_id}.{$field_name}.enabled", TRUE);
    $config->save();

    // Update the form display.
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('paragraph.' . $paragraph_type_id . '.default');

    if (!$form_display) {
      $form_display = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->create([
          'targetEntityType' => 'paragraph',
          'bundle' => $paragraph_type_id,
          'mode' => 'default',
          'status' => TRUE,
        ]);
    }

    // Set appropriate widget type based on field type.
    $widget_type = 'string_textfield';
    $widget_settings = [];

    if ($field_type === 'list_string') {
      $widget_type = 'options_select';
    }
    elseif ($field_type === 'image') {
      $widget_type = 'media_library_widget';
      $widget_settings = [
        'media_types' => ['image'],
      ];
    }
    elseif ($field_type === 'link') {
      $widget_type = 'link_default';
    }
    elseif ($field_type === 'text_long' || $field_type === 'text_with_summary') {
      $widget_type = 'text_textarea';
    }
    elseif ($field_type === 'entity_reference_revisions') {
      $widget_type = 'paragraphs';
    }
    elseif ($field_type === 'entity_reference' && isset($field_data['target_type']) && $field_data['target_type'] === 'media') {
      $widget_type = 'media_library_widget';
      $widget_settings = [
        'media_types' => ['image', 'remote_video', 'video', 'svg'],
      ];
    }

    $form_display->setComponent($field_name, [
      'type' => $widget_type,
      'weight' => 0,
      'settings' => $widget_settings,
    ])->save();

    // Update the view display.
    $view_display = $this->entityTypeManager
      ->getStorage('entity_view_display')
      ->load('paragraph.' . $paragraph_type_id . '.default');

    if (!$view_display) {
      $view_display = $this->entityTypeManager
        ->getStorage('entity_view_display')
        ->create([
          'targetEntityType' => 'paragraph',
          'bundle' => $paragraph_type_id,
          'mode' => 'default',
          'status' => TRUE,
        ]);
    }

    // Set appropriate formatter type based on field type.
    $formatter_type = 'string';
    $formatter_settings = [];

    switch ($field_type) {
      case 'list_string':
        $formatter_type = 'list_key';
        break;

      case 'image':
        $formatter_type = 'image';
        $formatter_settings = [
          'image_style' => 'large',
          'image_link' => '',
        ];
        break;

      case 'link':
        $formatter_type = 'link';
        break;

      case 'text_long':
      case 'text_with_summary':
        $formatter_type = 'text_default';
        break;

      case 'boolean':
        $formatter_type = 'boolean';
        break;

      case 'datetime':
        $formatter_type = 'datetime_default';
        break;

      case 'entity_reference':
        if (isset($field_array['target_type']) && $field_array['target_type'] === 'media') {
          $field_data['content'] = "{\n    ...MediaUnionFragment\n  }";
          $fields[] = $field_data;
          $fragment_dependencies[] = 'MediaUnionFragment';
        } else {
          $formatter_type = 'entity_reference_label';
          $fields[] = $field_data;
        }
        break;

      case 'entity_reference_revisions':
        $formatter_type = 'entity_reference_revisions_entity_view';
        $formatter_settings = [
          'view_mode' => 'default',
        ];
        break;
    }

    $view_display->setComponent($field_name, [
      'type' => $formatter_type,
      'weight' => 0,
      'settings' => $formatter_settings,
      'label' => 'hidden',
    ])->save();
  }

  /**
   * Create a paragraph and attach it to a node.
   *
   * @param object $paragraph_data
   *   The paragraph object fields with sample values.
   *
   * @return string
   *   Return a message indicating the result with the node title and URL.
   */
  protected function createParagraph($paragraph_data) {
    $this->loggerFactory->get('drupalx_ai')->debug(
      'Starting createParagraph method with data: @data',
      ['@data' => json_encode($paragraph_data)]
    );

    if (!empty($paragraph_data->parent_type)) {
      return '';
    }

    $paragraph = Paragraph::create(['type' => $paragraph_data->id]);
    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');

    // Helper function to create a media entity
    $createMediaEntity = function ($image_file, $field_name, $label, $index = NULL) {
      try {
        // Create a media entity
        $media = $this->entityTypeManager->getStorage('media')->create([
          'bundle' => 'image',
          'name' => $label ?? 'Sample image' . ($index !== NULL ? ' ' . ($index + 1) : ''),
          'status' => 1,
        ]);

        // Get the source field for the media type
        $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
        $source_field = $media_type->getSource()->getConfiguration()['source_field'];

        // Set the image field on the media entity
        $media->set($source_field, [
          'target_id' => $image_file->id(),
          'alt' => $label ?? 'Sample image' . ($index !== NULL ? ' ' . ($index + 1) : ''),
          'title' => $label ?? 'Sample image' . ($index !== NULL ? ' ' . ($index + 1) : ''),
        ]);

        $media->save();

        $this->loggerFactory->get('drupalx_ai')->info(
          'Created media entity @mid for field @field',
          [
            '@mid' => $media->id(),
            '@field' => $field_name
          ]
        );

        return $media;
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('drupalx_ai')->error(
          'Error creating media entity: @error',
          ['@error' => $e->getMessage()]
        );
        return NULL;
      }
    };

    // Helper function to create a new image file
    $createImageFile = function () use ($module_path) {
      $file_path = $module_path . '/files/card.png';
      $directory = 'public://paragraph_images';

      try {
        if (!file_exists($file_path) || !is_readable($file_path)) {
          throw new \Exception("Source file does not exist or is not readable: $file_path");
        }

        if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
          throw new \Exception('Failed to prepare directory');
        }

        $destination = $directory . '/card_' . uniqid() . '.png';
        $file_contents = file_get_contents($file_path);

        if ($file_contents === FALSE) {
          throw new \Exception("Could not read source file: $file_path");
        }

        $uri = $this->fileSystem->saveData($file_contents, $destination, FileSystemInterface::EXISTS_REPLACE);
        if (!$uri) {
          throw new \Exception("Failed to save file to: $destination");
        }

        $file = File::create([
          'uri' => $uri,
          'filename' => basename($destination),
          'filemime' => 'image/png',
          'status' => 1,
        ]);

        $file->save();
        return $file;
      } catch (\Exception $e) {
        $this->loggerFactory->get('drupalx_ai')->error(
          'Error creating image file: @error',
          ['@error' => $e->getMessage()]
        );
        return NULL;
      }
    };

    // Process fields
    foreach ($paragraph_data->fields as $field) {
      $field_array = is_object($field) ? get_object_vars($field) : $field;
      $field_name = !empty($field_array['name']) ?
        (strpos($field_array['name'], 'field_') === 0 ? $field_array['name'] : 'field_' . $field_array['name']) : '';

      if (!empty($field_name) && $paragraph->hasField($field_name)) {
        $field_definition = $paragraph->getFieldDefinition($field_name);
        $field_type = $field_definition->getType();

        try {
          switch ($field_type) {
            case 'entity_reference':
              if (isset($field_array['target_type']) && $field_array['target_type'] === 'media') {
                $image_file = $createImageFile();
                if ($image_file) {
                  $media = $createMediaEntity($image_file, $field_name, $field_array['label']);
                  if ($media) {
                    $paragraph->get($field_name)->setValue(['target_id' => $media->id()]);
                  }
                }
              }
              break;

            case 'image':
              $image_file = $createImageFile();
              if ($image_file) {
                $media = $createMediaEntity($image_file, $field_name, $field_array['label']);
                if ($media) {
                  $paragraph->get($field_name)->setValue(['target_id' => $media->id()]);
                }
              }
              break;

            case 'link':
              $url = $field_array['sample_value'];
              if (strpos($url, '/') === 0) {
                $url = 'internal:' . $url;
              }
              $paragraph->set($field_name, ['uri' => $url]);
              break;

            case 'entity_reference_revisions':
              if (!empty($field_array['target_bundle']) && !empty($paragraph_data->child_types)) {
                foreach ($paragraph_data->child_types as $child_type) {
                  if ($child_type->id === $field_array['target_bundle']) {
                    $child_type->parent_type = $paragraph_data->id;
                    $field_storage = $field_definition->getFieldStorageDefinition();
                    $cardinality = $field_storage->getCardinality();
                    if ($cardinality === -1) {
                      $cardinality = 3;
                    }

                    for ($i = 0; $i < $cardinality; $i++) {
                      $child_paragraph = Paragraph::create(['type' => $child_type->id]);
                      foreach ($child_type->fields as $child_field) {
                        $child_field_array = is_object($child_field) ? get_object_vars($child_field) : $child_field;
                        $child_field_name = !empty($child_field_array['name']) ?
                          (strpos($child_field_array['name'], 'field_') === 0 ? $child_field_array['name'] : 'field_' . $child_field_array['name']) : '';

                        if (!empty($child_field_name) && $child_paragraph->hasField($child_field_name)) {
                          $child_field_definition = $child_paragraph->getFieldDefinition($child_field_name);
                          $child_field_type = $child_field_definition->getType();

                          try {
                            switch ($child_field_type) {
                              case 'string':
                                $sample_value = $child_field_array['sample_value'] ?? 'Sample value ' . ($i + 1);
                                if (is_string($sample_value)) {
                                  $sample_value .= ' ' . ($i + 1);
                                }
                                $child_paragraph->set($child_field_name, $sample_value);
                                break;

                              case 'text_long':
                                $sample_value = $child_field_array['sample_value'] ?? 'Sample text ' . ($i + 1);
                                $child_paragraph->set($child_field_name, [
                                  'value' => $sample_value,
                                  'format' => 'basic_html',
                                ]);
                                break;

                              case 'link':
                                $url = $child_field_array['sample_value'] ?? '/node/' . ($i + 1);
                                if (strpos($url, '/') === 0) {
                                  $url = 'internal:' . $url;
                                }
                                $child_paragraph->set($child_field_name, ['uri' => $url]);
                                break;

                              case 'image':
                              case 'entity_reference':
                                $target_type = $child_field_definition->getSetting('target_type');
                                if ($child_field_type === 'image' || ($target_type === 'media')) {
                                  $image_file = $createImageFile();
                                  if ($image_file) {
                                    $media = $createMediaEntity($image_file, $child_field_name, $child_field_array['label'], $i);
                                    if ($media) {
                                      $child_paragraph->get($child_field_name)->setValue(['target_id' => $media->id()]);
                                    }
                                  }
                                }
                                break;

                              default:
                                $sample_value = $child_field_array['sample_value'] ?? 'Sample value ' . ($i + 1);
                                if (is_string($sample_value)) {
                                  $sample_value .= ' ' . ($i + 1);
                                }
                                $child_paragraph->set($child_field_name, $sample_value);
                                break;
                            }
                          }
                          catch (\Exception $e) {
                            $this->loggerFactory->get('drupalx_ai')->error(
                              'Error setting child field @field: @error',
                              [
                                '@field' => $child_field_name,
                                '@error' => $e->getMessage()
                              ]
                            );
                          }
                        }
                      }

                      $child_paragraph->save();
                      $paragraph->get($field_name)->appendItem($child_paragraph);
                    }
                    break;
                  }
                }
              }
              break;

            default:
              $paragraph->set($field_name, $field_array['sample_value']);
              break;
          }
        } catch (\Exception $e) {
          $this->loggerFactory->get('drupalx_ai')->error(
            'Error processing field @field: @error',
            [
              '@field' => $field_name,
              '@error' => $e->getMessage()
            ]
          );
        }
      }
    }

    try {
      $paragraph->save();
      $node = Node::create([
        'type' => 'landing',
        'title' => "Paragraph: {$paragraph_data->id}",
      ]);

      if ($node->hasField('field_content')) {
        $node->get('field_content')->appendItem($paragraph);
        $node->save();

        $edit_url = $node->toUrl('edit-form')->setAbsolute()->toString();
        return "Created test landing page node with '{$paragraph_data->id}' paragraph and its child paragraphs.\nEdit URL: {$edit_url}\n";
      }
      else {
        throw new \Exception('The node does not have the field_content field.');
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error(
        'Error creating test node: @error',
        ['@error' => $e->getMessage()]
      );
      return "Error creating test node: " . $e->getMessage();
    }
  }

  /**
   * Transform a GraphQL fragment to a TypeScript fragment.
   *
   * @param string $input
   *   The input fragment.
   *
   * @return array
   *   The transformed fragment as an array with the fragment name and content.
   */
  protected function transformFragment($input) {
    // 1. Extract the original fragment name and type.
    preg_match('/fragment\s+(\w+)\s+on\s+(\w+)/', $input, $matches);
    $originalFragmentName = $matches[1];
    $typeName = $matches[2];

    // 2. Derive the new fragment name.
    $newFragmentName = $typeName . 'Fragment';

    // 3. Update the fragment declaration.
    $updatedDeclaration = str_replace(
      "fragment {$originalFragmentName} on {$typeName}",
      "fragment {$newFragmentName} on {$typeName}",
      $input
    );

    // 4. Wrap the updated content with the new fragment name.
    $wrapped = "export const {$newFragmentName} = graphql(`\n" . $updatedDeclaration . "\n`);";

    // 5. Replace `... FragmentName` with `...NameFragment`.
    $withReplacedFragments = preg_replace_callback(
      '/\.\.\.\s+Fragment(\w+)/', function ($matches) {
        return '... ' . $matches[1] . 'Fragment';
      }, $wrapped
    );

    // 6. Extract unique fragments from the wrapped content.
    preg_match_all('/\.\.\.\s+(\w+)Fragment/', $withReplacedFragments, $fragmentMatches);
    $uniqueFragments = array_unique($fragmentMatches[1]);
    $fragmentsArray = '[' . implode(', ', array_map(fn($frag) => $frag . 'Fragment', $uniqueFragments)) . ']';

    // 7. Insert the fragments array before the final closing parenthesis.
    $finalOutput = str_replace('`);', "`, {$fragmentsArray});", $withReplacedFragments);

    // 8. Indent every line by 2 spaces.
    $indentedOutput = preg_replace('/^(?!$)/m', '  ', $finalOutput);

    return [$newFragmentName, $indentedOutput];
  }

  /**
   * Add aliases to common fields.
   *
   * @param array $fields
   *   The fields to process.
   * @param string $prefix
   *   The prefix to use for aliases.
   *
   * @return array
   *   The fields with aliases added.
   */
  protected function addAliases(array $fields, string $prefix) {
    $common_fields = [
      'title',
      'summary',
      'body',
      'media',
      'link',
      'created',
      'langcode',
      'status'
    ];

    $aliased_fields = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $field_name = $field['name'];
        if (in_array($field_name, $common_fields)) {
          $field['alias'] = $prefix . ucfirst($field_name);
        }
        $aliased_fields[] = $field;
      }
      else {
        // Handle string field names (like 'id').
        if (in_array($field, $common_fields)) {
          $aliased_fields[] = "{$prefix}" . ucfirst($field) . ": {$field}";
        }
        else {
          $aliased_fields[] = $field;
        }
      }
    }

    return $aliased_fields;
  }

  /**
   * Create a fragment and component file for a paragraph type.
   *
   * @param string $paragraph_type_id
   *   The ID of the paragraph type.
   * @param object|null $parent_data
   *   Optional parent paragraph data if this is a parent type.
   *
   * @return string
   *   The output data.
   */
  protected function createParagraphFragment($paragraph_type_id, $parent_data = NULL) {
    $output = '';

    // Helper function to convert snake_case to PascalCase and remove trailing 's'.
    $toPascalCase = function ($str) {
      // Remove trailing 's' if present.
      $str = rtrim($str, 's');
      return str_replace('_', '', ucwords($str, '_'));
    };

    // Helper function to convert field name to camelCase.
    $toCamelCase = function ($str) {
      // Remove 'field_' prefix if present.
      $str = preg_replace('/^field_/', '', $str);
      // Convert snake_case to camelCase.
      $str = lcfirst(str_replace('_', '', ucwords($str, '_')));
      return $str;
    };

    // If this is a parent type with child types, create a single component with both fragments.
    if ($parent_data && !empty($parent_data->child_types)) {
      // First, generate fragments for child types.
      $child_fragments = [];
      foreach ($parent_data->child_types as $child_type) {
        $child_fragment_name = $toPascalCase($child_type->id) . 'Fragment';
        $child_fields = [];
        $fragment_dependencies = [];

        // Add standard fields.
        $child_fields[] = 'id';

        // Add custom fields.
        foreach ($child_type->fields as $field) {
          $field_array = is_object($field) ? get_object_vars($field) : $field;
          $field_name = $toCamelCase('field_' . $field_array['name']);
          $field_data = ['name' => $field_name, 'type' => $field_array['type']];

          switch ($field_array['type']) {
            case 'string':
              $child_fields[] = $field_data;
              break;

            case 'text_long':
              $field_data['content'] = "{\n      value\n      processed\n    }";
              $child_fields[] = $field_data;
              break;

            case 'link':
              $field_data['content'] = "{\n      ...LinkFragment\n    }";
              $child_fields[] = $field_data;
              $fragment_dependencies[] = 'LinkFragment';
              break;

            case 'image':
              $field_data['content'] = "{\n      ...MediaUnionFragment\n    }";
              $child_fields[] = $field_data;
              $fragment_dependencies[] = 'MediaUnionFragment';
              break;
          }
        }

        // Add aliases to child fields.
        $child_fields = $this->addAliases($child_fields, $toCamelCase($child_type->id));

        // Create the child fragment with PascalCase type name.
        $child_type_pascal = $toPascalCase($child_type->id);
        $fragment_deps_str = empty($fragment_dependencies) ? '' : ', [' . implode(', ', $fragment_dependencies) . ']';

        // Format fields for fragment.
        $formatted_fields = array_map(
          function ($field) {
            if (is_array($field)) {
              $field_str = isset($field['alias']) ? "{$field['alias']}: {$field['name']}" : $field['name'];
              if (isset($field['content'])) {
                $field_str .= " " . $field['content'];
              }
              return $field_str;
            }
            return $field;
          },
          $child_fields
        );

        $child_fragment_content = "const {$child_fragment_name} = graphql(`fragment {$child_fragment_name} on Paragraph{$child_type_pascal} {\n  " . implode("\n  ", $formatted_fields) . "\n}`{$fragment_deps_str});";

        $child_fragments[] = [
          'name' => $child_fragment_name,
          'content' => $child_fragment_content,
          'dependencies' => $fragment_dependencies,
        ];
      }

      // Now generate the parent fragment.
      $parent_fragment_name = 'Paragraph' . $toPascalCase($paragraph_type_id) . 'Fragment';
      $parent_fields = ['id'];

      // Add parent fields.
      foreach ($parent_data->fields as $field) {
        $field_array = is_object($field) ? get_object_vars($field) : $field;
        $field_name = $toCamelCase('field_' . $field_array['name']);
        $field_data = ['name' => $field_name, 'type' => $field_array['type']];

        if ($field_array['type'] === 'entity_reference_revisions') {
          // Reference the child fragment.
          $child_type = $field_array['target_bundle'];
          $child_fragment_name = $toPascalCase($child_type) . 'Fragment';
          $field_data['content'] = "{\n    ...{$child_fragment_name}\n  }";
        }
        $parent_fields[] = $field_data;
      }

      // Add aliases to parent fields.
      $parent_fields = $this->addAliases($parent_fields, $toPascalCase($paragraph_type_id));

      // Format fields for parent fragment.
      $formatted_parent_fields = array_map(
        function ($field) {
          if (is_array($field)) {
            $field_str = isset($field['alias']) ? "{$field['alias']}: {$field['name']}" : $field['name'];
            if (isset($field['content'])) {
              $field_str .= " " . $field['content'];
            }
            return $field_str;
          }
          return $field;
        },
        $parent_fields
      );

      // Create the parent fragment with PascalCase type name.
      $parent_type_pascal = $toPascalCase($parent_data->id);
      $parent_fragment_content = "export const {$parent_fragment_name} = graphql(`fragment {$parent_fragment_name} on Paragraph{$parent_type_pascal} {\n  " . implode("\n  ", $formatted_parent_fields) . "\n}`, [" . implode(', ', array_map(fn($f) => $f['name'], $child_fragments)) . "]);";

      // Create the component file:
      $component_name = 'Paragraph' . $toPascalCase($paragraph_type_id);
      $new_fragment_file = $this->fileSystem->realpath('../nextjs/components/paragraphs') . "/{$component_name}.tsx";

      // Generate imports:
      $imports = "import { FragmentOf, readFragment, graphql } from 'gql.tada';\n";

      // Collect all unique fragment dependencies.
      $all_fragment_deps = [];
      foreach ($child_fragments as $fragment) {
        $all_fragment_deps = array_merge($all_fragment_deps, $fragment['dependencies']);
      }
      $all_fragment_deps = array_unique($all_fragment_deps);

      // Add required imports based on dependencies.
      $misc_fragments = array_intersect(
        [
          'LinkFragment',
          'TextSummaryFragment',
          'DateTimeFragment',
          'LanguageFragment'
        ],
        $all_fragment_deps
      );
      $media_fragments = array_intersect(['MediaUnionFragment'], $all_fragment_deps);

      if (!empty($misc_fragments)) {
        $imports .= "import { " . implode(', ', $misc_fragments) . " } from '@/graphql/fragments/misc';\n";
      }
      if (!empty($media_fragments)) {
        $imports .= "import { " . implode(', ', $media_fragments) . " } from '@/graphql/fragments/media';\n";
      }

      // Combine all fragments and generate component content:
      $component_content = $imports . "\n";
      foreach ($child_fragments as $fragment) {
        $component_content .= $fragment['content'] . "\n\n";
      }
      $component_content .= $parent_fragment_content . "\n\n";

      // Add component interface and implementation:
      $component_content .= "interface {$component_name}Props {\n";
      $component_content .= "  paragraph: FragmentOf<typeof {$parent_fragment_name}>\n";
      $component_content .= "}\n\n";

      $component_content .= "export default function {$component_name}({ paragraph }: {$component_name}Props) {\n";
      $component_content .= "  const paragraphData = readFragment({$parent_fragment_name}, paragraph);\n\n";
      $component_content .= "  return (\n";
      $component_content .= "    <div className=\"container mx-auto\">\n";
      $component_content .= "      <pre>{JSON.stringify(paragraphData, null, 2)}</pre>\n";
      $component_content .= "    </div>\n";
      $component_content .= "  );\n";
      $component_content .= "}\n";

      // Write the new component file:
      $this->fileSystem->saveData($component_content, $new_fragment_file, FileSystemInterface::EXISTS_REPLACE);

      // Update the paragraph.ts file:
      $paragraphs_file = '../nextjs/graphql/fragments/paragraph.ts';
      $paragraphs_content = file_get_contents($paragraphs_file);

      // Add import statement:
      $import_statement = "import { {$parent_fragment_name} } from \"@/components/paragraphs/{$component_name}\";";
      if (strpos($paragraphs_content, $import_statement) === FALSE) {
        $paragraphs_content = preg_replace(
          '/import { graphql } from "@\/graphql\/gql.tada";/',
          "import { graphql } from \"@/graphql/gql.tada\";\n" . $import_statement,
          $paragraphs_content
        );

        // Update ParagraphUnionFragment:
        $paragraphs_content = preg_replace(
          '/\.\.\.ParagraphViewFragment/',
          "...ParagraphViewFragment\n  ...{$parent_fragment_name}",
          $paragraphs_content
        );

        $paragraphs_content = preg_replace(
          '/ParagraphViewFragment,/',
          "ParagraphViewFragment,\n  {$parent_fragment_name},",
          $paragraphs_content
        );

        // Save the updated paragraph.ts file:
        $this->fileSystem->saveData($paragraphs_content, $paragraphs_file, FileSystemInterface::EXISTS_REPLACE);
      }

      $output .= "Component {$component_name} created in {$new_fragment_file}.\n";
      $output .= "Paragraph.ts updated with new import and fragment.";
    }
    // For standalone paragraph types (no children):
    else {
      // Generate the fragment for the standalone type.
      $fragment_name = 'Paragraph' . $toPascalCase($paragraph_type_id) . 'Fragment';
      $fields = ['id'];
      $fragment_dependencies = [];

      // Get the paragraph type entity to access field definitions.
      $paragraph_type = ParagraphsType::load($paragraph_type_id);
      if (!$paragraph_type) {
        return "Error: Could not load paragraph type {$paragraph_type_id}";
      }

      // Get field definitions.
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('paragraph', $paragraph_type_id);

      foreach ($field_definitions as $field_name => $field_definition) {
        // Skip base fields.
        if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }

        // Remove 'field_' prefix.
        $field_name_clean = preg_replace('/^field_/', '', $field_name);
        $field_type = $field_definition->getType();
        $field_data = ['name' => $field_name_clean, 'type' => $field_type];

        switch ($field_type) {
          case 'string':
          case 'boolean':
            $fields[] = $field_data;
            break;

          case 'text':
          case 'text_long':
          case 'text_with_summary':
            $field_data['content'] = "{\n    ...TextFragment\n  }";
            $fields[] = $field_data;
            $fragment_dependencies[] = 'TextFragment';
            break;

          case 'link':
            $field_data['content'] = "{\n    ...LinkFragment\n  }";
            $fields[] = $field_data;
            $fragment_dependencies[] = 'LinkFragment';
            break;

          case 'image':
            $field_data['content'] = "{\n    ...MediaUnionFragment\n  }";
            $fields[] = $field_data;
            $fragment_dependencies[] = 'MediaUnionFragment';
            break;
        }
      }

      // Add aliases to fields.
      $fields = $this->addAliases($fields, $toPascalCase($paragraph_type_id));

      // Format fields for fragment.
      $formatted_fields = array_map(
        function ($field) {
          if (is_array($field)) {
            $field_str = isset($field['alias']) ? "{$field['alias']}: {$field['name']}" : $field['name'];
            if (isset($field['content'])) {
              $field_str .= " " . $field['content'];
            }
            return $field_str;
          }
          return $field;
        },
        $fields
      );

      // Create the fragment.
      $type_pascal = $toPascalCase($paragraph_type_id);
      $fragment_deps_str = empty($fragment_dependencies) ? '' : ', [' . implode(', ', array_map(fn($dep) => $dep, $fragment_dependencies)) . ']';
      $fragment_content = "export const {$fragment_name} = graphql(`fragment {$fragment_name} on Paragraph{$type_pascal} {\n  " . implode("\n  ", $formatted_fields) . "\n}`{$fragment_deps_str});";

      // Create the component file.
      $component_name = 'Paragraph' . $type_pascal;
      $new_fragment_file = $this->fileSystem->realpath('../nextjs/components/paragraphs') . "/{$component_name}.tsx";

      // Generate imports.
      $imports = "import { FragmentOf, readFragment, graphql } from 'gql.tada';\n";

      // Add required imports based on dependencies.
      $misc_fragments = array_intersect(
        ['LinkFragment', 'TextFragment', 'TextSummaryFragment', 'DateTimeFragment', 'LanguageFragment'],
        $fragment_dependencies
      );
      $media_fragments = array_intersect(['MediaUnionFragment'], $fragment_dependencies);

      if (!empty($misc_fragments)) {
        $imports .= "import { " . implode(', ', $misc_fragments) . " } from '@/graphql/fragments/misc';\n";
      }
      if (!empty($media_fragments)) {
        $imports .= "import { " . implode(', ', $media_fragments) . " } from '@/graphql/fragments/media';\n";
      }

      // Generate component content.
      $component_content = $imports . "\n";
      $component_content .= $fragment_content . "\n\n";
      $component_content .= "interface {$component_name}Props {\n";
      $component_content .= "  paragraph: FragmentOf<typeof {$fragment_name}>\n";
      $component_content .= "}\n\n";
      $component_content .= "export default function {$component_name}({ paragraph }: {$component_name}Props) {\n";
      $component_content .= "  const paragraphData = readFragment({$fragment_name}, paragraph);\n\n";
      $component_content .= "  return (\n";
      $component_content .= "    <div className=\"container mx-auto\">\n";
      $component_content .= "      <pre>{JSON.stringify(paragraphData, null, 2)}</pre>\n";
      $component_content .= "    </div>\n";
      $component_content .= "  );\n";
      $component_content .= "}\n";

      // Write the new component file.
      $this->fileSystem->saveData($component_content, $new_fragment_file, FileSystemInterface::EXISTS_REPLACE);

      // Update the paragraph.ts file.
      $paragraphs_file = '../nextjs/graphql/fragments/paragraph.ts';
      $paragraphs_content = file_get_contents($paragraphs_file);

      // Add import statement.
      $import_statement = "import { {$fragment_name} } from \"@/components/paragraphs/{$component_name}\";";
      if (strpos($paragraphs_content, $import_statement) === FALSE) {
        $paragraphs_content = preg_replace(
          '/import { graphql } from "@\/graphql\/gql.tada";/',
          "import { graphql } from \"@/graphql/gql.tada\";\n" . $import_statement,
          $paragraphs_content
        );

        // Update ParagraphUnionFragment.
        $paragraphs_content = preg_replace(
          '/\.\.\.ParagraphViewFragment/',
          "...ParagraphViewFragment\n  ...{$fragment_name}",
          $paragraphs_content
        );

        $paragraphs_content = preg_replace(
          '/ParagraphViewFragment,/',
          "ParagraphViewFragment,\n  {$fragment_name},",
          $paragraphs_content
        );

        // Save the updated paragraph.ts file.
        $this->fileSystem->saveData($paragraphs_content, $paragraphs_file, FileSystemInterface::EXISTS_REPLACE);
      }

      $output .= "Component {$component_name} created in {$new_fragment_file}.\n";
      $output .= "Paragraph.ts updated with new import and fragment.";
    }

    return $output;
  }

  /**
   * Generate the content for the React component file with child fragments.
   */
  public function generateComponentContentWithChildren($component_name, $fragment_name, $fragment_content, $child_fragments) {
    $imports = $this->generateImports($fragment_content);
    foreach ($child_fragments as $child) {
      $imports .= "\n" . $this->generateImports($child['content']);
    }

    $child_fragment_declarations = '';
    foreach ($child_fragments as $child) {
      $child_fragment_declarations .= "\n" . $child['content'] . "\n";
    }

    $this->integrationTemplate = <<<EOT
import { FragmentOf, readFragment, graphql } from 'gql.tada';
{$imports}

{$child_fragment_declarations}

{$fragment_content}

interface {$component_name}Props {
  paragraph: FragmentOf<typeof {$fragment_name}>,
}

export default function {$component_name}({ paragraph }: {$component_name}Props) {
  const paragraphData = readFragment({$fragment_name}, paragraph);

  const DebugView = ({ data }: { data: unknown }) => (
    <pre style={{ backgroundColor: '#f0f0f0', padding: '10px', borderRadius: '5px', overflow: 'auto' }}>
      {JSON.stringify(data, null, 2)}
    </pre>
  );

  return (
    <div className="container mx-auto">
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {paragraphData.card_items?.map((item, index) => (
          <div key={item.id} className="bg-white rounded-lg shadow-md overflow-hidden">
            {item.recent_card_itemMedia && (
              <div className="aspect-w-16 aspect-h-9">
                {/* Add media rendering logic here */}
                <div className="bg-gray-200 w-full h-full"></div>
              </div>
            )}
            <div className="p-6">
              <h3 className="text-xl font-semibold mb-2">{item.recent_card_itemTitle}</h3>
              {item.recent_card_itemSummary && (
                <p className="text-gray-600 mb-4">{item.recent_card_itemSummary.processed}</p>
              )}
              {item.recent_card_itemLink && (
                <a
                  href={item.recent_card_itemLink.url}
                  className="text-blue-600 hover:text-blue-800 font-medium"
                  target={item.recent_card_itemLink.target}
                >
                  {item.recent_card_itemLink.title || 'Read more'}
                </a>
              )}
            </div>
          </div>
        ))}
      </div>
      {<DebugView data={paragraphData} />}
    </div>
  );
}
EOT;

    return $this->integrationTemplate;
  }

  /**
   * Generate the content for the React component file.
   *
   * @param string $component_name
   *   The name of the component.
   * @param string $fragment_name
   *   The name of the fragment.
   * @param string $fragment_content
   *   The content of the fragment.
   *
   * @return string
   *   The generated component content.
   */
  public function generateComponentContent($component_name, $fragment_name, $fragment_content) {
    $imports = $this->generateImports($fragment_content);

    $this->integrationTemplate = <<<EOT
import { FragmentOf, readFragment, graphql } from 'gql.tada';
{$imports}

{$fragment_content}

interface {$component_name}Props {
  paragraph: FragmentOf<typeof {$fragment_name}>,
}

export default function {$component_name}({ paragraph }: {$component_name}Props) {
  const paragraphData = readFragment({$fragment_name}, paragraph);

  const DebugView = ({ data }: { data: any }) => (
    <pre style={{ backgroundColor: '#f0f0f0', padding: '10px', borderRadius: '5px', overflow: 'auto' }}>
      {JSON.stringify(data, null, 2)}
    </pre>
  );

  return (
    <div className={'container mx-auto '}>
      {<DebugView data={paragraphData} />}
    </div>
  );
}
EOT;
    return $this->integrationTemplate;
  }

  /**
   * Get the integration template.
   *
   * @return string
   *   The integration template content.
   */
  public function getIntegrationTemplate() {
    return $this->integrationTemplate;
  }

  /**
   * Generate import statements based on the fragment content.
   *
   * @param string $fragment_content
   *   The content of the fragment.
   *
   * @return string
   *   The generated import statements.
   */
  protected function generateImports($fragment_content) {
    $fragments = [
      'LinkFragment',
      'TextFragment',
      'TextSummaryFragment',
      'DateTimeFragment',
      'LanguageFragment',
      'ImageFragment',
      'SvgImageFragment',
      'SvgMediaFragment',
      'MediaImageFragment',
      'MediaVideoFragment',
      'MediaUnionFragment',
    ];

    $imports = [];
    foreach ($fragments as $fragment) {
      if (strpos($fragment_content, $fragment) !== FALSE) {
        $imports[] = $fragment;
      }
    }

    $misc_fragments = [
      'DateTimeFragment',
      'LanguageFragment',
      'LinkFragment',
      'TextFragment',
      'TextSummaryFragment',
    ];
    $media_fragments = [
      'ImageFragment',
      'SvgImageFragment',
      'SvgMediaFragment',
      'MediaImageFragment',
      'MediaVideoFragment',
      'MediaUnionFragment',
    ];
    $metatag_fragments = ['MetaTagUnionFragment'];

    $misc_imports = array_intersect($misc_fragments, $imports);
    $media_imports = array_intersect($media_fragments, $imports);
    $metatag_imports = array_intersect($metatag_fragments, $imports);

    $import_strings = [];

    if (!empty($misc_imports)) {
      $misc_import_string = implode(', ', $misc_imports);
      $import_strings[] = "import { {$misc_import_string} } from '@/graphql/fragments/misc';";
    }

    if (!empty($media_imports)) {
      $media_import_string = implode(', ', $media_imports);
      $import_strings[] = "import { {$media_import_string} } from '@/graphql/fragments/media';";
    }

    if (!empty($metatag_imports)) {
      $metatag_import_string = implode(', ', $metatag_imports);
      $import_strings[] = "import { {$metatag_import_string} } from '@/graphql/fragments/metatag';";
    }

    return implode("\n", $import_strings);
  }

}
