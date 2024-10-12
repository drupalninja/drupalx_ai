<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\drupalx_ai\Service\AiLandingPageService;
use Drupal\drupalx_ai\Service\MockLandingPageService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for creating AI-generated landing pages.
 *
 * @Form(
 *   id = "drupalx_ai_create_ai_landing_form",
 *   title = @Translation("Create AI Landing Page"),
 *   description = @Translation("Form to create AI-generated landing pages.")
 * )
 */
class CreateAiLandingForm extends FormBase {

  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * The mock landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\MockLandingPageService
   */
  protected $mockLandingPageService;

  /**
   * Constructs a new CreateAiLandingForm.
   *
   * @param \Drupal\drupalx_ai\Service\AiLandingPageService $ai_landing_page_service
   *   The AI landing page service.
   * @param \Drupal\drupalx_ai\Service\MockLandingPageService $mock_landing_page
   *   The mock landing page service.
   */
  public function __construct(AiLandingPageService $ai_landing_page_service, MockLandingPageService $mock_landing_page) {
    $this->aiLandingPageService = $ai_landing_page_service;
    $this->mockLandingPageService = $mock_landing_page;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('drupalx_ai.ai_landing_page_service'),
      $container->get('drupalx_ai.mock_landing_page')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupalx_ai_create_ai_landing_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $ai_icon = '
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" style="width:25px;"><!--!Font Awesome Free 6.6.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2024 Fonticons, Inc.--><path d="M234.7 42.7L197 56.8c-3 1.1-5 4-5 7.2s2 6.1 5 7.2l37.7 14.1L248.8 123c1.1 3 4 5 7.2 5s6.1-2 7.2-5l14.1-37.7L315 71.2c3-1.1 5-4 5-7.2s-2-6.1-5-7.2L277.3 42.7 263.2 5c-1.1-3-4-5-7.2-5s-6.1 2-7.2 5L234.7 42.7zM46.1 395.4c-18.7 18.7-18.7 49.1 0 67.9l34.6 34.6c18.7 18.7 49.1 18.7 67.9 0L529.9 116.5c18.7-18.7 18.7-49.1 0-67.9L495.3 14.1c-18.7-18.7-49.1-18.7-67.9 0L46.1 395.4zM484.6 82.6l-105 105-23.3-23.3 105-105 23.3 23.3zM7.5 117.2C3 118.9 0 123.2 0 128s3 9.1 7.5 10.8L64 160l21.2 56.5c1.7 4.5 6 7.5 10.8 7.5s9.1-3 10.8-7.5L128 160l56.5-21.2c4.5-1.7 7.5-6 7.5-10.8s-3-9.1-7.5-10.8L128 96 106.8 39.5C105.1 35 100.8 32 96 32s-9.1 3-10.8 7.5L64 96 7.5 117.2zm352 256c-4.5 1.7-7.5 6-7.5 10.8s3 9.1 7.5 10.8L416 416l21.2 56.5c1.7 4.5 6 7.5 10.8 7.5s9.1-3 10.8-7.5L480 416l56.5-21.2c4.5-1.7 7.5-6 7.5-10.8s-3-9.1-7.5-10.8L480 352l-21.2-56.5c-1.7-4.5-6-7.5-10.8-7.5s-9.1 3-10.8 7.5L416 352l-56.5 21.2z"/></svg>
    ';

    $form['title'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('<h2>' . $this->t('How to Create AI Landing Page') . $ai_icon . '</h2>'),
    ];

    $form['instructions'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($this->t('
        <ol>
          <li>In the text area below, provide a detailed description of the landing page you want to create.</li>
          <li>Include information about the purpose of the page, target audience, key messages, and any specific sections or content you want to include.</li>
          <li>Our AI will generate content based on your description, using the available paragraph types listed below.</li>
          <li>After submission, you\'ll be redirected to edit the generated landing page, where you can make any necessary adjustments.</li>
        </ol>
        <p><strong>Tip:</strong> The more specific and detailed your description, the better the AI-generated content will match your needs.</p>
      ')),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#rows' => 10,
      '#title' => $this->t('Landing Page Description'),
      '#description' => $this->t('Provide a detailed description of the landing page content you want to generate.'),
      '#required' => TRUE,
    ];

    $allowed_paragraph_types = $this->mockLandingPageService->getAllowedParagraphTypes('node', 'landing', 'field_content', TRUE);
    $filtered_paragraph_types = array_filter($allowed_paragraph_types, function($type) {
      return $type !== 'Views';
    });
    $paragraph_type_list = implode(', ', $filtered_paragraph_types);

    $form['paragraph_types_info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p><strong>Available paragraph types:</strong> @types</p>', ['@types' => $paragraph_type_list]),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create AI Landing Page'),
      '#attributes' => [
        'onclick' => 'this.disabled=true; this.style.opacity=0.6; this.style.cursor="not-allowed"; document.getElementById("waiting-message").classList.remove("hidden"); this.form.submit();',
      ],
    ];

    $form['waiting_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="waiting-message" class="hidden"><img src="/core/misc/throbber-active.gif" alt="Loading..."> ' . $this->t('Please wait while we create your AI landing page...') . '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $description = $form_state->getValue('description');
    $data = $this->aiLandingPageService->generateAiContent($description);

    if ($data) {
      $edit_url = $this->aiLandingPageService->createLandingNodeWithAiContent($data['page_title'], $data['paragraphs']);
      $this->messenger()->addStatus($this->t('AI landing page created successfully.'));
      $form_state->setRedirectUrl(Url::fromUri($edit_url));
    }
    else {
      $this->messenger()->addError($this->t('Failed to generate AI landing page content.'));
    }
  }

}
