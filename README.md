# DrupalX AI Module

## Overview

This module provides AI-powered features for Drupal, including a chatbot
widget and Drush commands to generate stub landing pages. It is designed to
integrate with OpenAI-compatible APIs for content generation.

## Features

*   **AI Chatbot Widget:** A block that provides a user interface to interact
    with an AI. Initially, it creates stub "landing_page" nodes based on user
    input. Future development will integrate with an AI service for dynamic
    content generation.
*   **Drush Command:** A command (`drupalx_ai:create-landing-page` or alias
    `dx-clp`) to create a stub "landing_page" node.

## Requirements

*   Drupal ^9 or ^10.
*   PHP 7.4 or higher (as required by dependencies).
*   Composer.
*   A content type with the machine name `landing_page`. This content type should
    at least have a title and a body field.
*   The `openai-php/client` Composer package (installed automatically).

## Installation

1.  **Download the module:**
    Place the `drupalx_ai` module directory within your Drupal site's
    `modules/contrib` (or `modules/custom`) directory.

2.  **Install dependencies:**
    Navigate to your Drupal root directory in the terminal and run:
    ```bash
    ddev composer require openai-php/client
    ```
    (If you are not using DDEV, run `composer require openai-php/client` directly.)

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

## Configuration and Usage

### Content Type

Ensure you have a content type with the machine name `landing_page`. If you use a
different machine name, you will need to update the code in:
*   `src/Commands/DrupalxAiCommands.php`
*   `src/Controller/ChatbotController.php`

### Chatbot Block

1.  Go to Structure > Block layout (`/admin/structure/block`).
2.  Choose a region and click "Place block".
3.  Find "DrupalX AI Chatbot" in the list and place it.
4.  Configure the block if needed (currently no specific configuration options).

The chatbot widget will then be available on pages where that region is displayed.
It will allow users to type a message, and upon submission, a new `landing_page`
node will be created with a title and body derived from the message.

### Drush Command

To create a stub landing page via Drush, run the following command from your
Drupal root:

```bash
ddev drush drupalx_ai:create-landing-page
```

Or using the alias:

```bash
ddev drush dx-clp
```

This will create a new `landing_page` node with placeholder content.

## Future Development

*   Integrate the `openai-php/client` with a compatible API (e.g., OpenAI API or
    a self-hosted solution) in `ChatbotController.php` to generate dynamic
    content for landing pages instead of stubs.
*   Allow configuration of API keys and endpoints through the Drupal UI.
*   Expand the chatbot's capabilities beyond landing page creation.
*   Enhance the Drush command to accept parameters for page generation.

## Notes

*   The module currently uses a hardcoded user ID (1) for node creation. This
    should be adjusted based on your site's requirements.
*   The AJAX endpoint for the chatbot (`/_drupalx_ai/chatbot/process`) is set to
    allow POST requests only and requires the 'access content' permission.
