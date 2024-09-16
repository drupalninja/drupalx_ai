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
   * @param \Drupal\graphql_compose_fragments\FragmentManager $fragment_manager
   *   The fragment manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    FragmentManager $fragment_manager,
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

      // Create the paragraph type.
      $paragraph_type = ParagraphsType::create(
            [
              'id' => $paragraph_data->id,
              'label' => $paragraph_data->name,
              'description' => $paragraph_data->description,
            ]
        );
      $paragraph_type->save();

      // Update GraphQL Compose configuration for this paragraph.
      $config = $this->configFactory->getEditable('graphql_compose.settings');

      // Enable the paragraph type in GraphQL configuration.
      $config->set("entity_config.paragraph.{$paragraph_data->id}.enabled", TRUE);
      $config->set("entity_config.paragraph.{$paragraph_data->id}.query_load_enabled", TRUE);
      $config->set("entity_config.paragraph.{$paragraph_data->id}.edges_enabled", TRUE);

      $config->save();

      // Create fields.
      $field_count = 0;
      foreach ($paragraph_data->fields as $field) {
        // Validate required field properties.
        $required_field_properties = ['name', 'label', 'type'];
        foreach ($required_field_properties as $property) {
          if (!isset($field[$property])) {
            throw new \InvalidArgumentException("Missing required field property: $property");
          }
        }

        $this->createField($paragraph_type->id(), $field);
        $field_count++;
      }

      // Create a test paragraph on a test layout page.
      $result = $this->createParagraph($paragraph_data);

      // Create a fragment for the paragraph type.
      $result .= "\n" . $this->createParagraphFragment($paragraph_data->id);

      return "Paragraph type '{$paragraph_data->name}' successfully created with $field_count fields.\n{$result}";
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalx_ai')->error('Error importing paragraph type: @message', ['@message' => $e->getMessage()]);
      return 'Error importing paragraph type: ' . $e->getMessage();
    }
  }

  /**
   * Create a field for a paragraph type and update form display.
   *
   * @param string $paragraph_type_id
   *   The ID of the paragraph type.
   * @param object $field_data
   *   The field data.
   */
  protected function createField($paragraph_type_id, $field_data) {
    $field_name = 'field_' . $field_data['name'];
    $field_type = $field_data['type'];

    // Check if field storage already exists.
    if (!FieldStorageConfig::loadByName('paragraph', $field_name)) {
      FieldStorageConfig::create(
        [
          'field_name' => $field_name,
          'entity_type' => 'paragraph',
          'type' => $field_type,
          'cardinality' => $field_data['cardinality'] ?? 1,
        ]
      )->save();
    }

    // Create the field instance.
    if (!FieldConfig::loadByName('paragraph', $paragraph_type_id, $field_name)) {
      FieldConfig::create(
        [
          'field_name' => $field_name,
          'entity_type' => 'paragraph',
          'bundle' => $paragraph_type_id,
          'label' => $field_data['label'],
          'required' => $field_data['required'] ?? FALSE,
        ]
      )->save();
    }

    // Update GraphQL Compose configuration for this paragraph field.
    $config = $this->configFactory->getEditable('graphql_compose.settings');
    $config->set("field_config.paragraph.{$paragraph_type_id}.{$field_name}.enabled", TRUE);
    $config->save();

    // Update the form display to include the new field.
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('paragraph.' . $paragraph_type_id . '.default');

    if (!$form_display) {
      $form_display = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->create(
          [
            'targetEntityType' => 'paragraph',
            'bundle' => $paragraph_type_id,
            'mode' => 'default',
            'status' => TRUE,
          ]
        );
    }

    /**
    * @var \Drupal\Core\Entity\EntityFormDisplayInterface $form_display
    */
    $form_display->setComponent(
      $field_name, [
        'type' => 'string_textfield',
        'weight' => 0,
      ]
    )->save();
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
    // Create a new paragraph entity.
    $paragraph = Paragraph::create(
      [
        'type' => $paragraph_data->id,
      ]
    );

    $module_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai');

    // Assign the fields to the paragraph.
    foreach ($paragraph_data->fields as $field) {
      $field_name = !empty($field['name']) ? (strpos($field['name'], 'field_') === 0 ? $field['name'] : 'field_' . $field['name']) : '';
      if (!empty($field_name) && $paragraph->hasField($field_name)) {
        $field_definition = $paragraph->getFieldDefinition($field_name);
        $field_type = $field_definition->getType();

        if ($field_type === 'image') {
          $file_path = $module_path . '/files/card.png';
          $file_contents = file_get_contents($file_path);
          $directory = 'public://paragraph_images';
          $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
          $file = File::create(
            [
              'uri' => $this->fileSystem->saveData($file_contents, $directory . '/card.png', FileSystemInterface::EXISTS_REPLACE),
            ]
          );
          $file->setPermanent();
          $file->save();

          $paragraph->set(
            $field_name, [
              'target_id' => $file->id(),
              'alt' => $field['label'],
              'title' => $field['label'],
            ]
          );
        }
        elseif ($field_type === 'link') {
          $url = $field['sample_value'];
          if (strpos($url, '/') === 0) {
            $url = 'internal:' . $url;
          }
          $paragraph->set($field_name, ['uri' => $url]);
        }
        else {
          $paragraph->set($field_name, $field['sample_value']);
        }
      }
      else {
        $this->loggerFactory->get('drupalx_ai')->warning(
          "Field @field does not exist on the paragraph type @type.", [
            '@field' => $field_name ?? 'undefined',
            '@type' => $paragraph_data->id,
          ]
        );
      }
    }

    // Save the paragraph entity.
    $paragraph->save();

    // Create the node with the provided title.
    $node = Node::create(
      [
        'type' => 'layout',
        'title' => "Paragraph: '{$paragraph_data->id}'",
      ]
    );

    // Attach the paragraph to the node's field_content.
    if ($node->hasField('field_content')) {
      $node->get('field_content')->appendItem($paragraph);
    }
    else {
      $this->loggerFactory->get('drupalx_ai')->error('The node does not have the field_content field.');
      return NULL;
    }

    // Save the node.
    $node->save();

    // Return a message indicating the result.
    $edit_url = $node->toUrl('edit-form')->setAbsolute()->toString();
    return "Created test layout node and added a paragraph of type '{$paragraph_data->id}'.\nEdit URL: {$edit_url}\n";
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

    // 4. Wrap the updated content with the new fragment name
    $wrapped = "export const {$newFragmentName} = graphql(`\n" . $updatedDeclaration . "\n`);";

    // 5. Replace `... FragmentName` with `...NameFragment`
    $withReplacedFragments = preg_replace_callback(
      '/\.\.\.\s+Fragment(\w+)/', function ($matches) {
        return '... ' . $matches[1] . 'Fragment';
      }, $wrapped
    );

    // 6. Extract unique fragments from the wrapped content
    preg_match_all('/\.\.\.\s+(\w+)Fragment/', $withReplacedFragments, $fragmentMatches);
    $uniqueFragments = array_unique($fragmentMatches[1]);
    $fragmentsArray = '[' . implode(', ', array_map(fn($frag) => $frag . 'Fragment', $uniqueFragments)) . ']';

    // 7. Insert the fragments array before the final closing parenthesis
    $finalOutput = str_replace('`);', "`, {$fragmentsArray});", $withReplacedFragments);

    // 8. Indent every line by 2 spaces
    $indentedOutput = preg_replace('/^(?!$)/m', '  ', $finalOutput);

    return [$newFragmentName, $indentedOutput];
  }

  /**
   * Add aliases to the GraphQL query.
   *
   * @param string $text
   *   The text to add the alias to.
   * @param string $alias
   *   The alias to add.
   *
   * @return string
   *   The updated text.
   */
  protected function addAliases($text, $alias) {
    $pattern = '/\b(body|title|link|summary)(\s)/';
    $replacement = function ($matches) use ($alias) {
      $capitalized = ucfirst($matches[1]);
      return $alias . $capitalized . ': ' . $matches[1] . $matches[2];
    };

    return preg_replace_callback($pattern, $replacement, $text);
  }

  /**
   * Create a fragment and component file for a paragraph type.
   *
   * @param string $paragraph_type_id
   *   The ID of the paragraph type.
   *
   * @return string
   *   The output data.
   */
  protected function createParagraphFragment($paragraph_type_id) {
    $fragments = array_map(
      [$this->fragmentManager, 'getFragment'],
      $this->fragmentManager->getTypes()
    );

    $objects = array_filter(
      $fragments,
      fn($fragment) => $fragment['type'] instanceof ObjectType
    );

    $fragment_content = '';
    $fragment_name = '';

    foreach ($objects as $fragment) {
      if (!empty($fragment['bundle']) && $fragment['bundle'] === $paragraph_type_id) {
        $fragment_content = $fragment['content'];
        $fragment_name = $fragment['name'];
        break;
      }
    }

    if (!empty($fragment_content)) {
      // Transform the fragment to TypeScript.
      [$fragment_name, $fragment_content] = $this->transformFragment($fragment_content);

      // Add aliases to the GraphQL query.
      $fragment_content = $this->addAliases($fragment_content, $paragraph_type_id);

      // Create the new file path.
      $component_name = 'Paragraph' . str_replace('_', '', ucwords($paragraph_type_id, '_'));
      $new_fragment_file = "../nextjs/components/paragraphs/{$component_name}.tsx";

      // Generate the component content.
      $component_content = $this->generateComponentContent($component_name, $fragment_name, $fragment_content);

      // Write the new component file.
      $this->fileSystem->saveData($component_content, $new_fragment_file, FileSystemInterface::EXISTS_REPLACE);

      // Update the paragraph.ts file.
      $paragraphs_file = '../nextjs/graphql/fragments/paragraph.ts';
      $paragraphs_content = file_get_contents($paragraphs_file);

      // Add import statement.
      $import_statement = "import { {$fragment_name} } from \"@/components/paragraphs/{$component_name}\";";
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

      return "Component {$component_name} created in {$new_fragment_file}.\nParagraph.ts updated with new import and fragment.";
    }

    return "Error: Unable to create fragment for paragraph type '{$paragraph_type_id}'. No fragment content found.";
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
