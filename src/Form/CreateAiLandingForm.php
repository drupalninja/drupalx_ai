<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupalx_ai\Service\AiLandingPageService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

class CreateAiLandingForm extends FormBase
{
  /**
   * The AI landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\AiLandingPageService
   */
  protected $aiLandingPageService;

  /**
   * Constructs a new CreateAiLandingForm.
   *
   * @param \Drupal\drupalx_ai\Service\AiLandingPageService $ai_landing_page_service
   *   The AI landing page service.
   */
  public function __construct(AiLandingPageService $ai_landing_page_service)
  {
    $this->aiLandingPageService = $ai_landing_page_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('drupalx_ai.ai_landing_page_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'drupalx_ai_create_ai_landing_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['description'] = [
      '#type' => 'textarea',
      '#rows' => 10,
      '#title' => $this->t('Landing Page Description'),
      '#description' => $this->t('Provide a description of the landing page content you want to generate.'),
      '#required' => TRUE,
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
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $description = $form_state->getValue('description');
    $paragraphs = $this->aiLandingPageService->generateAiContent($description);

    if ($paragraphs) {
      $edit_url = $this->aiLandingPageService->createLandingNodeWithAiContent($paragraphs);
      $this->messenger()->addStatus($this->t('AI landing page created successfully.'));
      $form_state->setRedirectUrl(Url::fromUri($edit_url));
    } else {
      $this->messenger()->addError($this->t('Failed to generate AI landing page content.'));
    }
  }
}
