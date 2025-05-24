# DrupalX AI Module

## Overview

The DrupalX AI module provides comprehensive AI-powered content generation features for Drupal sites. It leverages OpenAI-compatible APIs to generate dynamic content including paragraphs, images, and complete page layouts. The module is designed to work with paragraph-based content architecture and supports multiple image generation services.

## Features

*   **AI-Powered Page Generation:** Generate complete landing pages with structured paragraph content using AI prompts.
*   **Chatbot Widget:** A configurable block that allows users to describe content they want created, which then generates AI-powered page content.
*   **Multiple Image Services:** Support for Pexels, Unsplash, and placeholder images for generated content.
*   **Flexible Paragraph Support:** Works with paragraph entities to create rich, structured content layouts.
*   **Drush Commands:** Command-line tools for generating pages programmatically.
*   **Configurable AI Settings:** Full admin interface for configuring API endpoints, models, and prompts.
*   **Content Validation:** Built-in validation services to ensure generated content meets Drupal standards.
*   **Taxonomy Integration:** Automatic taxonomy term creation and assignment for generated content.

## Requirements

*   Drupal ^9 or ^10
*   PHP 8.1 or higher
*   Composer
*   The `drupal/key` module (for secure API key storage)
*   The `openai-php/client` Composer package (installed automatically)
*   A content type configured for landing pages (typically 'landing')
*   Paragraph types configured for the content structure you want to generate

## Installation

1.  **Download the module:**
    Place the `drupalx_ai` module directory within your Drupal site's
    `modules/contrib` (or `modules/custom`) directory.

2.  **Install dependencies:**
    Navigate to your Drupal root directory in the terminal and run:
    ```bash
    ddev composer require nextagencyio/drupalx_ai
    ```
    (If you are not using DDEV, run `composer require nextagencyio/drupalx_ai` directly.)

3.  **Enable the module:**
    Enable the "DrupalX AI" module through the Drupal UI (Extend page) or by
    using Drush:
    ```bash
    ddev drush en drupalx_ai -y
    ```

4.  **Clear Drupal's cache:**
    ```bash
    ddev drush cr
    ```

## Configuration

### API Configuration

1.  **Create API Keys:**
    - Go to Configuration > System > Keys (`/admin/config/system/keys`)
    - Create keys for your AI service API (e.g., OpenAI, OpenRouter)
    - Optionally create keys for image services (Pexels, Unsplash)

2.  **Configure AI Settings:**
    - Navigate to Configuration > DrupalX AI Settings (`/admin/config/drupalx_ai/settings`)
    - Set your API endpoint URL (default: `https://openrouter.ai/api/v1`)
    - Configure your AI model name (default: `qwen/qwq-32b:free`)
    - Select your API key from the dropdown
    - Choose your preferred image service (Placeholder, Pexels, or Unsplash)
    - Configure image service API keys if using external services

3.  **Customize AI Prompts:**
    - In the AI Settings form, expand "AI Prompt Settings"
    - Customize the system prompt to match your content generation needs
    - The prompt supports placeholders for allowed component types and sample data

### Content Type Configuration

The module works best with a content type configured for landing pages:

1.  Create or configure a content type (typically named 'landing')
2.  Add a paragraph reference field to store the generated content
3.  Ensure appropriate paragraph types are created for the components you want to generate

### Chatbot Block

1.  Go to Structure > Block layout (`/admin/structure/block`)
2.  Choose a region and click "Place block"
3.  Find "DrupalX AI Chatbot" in the list and place it
4.  Configure the block settings as needed

The chatbot widget allows users to describe content they want created, and the AI will generate structured paragraph content based on their input.

## Usage

### Drush Commands

#### Generate Page Command

Create AI-generated landing pages from the command line:

```bash
ddev drush drupalx_ai:generate-page "Create a page about sustainable energy solutions for urban environments"
```

Or using the alias:

```bash
ddev drush dxp "A promotional page for a new tech startup focused on AI-driven analytics" --uid=1
```

Options:
- `--uid`: Specify the user ID to assign as the page author (defaults to current user or user 1)

### Chatbot Interface

Users can interact with the chatbot block to generate content by:

1.  Typing a description of the content they want created
2.  Submitting the form
3.  The AI will process the request and create a new landing page with structured content

### API Integration

The module supports various OpenAI-compatible APIs including:
- OpenAI GPT models
- OpenRouter (default configuration)
- Local LLM deployments
- Custom AI endpoints

## Services and Architecture

The module provides several key services:

*   **AIService:** Core service for interacting with AI APIs
*   **ParagraphService:** Handles creation and management of paragraph entities
*   **ValidationService:** Validates generated content and entity structure
*   **MediaService:** Manages image and media file creation
*   **TaxonomyService:** Handles automatic taxonomy term creation
*   **ImageApiService:** Interfaces with external image services (Pexels, Unsplash)

## File Structure

*   `src/Service/` - Core services for AI, paragraph, and media management
*   `src/Form/` - Configuration forms for AI settings
*   `src/Controller/` - AJAX controllers for chatbot functionality
*   `src/Commands/` - Drush commands for content generation
*   `src/Plugin/Block/` - Chatbot block plugin
*   `files/` - Default prompts, sample data, and assets
*   `templates/` - Twig templates for UI components

## Customization

### Custom Prompts

You can customize the AI behavior by:

1.  Modifying the system prompt in the AI Settings form
2.  Editing the default prompt file at `files/default-system-prompt.txt`
3.  Using the `{allowed_types}` and `{components_json}` placeholders in your prompts

### Custom Paragraph Types

The module can generate content for any paragraph types configured in your Drupal site. Configure the validation service to recognize your custom paragraph types and their fields.

### Custom Image Services

Implement the `ImageApiServiceInterface` to add support for additional image services beyond Pexels and Unsplash.

## Security Considerations

*   API keys are securely stored using the Key module
*   AJAX endpoints require appropriate permissions
*   SSL verification can be configured per environment
*   Generated content goes through validation before being saved

## Troubleshooting

*   Check the Drupal logs for AI service errors
*   Verify API keys are properly configured in the Key module
*   Ensure the AI endpoint URL is accessible from your server
*   Verify paragraph types and content types are properly configured
*   Check that required permissions are granted to users

## Development and Future Features

The module provides a robust foundation for AI-powered content generation with plans for:

*   Enhanced content type support
*   Additional AI model integrations
*   Advanced content customization options
*   Multi-language content generation
*   Integration with additional image and media services
