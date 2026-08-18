<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Gutenberg Next.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TypedConfigManagerInterface $typed_config_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('config.typed'),
    );
  }

  public function getFormId(): string {
    return 'gutenberg_next_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['gutenberg_next.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('gutenberg_next.settings');
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $type_options = [];
    foreach ($types as $type) {
      $type_options[$type->id()] = $type->label();
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Gutenberg Next'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('The integration only activates after a Gutenberg editor is detected on the entity form.'),
    ];

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#options' => $type_options,
      '#default_value' => (array) $config->get('content_types'),
      '#description' => $this->t('Leave every option unchecked to allow Gutenberg Next on any Gutenberg-enabled node type.'),
    ];

    $form['layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Editor layout'),
      '#open' => TRUE,
    ];
    $form['layout']['content_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Content width'),
      '#default_value' => $config->get('content_width'),
      '#min' => 480,
      '#max' => 1200,
      '#field_suffix' => 'px',
      '#required' => TRUE,
    ];
    $form['layout']['wide_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Wide content width'),
      '#default_value' => $config->get('wide_width'),
      '#min' => 640,
      '#max' => 1800,
      '#field_suffix' => 'px',
      '#required' => TRUE,
    ];
    $form['layout']['sticky_header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep the Gutenberg header visible'),
      '#default_value' => $config->get('sticky_header'),
    ];
    $form['layout']['inject_canvas_styles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Inject compatibility styles into the Gutenberg canvas'),
      '#default_value' => $config->get('inject_canvas_styles'),
      '#description' => $this->t('Constrains normal and wide blocks to the configured widths without limiting align-full blocks.'),
    ];

    $form['integration'] = [
      '#type' => 'details',
      '#title' => $this->t('Drupal integration'),
      '#open' => TRUE,
    ];
    $form['integration']['show_drupal_badge'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show integration status in the editor header'),
      '#default_value' => $config->get('show_drupal_badge'),
    ];
    $form['integration']['show_field_panel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose Drupal fields in the document settings sidebar'),
      '#default_value' => $config->get('show_field_panel'),
      '#description' => $this->t('Adds a Gutenberg-native panel that can jump directly to Drupal fields on the entity form.'),
    ];
    $form['integration']['field_bindings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose Drupal fields as block binding sources'),
      '#default_value' => $config->get('field_bindings'),
      '#description' => $this->t('Lets editors bind heading, paragraph, button and image blocks directly to Drupal field values.'),
    ];
    $form['integration']['autosave_fields'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autosave Drupal field changes'),
      '#default_value' => $config->get('autosave_fields'),
      '#description' => $this->t('Keeps a per-user snapshot of unsaved field edits for saved nodes and restores them after an accidental reload.'),
    ];
    $form['integration']['featured_media_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Featured media overrides'),
      '#default_value' => $config->get('featured_media_overrides'),
      '#description' => $this->t('One "content_type: field_name" per line; "content_type: none" disables featured media for that type. Leave empty to auto-detect the first media or image field.'),
    ];
    $form['integration']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Compatibility diagnostics'),
      '#default_value' => $config->get('debug'),
      '#description' => $this->t('Logs detected Gutenberg packages and bridge capabilities to the browser console.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ([['content_width', 480, 1200], ['wide_width', 640, 1800]] as [$name, $min, $max]) {
      $value = (int) $form_state->getValue($name);
      if ($value < $min || $value > $max) {
        $form_state->setErrorByName($name, $this->t('The value must be between @min and @max.', ['@min' => $min, '@max' => $max]));
      }
    }
    if ((int) $form_state->getValue('wide_width') < (int) $form_state->getValue('content_width')) {
      $form_state->setErrorByName('wide_width', $this->t('Wide content width must be at least as large as the normal content width.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $content_types = array_values(array_filter((array) $form_state->getValue('content_types')));

    $this->config('gutenberg_next.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('content_types', $content_types)
      ->set('content_width', (int) $form_state->getValue('content_width'))
      ->set('wide_width', (int) $form_state->getValue('wide_width'))
      ->set('sticky_header', (bool) $form_state->getValue('sticky_header'))
      ->set('show_drupal_badge', (bool) $form_state->getValue('show_drupal_badge'))
      ->set('show_field_panel', (bool) $form_state->getValue('show_field_panel'))
      ->set('inject_canvas_styles', (bool) $form_state->getValue('inject_canvas_styles'))
      ->set('debug', (bool) $form_state->getValue('debug'))
      ->set('field_bindings', (bool) $form_state->getValue('field_bindings'))
      ->set('autosave_fields', (bool) $form_state->getValue('autosave_fields'))
      ->set('featured_media_overrides', (string) $form_state->getValue('featured_media_overrides'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
