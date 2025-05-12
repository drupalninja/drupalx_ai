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
        'groq' => $this->t('Groq'),
      ],
      '#default_value' => 'groq',
      '#required' => TRUE,
      '#access' => FALSE,
    ];

    $form['ai_provider']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Enter your API key for the selected provider.'),
      '#required' => TRUE,
    ];

    // Groq-specific settings.
    $form['ai_provider']['groq_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Groq Settings'),
    ];

    $form['ai_provider']['groq_settings']['groq_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Groq Model'),
      '#options' => [
        'meta-llama/llama-4-maverick-17b-128e-instruct' => $this->t('meta-llama/llama-4-maverick-17b-128e-instruct'),
        'meta-llama/llama-4-scout-17b-16e-instruct' => $this->t('meta-llama/llama-4-scout-17b-16e-instruct'),
      ],
      '#default_value' => $config->get('groq_model') ?: 'meta-llama/llama-4-maverick-17b-128e-instruct',
      '#description' => $this->t('Choose the Groq model to use.'),
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

    $image_generator = $form_state->getValue('service');

    if (empty($form_state->getValue('groq_model'))) {
      $form_state->setErrorByName('groq_model', $this->t('Groq Model is required.'));
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
      ->set('ai_provider', 'groq')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('groq_model', $form_state->getValue('groq_model'))
      ->set('image_generator', $form_state->getValue('service'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->set('tavily_api_key', $form_state->getValue('tavily_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
