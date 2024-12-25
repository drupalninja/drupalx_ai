<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure DrupalX AI settings for this site.
 */
class DrupalXAISettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupalx_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['drupalx_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('drupalx_ai.settings');

    // Theme settings.
    $form['theme'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Theme Settings'),
    ];

    $form['theme']['is_nextjs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Next.js Theme?'),
      '#description' => $this->t('Enable if this is a Next.js theme.'),
      '#default_value' => $config->get('is_nextjs') ?: FALSE,
    ];

    // AI Provider settings.
    $form['ai_provider'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI Provider Settings'),
    ];

    $form['ai_provider']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#options' => [
        'anthropic' => $this->t('Anthropic'),
        'openai' => $this->t('OpenAI'),
        'groq' => $this->t('Groq'),
        'fireworks' => $this->t('Fireworks'),
        'nebius' => $this->t('Nebius'),
      ],
      '#default_value' => $config->get('ai_provider') ?: 'anthropic',
      '#required' => TRUE,
    ];

    $form['ai_provider']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Enter your API key for the selected provider.'),
      '#required' => TRUE,
    ];

    // Anthropic-specific settings.
    $form['ai_provider']['anthropic_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Anthropic Settings'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => ['value' => 'anthropic'],
        ],
      ],
    ];

    $form['ai_provider']['anthropic_settings']['claude_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Claude Model'),
      '#options' => [
        'claude-3-haiku-20240307' => $this->t('Claude 3 Haiku (Faster, cheaper)'),
        'claude-3-sonnet-20240229' => $this->t('Claude 3 Sonnet (More capable)'),
        'claude-3-opus-20240229' => $this->t('Claude 3 Opus (Most capable)'),
      ],
      '#default_value' => $config->get('claude_model') ?: 'claude-3-haiku-20240307',
      '#description' => $this->t('Choose the Claude model to use.'),
    ];

    // OpenAI-specific settings.
    $form['ai_provider']['openai_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OpenAI Settings'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['ai_provider']['openai_settings']['openai_model'] = [
      '#type' => 'select',
      '#title' => $this->t('OpenAI Model'),
      '#options' => [
        'gpt-3.5-turbo' => $this->t('GPT-3.5 Turbo (Very fast, cheap)'),
        'gpt-4o' => $this->t('GPT-4o (More capable)'),
        'gpt-4o-mini' => $this->t('GPT-4o mini (Faster, cheaper)'),
      ],
      '#default_value' => $config->get('openai_model') ?: 'gpt-4o-mini',
      '#description' => $this->t('Choose the OpenAI model to use.'),
    ];

    // Groq-specific settings.
    $form['ai_provider']['groq_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Groq Settings'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => ['value' => 'groq'],
        ],
      ],
    ];

    $form['ai_provider']['groq_settings']['groq_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Groq Model'),
      '#options' => [
        'llama-3.1-70b-versatile' => $this->t('LLama 3.1-70B (Versatile)'),
      ],
      '#default_value' => $config->get('groq_model') ?: 'mixtral-8x7b-32768',
      '#description' => $this->t('Choose the Groq model to use.'),
    ];

    // Fireworks-specific settings.
    $form['ai_provider']['fireworks_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fireworks Settings'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => ['value' => 'fireworks'],
        ],
      ],
    ];

    $form['ai_provider']['fireworks_settings']['fireworks_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Fireworks Model'),
      '#options' => [
        'accounts/fireworks/models/firefunction-v2' => $this->t('Firefunction v2'),
      ],
      '#default_value' => $config->get('fireworks_model') ?: 'accounts/fireworks/models/firefunction-v2',
      '#description' => $this->t('Choose the Fireworks model to use.'),
    ];

    // Nebius-specific settings.
    $form['ai_provider']['nebius_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Nebius Settings'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => ['value' => 'nebius'],
        ],
      ],
    ];

    $form['ai_provider']['nebius_settings']['nebius_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Nebius Model'),
      '#options' => [
        'meta-llama/Llama-3.3-70B-Instruct-fast' => $this->t('Llama-3.3-70B-Instruct (fast)'),
      ],
      '#default_value' => $config->get('nebius_model') ?: 'meta-llama/Llama-3.3-70B-Instruct-fast',
      '#description' => $this->t('Choose the Nebius model to use.'),
    ];

    $form['image_generator'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Image Generator Settings'),
    ];

    $form['image_generator']['service'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image Generator'),
      '#options' => [
        'placeholder' => $this->t('Placeholder (no key required)'),
        'unsplash' => $this->t('Unsplash'),
        'pexels' => $this->t('Pexels'),
        'tavily' => $this->t('Tavily'),
      ],
      '#default_value' => $config->get('image_generator') ?: 'placeholder',
      '#description' => $this->t('Choose the image generator service to use.'),
      '#required' => TRUE,
    ];

    $form['image_generator']['pexels_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pexels API Key'),
      '#default_value' => $config->get('pexels_api_key'),
      '#description' => $this->t('Enter your Pexels API key for fetching images.'),
      '#states' => [
        'required' => [
          ':input[name="service"]' => ['value' => 'pexels'],
        ],
        'visible' => [
          ':input[name="service"]' => ['value' => 'pexels'],
        ],
      ],
    ];

    $form['image_generator']['unsplash_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unsplash API Key'),
      '#default_value' => $config->get('unsplash_api_key'),
      '#description' => $this->t('Enter your Unsplash API key for fetching images.'),
      '#states' => [
        'required' => [
          ':input[name="service"]' => ['value' => 'unsplash'],
        ],
        'visible' => [
          ':input[name="service"]' => ['value' => 'unsplash'],
        ],
      ],
    ];

    $form['image_generator']['tavily_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tavily API Key'),
      '#default_value' => $config->get('tavily_api_key'),
      '#description' => $this->t('Enter your Tavily API key for fetching images.'),
      '#states' => [
        'required' => [
          ':input[name="service"]' => ['value' => 'tavily'],
        ],
        'visible' => [
          ':input[name="service"]' => ['value' => 'tavily'],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $provider = $form_state->getValue('provider');
    $image_generator = $form_state->getValue('service');

    if ($provider === 'anthropic' && empty($form_state->getValue('claude_model'))) {
      $form_state->setErrorByName('claude_model', $this->t('Claude Model is required when Anthropic is selected as the AI provider.'));
    }

    if ($provider === 'openai' && empty($form_state->getValue('openai_model'))) {
      $form_state->setErrorByName('openai_model', $this->t('OpenAI Model is required when OpenAI is selected as the AI provider.'));
    }

    if ($provider === 'groq' && empty($form_state->getValue('groq_model'))) {
      $form_state->setErrorByName('groq_model', $this->t('Groq Model is required when Groq is selected as the AI provider.'));
    }

    if ($provider === 'fireworks' && empty($form_state->getValue('fireworks_model'))) {
      $form_state->setErrorByName('fireworks_model', $this->t('Fireworks Model is required when Fireworks is selected as the AI provider.'));
    }

    if ($provider === 'nebius' && empty($form_state->getValue('nebius_model'))) {
      $form_state->setErrorByName('nebius_model', $this->t('Nebius Model is required when Nebius is selected as the AI provider.'));
    }

    if ($image_generator === 'pexels' && empty($form_state->getValue('pexels_api_key'))) {
      $form_state->setErrorByName('pexels_api_key', $this->t('Pexels API Key is required when Pexels is selected as the image generator.'));
    }

    if ($image_generator === 'unsplash' && empty($form_state->getValue('unsplash_api_key'))) {
      $form_state->setErrorByName('unsplash_api_key', $this->t('Unsplash API Key is required when Unsplash is selected as the image generator.'));
    }

    if ($image_generator === 'tavily' && empty($form_state->getValue('tavily_api_key'))) {
      $form_state->setErrorByName('tavily_api_key', $this->t('Tavily API Key is required when Tavily is selected as the image generator.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('drupalx_ai.settings')
      ->set('is_nextjs', $form_state->getValue('is_nextjs'))
      ->set('ai_provider', $form_state->getValue('provider'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('claude_model', $form_state->getValue('claude_model'))
      ->set('openai_model', $form_state->getValue('openai_model'))
      ->set('groq_model', $form_state->getValue('groq_model'))
      ->set('fireworks_model', $form_state->getValue('fireworks_model'))
      ->set('nebius_model', $form_state->getValue('nebius_model'))
      ->set('image_generator', $form_state->getValue('service'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->set('tavily_api_key', $form_state->getValue('tavily_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
