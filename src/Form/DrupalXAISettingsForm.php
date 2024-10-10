<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure DrupalX AI settings for this site.
 */
class DrupalXAISettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'drupalx_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['drupalx_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('drupalx_ai.settings');

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anthropic API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Enter your Anthropic API key.'),
      '#required' => TRUE,
    ];

    $form['image_generator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image Generator'),
      '#options' => [
        'unsplash' => $this->t('Unsplash'),
        'pexels' => $this->t('Pexels'),
      ],
      '#default_value' => $config->get('image_generator') ?: 'unsplash',
      '#description' => $this->t('Choose the image generator service to use.'),
      '#required' => TRUE,
    ];

    $form['pexels_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pexels API Key'),
      '#default_value' => $config->get('pexels_api_key'),
      '#description' => $this->t('Enter your Pexels API key for fetching images.'),
      '#required' => TRUE,
    ];

    $form['unsplash_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unsplash API Key'),
      '#default_value' => $config->get('unsplash_api_key'),
      '#description' => $this->t('Enter your Unsplash API key for fetching images.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $this->config('drupalx_ai.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('image_generator', $form_state->getValue('image_generator'))
      ->set('pexels_api_key', $form_state->getValue('pexels_api_key'))
      ->set('unsplash_api_key', $form_state->getValue('unsplash_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
