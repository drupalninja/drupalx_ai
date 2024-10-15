# DrupalX AI Module

## Overview

The DrupalX AI module integrates AI-powered functionality into Drupal, specifically for importing paragraph types based on Next.js components. This module uses the Anthropic Claude API and OpenAI to analyze Next.js components and suggest corresponding Drupal paragraph type structures.

## Features

- Drush command for importing paragraph types from Next.js components
- Integration with Anthropic's Claude and OpenAI API for AI-powered analysis
- Configurable API key settings
- Automatic generation of Drupal paragraph type structures

## Requirements

- Drupal 10
- Drush 11+
- Anthropic or OpenAI API key

## Installation

1. Use Composer to require the module:
   ```
   composer require drupalninja/drupalx_ai
   ```
2. Enable the module via Drush or the Drupal admin interface:
   ```
   drush en drupalx_ai
   ```

## Configuration

1. Navigate to the DrupalX AI settings page: `/admin/config/drupalx_ai/settings`
2. Enter your Anthropic API key in the provided field
3. Save the configuration

## Usage

### Importing a Paragraph Type from a Next.js Component

Use the following Drush command to start the import process:

```
drush drupalx-ai:import-from-component
```

or use the alias:

```
drush dai-ifc
```

The command will guide you through the following steps:

1. Select a Next.js component from the `../nextjs/components/` directory
2. Choose a specific file from the selected component directory
3. Review the AI-generated paragraph type structure
4. Confirm the import

### Notes

- Ensure your Next.js components are located in the `../nextjs/components/` directory relative to your Drupal installation
- The module will only consider `.tsx` files that are not story files (i.e., not ending with `.stories.tsx`)
- The generated paragraph type names will not include the word "paragraph"
- Field names will only use lowercase alphanumeric characters and underscores, with the first character being a lowercase letter or underscore

## Troubleshooting

- If you encounter API-related errors, ensure your Anthropic API key is correctly set in the module configuration
- Check the Drupal logs for detailed error messages and debugging information

## Contributing

Contributions to the DrupalX AI module are welcome! Please submit pull requests with any enhancements, bug fixes, or documentation improvements.

## License

This module is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html).
