<?php

namespace Drupal\copy_content\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a form that configures forms module settings.
 */
class CopyContentForm extends FormBase {

  private static $post_response;
  private static $target_url;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'copy_content_copy_content';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'copy_content.copy_content',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['url'] = [
      '#type' => 'url',
      '#title' => t('Target URL'),
      '#description' => t('Copy-paste the full URL of the page for the content that you want to copy.'),
      '#required' => TRUE,
    ];
    $form['with_paragraphs'] = [
      '#type' => 'checkbox',
      '#title' => t('Paragraphs?'),
      '#attributes' => [
        'title' => t('Do you want paragraphs references to be included? (if there are available)'),
        'checked' => 'checked'
      ],
      '#prefix' => '<br /><h5>Including:</h5>',
    ];
    $form['with_images'] = [
      '#type' => 'checkbox',
      '#title' => t('Images?'),
      '#attributes' => [
        'title' => t('Do you want images to be included? (if there are available)'),
        'checked' => 'checked'
      ],
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'clone' => [
        '#type' => 'submit',
        '#value' => t('Copy'),
        '#button_type' => 'primary',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url_target = $form_state->getValue('url');
    $with_paragraphs = $form_state->getValue('with_paragraphs');
    $with_images = $form_state->getValue('with_images');
    $with_tags = $form_state->getValue('with_tags');
    $url = Url::fromRoute('copy_content.import', [
      'path' => $url_target,
      'with_paragraphs' => $with_paragraphs,
      'with_images' => $with_images,
    ]);
    $form_state->setRedirectUrl($url);
  }

}
