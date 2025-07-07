# DrupalX AI Groq

This submodule provides pre-configured Groq AI integration for DrupalX AI.

## What it does

When enabled, this module automatically:

1. **Enables Dependencies**: Installs and enables the `ai_provider_groq` module
2. **Creates API Key**: Sets up a `groq_api_key` key entity (initially blank)
3. **Configures Groq Provider**: Associates the API key with Groq provider settings
4. **Sets Default Model**: Configures `llama-3.3-70b-versatile` as the default model
5. **Updates Token Limit**: Sets token limit to 4000 for optimal performance
6. **Updates DrupalX Settings**: Configures DrupalX AI to use Groq by default
7. **Shows Setup Link**: Displays a message with link to add your API key

## Installation

1. Enable the module:
   ```bash
   drush en drupalx_ai_groq -y
   ```

2. Add your Groq API key:
   - Click the link in the installation message, or
   - Go to Configuration > System > Keys
   - Edit the "Groq API Key" entry
   - Add your actual Groq API key

## Features

- **Zero Configuration**: Works out of the box with optimal settings
- **Production Ready**: Uses the powerful `llama-3.3-70b-versatile` model
- **Optimized Settings**: 4000 token limit for complex content generation
- **Secure**: API keys managed through Drupal's Key module

## Requirements

- DrupalX AI module
- Drupal AI module
- Key module
- Groq API account and API key

## Getting a Groq API Key

1. Visit [Groq Console](https://console.groq.com/)
2. Sign up or log in
3. Navigate to API Keys section
4. Create a new API key
5. Copy the key and add it to the Drupal configuration