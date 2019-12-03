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
      '#title' => $this->t('Target URL'),
      '#description' => t('Copy-paste the full URL of the page for the content that you want to copy.'),
      '#required' => TRUE,
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
    $url = Url::fromRoute('copy_content.import', [
      'path' => $url_target,
    ]);
    $form_state->setRedirectUrl($url);
  }

}
