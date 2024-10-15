# DrupalX AI Module

## Overview

The DrupalX AI module integrates AI-powered functionality into Drupal, enhancing development workflows and content creation. This module leverages the Anthropic Claude API and OpenAI to analyze Next.js components, generate Storybook stories, create Cypress tests, and assist in landing page creation.

## Features

- Drush command for importing paragraph types from Next.js components
- Generation of Storybook stories for components
- Creation of Cypress tests for components
- AI-assisted landing page creation and mock-up
- Integration with Anthropic's Claude and OpenAI API for AI-powered analysis
- Configurable API key settings
- Automatic generation of Drupal paragraph type structures
- Tailwind theme configuration updates

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
2. Enter your Anthropic or OpenAI API key in the provided field
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

### Generating Storybook Stories

To generate Storybook stories for your components, use:

```
drush drupalx-ai:generate-storybook
```

### Creating Cypress Tests

To create Cypress tests for your components, use:

```
drush drupalx-ai:generate-cypress
```

### Creating Mock Landing Pages

To create a mock landing page, use:

```
drush drupalx-ai:create-mock-landing
```

### AI-Assisted Landing Page Creation

For AI-assisted landing page creation, use:

```
drush drupalx-ai:ai-landing-page
```

### Updating Tailwind Theme Configuration

To update your Tailwind theme configuration, use:

```
drush drupalx-ai:update-tailwind-theme
```

## Notes

- Ensure your Next.js components are located in the `../nextjs/components/` directory relative to your Drupal installation
- The module will only consider `.tsx` files that are not story files (i.e., not ending with `.stories.tsx`)
- Generated paragraph type names will not include the word "paragraph"
- Field names will only use lowercase alphanumeric characters and underscores, with the first character being a lowercase letter or underscore

## Troubleshooting

- If you encounter API-related errors, ensure your API key is correctly set in the module configuration
- Check the Drupal logs for detailed error messages and debugging information

## Contributing

Contributions to the DrupalX AI module are welcome! Please submit pull requests with any enhancements, bug fixes, or documentation improvements.

## License

This module is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html).
