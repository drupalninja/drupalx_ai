<?php

namespace Drupal\drupalx_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupalx_ai\Service\MockLandingPageService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

class CreateMockLandingForm extends FormBase
{

  /**
   * The mock landing page service.
   *
   * @var \Drupal\drupalx_ai\Service\MockLandingPageService
   */
  protected $mockLandingPageService;

  /**
   * Constructs a new CreateMockLandingForm.
   *
   * @param \Drupal\drupalx_ai\Service\MockLandingPageService $mock_landing_page_service
   *   The mock landing page service.
   */
  public function __construct(MockLandingPageService $mock_landing_page_service)
  {
    $this->mockLandingPageService = $mock_landing_page_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('drupalx_ai.mock_landing_page_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'drupalx_ai_create_mock_landing_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>Click the button below to create a new mock landing page.</p>'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Mock Landing'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $edit_url = $this->mockLandingPageService->createLandingNodeWithMockContent();
    $this->messenger()->addStatus($this->t('Mock landing page created successfully.'));
    $form_state->setRedirectUrl(Url::fromUri($edit_url));
  }
}
