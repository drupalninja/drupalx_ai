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

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anthropic API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Enter your Anthropic API key.'),
      '#required' => TRUE,
    ];

    $form['claude_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Claude Model'),
      '#options' => [
        'claude-3-haiku-20240307' => $this->t('Claude 3 Haiku (Faster, cheaper)'),
        'claude-3-sonnet-20240229' => $this->t('Claude 3 Sonnet (More capable)'),
      ],
      '#default_value' => $config->get('claude_model') ?: 'claude-3-haiku-20240307',
      '#description' => $this->t('Choose the Claude model to use. Haiku is faster and cheaper, while Sonnet is more capable.'),
      '#required' => TRUE,
    ];

    $form['image_generator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image Generator'),
      '#options' => [
        'placeholder' => $this->t('Placeholder (no key required)'),
        'unsplash' => $this->t('Unsplash'),
        'pexels' => $this->t('Pexels'),
      ],
      '#default_value' => $config->get('image_generator') ?: 'placeholder',
      '#description' => $this->t('Choose the image generator service to use.'),
      '#required' => TRUE,
    ];

    $form['pexels_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pexels API Key'),
      '#default_value' => $config->get('pexels_api_key'),
      '#description' => $this->t('Enter your Pexels API key for fetching images.'),
      '#states' => [
        'required' => [
          ':input[name="image_generator"]' => ['value' => 'pexels'],
        ],
        'visible' => [
          ':input[name="image_generator"]' => ['value' => 'pexels'],
        ],
      ],
    ];

    $form['unsplash_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unsplash API Key'),
      '#default_value' => $config->get('unsplash_api_key'),
      '#description' => $this->t('Enter your Unsplash API key for fetching images.'),
      '#states' => [
        'required' => [
          ':input[name="image_generator"]' => ['value' => 'unsplash'],
        ],
        'visible' => [
          ':input[name="image_generator"]' => ['value' => 'unsplash'],
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

    $image_generator = $form_state->getValue('image_generator');

    if ($image_generator === 'pexels' && empty($form_state->getValue('pexels_api_key'))) {
      $form_state->setErrorByName('pexels_api_key', $this->t('Pexels API Key is required when Pexels is selected as the image generator.'));
    }

    if ($image_generator === 'unsplash' && empty($form_state->getValue('unsplash_api_key'))) {
      $form_state->setErrorByName('unsplash_api_key', $this->t('Unsplash API Key is required when Unsplash is selected as the image generator.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('drupalx_ai.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('claude_model', $form_state->getValue('claude_model'))
      ->set('image_generator', $form_state->getValue('image_generator'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
