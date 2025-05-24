# DrupalX AI Files

This directory contains data files used by the DrupalX AI module, including system prompts and sample data.

## Files

- `default-system-prompt.txt` - The default system prompt used when no custom prompt is configured in the admin interface.
- `sample-components.json` - Sample UI component definitions used by the AI for generating pages.
- `card.png` - Default placeholder image used when no other images are available.
- `lucide-icon-names.txt` - List of available Lucide icon names for use in components.

## Customizing Prompts

### Via Admin Interface (Recommended)
1. Navigate to `/admin/config/drupalx_ai/settings`
2. Expand the "AI Prompt Settings" section
3. Modify the "System Prompt" field
4. Save the configuration

### Via File Modification (Advanced)
You can modify the `default-system-prompt.txt` file directly, but be aware that:
- Changes will only affect new installations or when the admin form hasn't been customized
- Updates to the module may overwrite your changes
- It's recommended to use the admin interface for persistent customizations

## Other Files

### Sample Components (`sample-components.json`)
This file contains the component definitions that the AI uses as examples when generating new pages. You can modify this file to:
- Add new component types
- Update existing component structures
- Provide better examples for the AI

### Default Image (`card.png`)
A fallback placeholder image used when other image services are unavailable or fail.

### Lucide Icons (`lucide-icon-names.txt`)
A reference file containing all available Lucide icon names that can be used in components.

## Placeholders

The following placeholders are automatically replaced when the prompt is used:

- `{allowed_types}` - Replaced with a comma-separated list of allowed Drupal paragraph bundle machine names
- `{components_json}` - Replaced with the JSON data of sample components available to the AI

## Tips for Writing Effective Prompts

1. Be specific about the expected output format
2. Include clear instructions about required fields
3. Provide examples when possible
4. Use the placeholders to inject dynamic data
5. Consider the AI model's context window when writing lengthy prompts

## Troubleshooting

If the default prompt file cannot be read, the system will fall back to a hardcoded version of the prompt. Check the Drupal logs for any file reading errors.
