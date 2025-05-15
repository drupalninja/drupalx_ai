<?php

namespace Drupal\drupalx_ai\Service;

/**
 * Service for formatting command output with colors and icons.
 */
class OutputFormatterService {

  /**
   * Icon mapping for different types of logs.
   *
   * @var array
   */
  protected array $iconMap = [
    // Entity operations
    'creating' => '🔨',
    'created' => '✅',
    'clearing' => '🧹',
    'generating' => '⚡',
    'loading' => '📂',
    'adding' => '➕',
    'added' => '✓',
    'skipping' => '⏭️',
    'processing' => '⚙️',
    'filtering' => '🔍',
    'normalization' => '🔄',
    'blocking' => '🛑',
    'warning' => '⚠️',
    'error' => '❌',
    'success' => '🎉',
    'completed' => '✨',
    'found' => '🔎',
    'id' => '🔢',

    // Media operations
    'media' => '🖼️',
    'image' => '📷',
    'video' => '🎬',
    'audio' => '🔊',
    'document' => '📄',
    'remote_video' => '📺',
    'placeholder' => '🔲',
    'url' => '🌐',
    'alt' => '🔤',
    'logo' => '®️',

    // Entity types
    'node' => '📝',
    'paragraph' => '📌',
    'hero' => '🏔️',
    'card' => '🃏',
    'card_group' => '🗂️',
    'text' => '📄',
    'quote' => '💬',
    'banner' => '🚩',
    'accordion' => '🪗',
    'tabs' => '📑',
    'newsletter' => '📨',
    'cta' => '🔔',
    'logo_collection' => '🏢',
    'stats' => '📊',
    'features' => '⭐',
    'feature_item' => '✨',
    'timeline' => '📅',
    'slider' => '🎚️',
    'gallery' => '🖼️',
    'content' => '📋',
    'title' => '🔠',
    'description' => '📝',
    'summary' => '💬',
    'heading' => '📍',

    // Fields and data
    'field' => '🏷️',
    'component' => '🧩',
    'type' => '📋',
    'data' => '💾',
    'array' => '📚',
    'link' => '🔗',
    'json' => '📊',
    'value' => '📝',
    'format' => '🔠',
  ];

  /**
   * Color map for different message types.
   *
   * @var array
   */
  protected array $colorMap = [
    'notice' => 'blue',
    'warning' => 'yellow',
    'error' => 'red',
    'success' => 'green',
    'info' => 'cyan',
  ];

  /**
   * Format a log message with appropriate icon and color.
   *
   * @param string $message
   *   The message to format.
   * @param string $type
   *   The message type (notice, warning, error, etc.).
   * @param array $context
   *   Additional context for the message.
   *
   * @return string
   *   The formatted message.
   */
  public function formatLogMessage(string $message, string $type = 'notice', array $context = []): string {
    // Determine icon based on message content.
    $icon = $this->getIconForMessage($message);

    // Extract component type if present for highlighting.
    $componentType = $this->extractComponentType($message);

    // Determine color based on type.
    $color = $this->colorMap[$type] ?? 'white';

    // Process the message with replaced context.
    $processedMessage = $this->replaceContextInMessage($message, $context);

    // Apply highlighting to specific parts of the message.
    if ($componentType && isset($this->iconMap[$componentType])) {
      $componentIcon = $this->iconMap[$componentType];
      $processedMessage = preg_replace(
        '/\b(' . preg_quote($componentType, '/') . ')\b/i',
        "<fg=magenta;options=bold>$componentIcon $1</>",
        $processedMessage
      );
    }

    // Highlight IDs, paths, etc.
    $processedMessage = preg_replace('/([0-9]+)/', "<fg=yellow;options=bold>$1</>", $processedMessage);

    // Format the message with icon and color.
    $formatted = "<fg=$color>$icon</> " . $processedMessage;

    return $formatted;
  }

  /**
   * Get appropriate icon for a message based on its content.
   *
   * @param string $message
   *   The message content.
   *
   * @return string
   *   The icon to use.
   */
  protected function getIconForMessage(string $message): string {
    $messageLower = strtolower($message);

    // Check for specific component types (hero, card, etc.)
    foreach ($this->iconMap as $keyword => $icon) {
      if (strpos($messageLower, $keyword) !== FALSE) {
        return $icon;
      }
    }

    // Check for specific operations
    if (strpos($messageLower, 'creat') === 0) {
      return $this->iconMap['creating'];
    }
    elseif (strpos($messageLower, 'add') === 0) {
      return $this->iconMap['adding'];
    }
    elseif (strpos($messageLower, 'media') !== FALSE) {
      return $this->iconMap['media'];
    }

    // Default
    return '📌';
  }

  /**
   * Extracts component type from a message string.
   *
   * @param string $message
   *   The message to extract from.
   *
   * @return string|null
   *   The component type if found, null otherwise.
   */
  protected function extractComponentType(string $message): ?string {
    $messageLower = strtolower($message);

    // List of known component types to search for
    $componentTypes = [
      'hero', 'card_group', 'card', 'text', 'quote', 'banner', 'accordion',
      'tabs', 'newsletter', 'cta', 'logo_collection', 'stats', 'feature_item',
      'features', 'timeline', 'slider', 'gallery', 'sidebyside'
    ];

    // Look for component type mentions
    foreach ($componentTypes as $type) {
      if (strpos($messageLower, $type) !== FALSE) {
        return $type;
      }
    }

    // Check for "Component type:" pattern which often appears in logs
    if (preg_match('/component type[\s]*:([\s]*)(\w+)/', $messageLower, $matches)) {
      if (isset($matches[2]) && !empty($matches[2])) {
        return $matches[2];
      }
    }

    // Check for "Creating nested paragraph from component:" pattern
    if (strpos($messageLower, 'creating nested paragraph from component') !== FALSE) {
      if (preg_match('/"type"[\s]*:[\s]*"([^"]+)"/', $messageLower, $matches)) {
        if (isset($matches[1]) && !empty($matches[1])) {
          return $matches[1];
        }
      }
    }

    return null;
  }

  /**
   * Replace context placeholders in a message.
   *
   * @param string $message
   *   The message with placeholders.
   * @param array $context
   *   The context with replacement values.
   *
   * @return string
   *   The message with replaced values.
   */
  protected function replaceContextInMessage(string $message, array $context): string {
    // Replace placeholders like @variable with their values
    foreach ($context as $key => $value) {
      if (is_string($key) && strpos($key, '@') === 0) {
        $placeholder = $key;
        $realValue = is_scalar($value) ? $value : json_encode($value);
        $message = str_replace($placeholder, $realValue, $message);
      }
    }

    return $message;
  }

  /**
   * Gets an appropriate icon based on component type.
   *
   * @param string $type
   *   The component type.
   *
   * @return string
   *   An emoji icon representing the component type.
   */
  public function getComponentIcon(string $type): string {
    return $this->iconMap[$type] ?? '📦';
  }

}
