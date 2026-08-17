<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the runtime compatibility status.
 */
final class StatusController extends ControllerBase {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('extension.list.module'));
  }

  public function build(): array {
    $config = $this->config('gutenberg_next.settings');
    $version = $this->moduleExtensionList->getExtensionInfo('gutenberg_next')['version'] ?? '0.1.0-alpha1';

    $upstream_version = $this->moduleExtensionList->getExtensionInfo('gutenberg')['version'] ?? $this->t('Unknown/dev');
    $state = fn (bool $enabled) => $enabled ? $this->t('Enabled') : $this->t('Disabled');

    $rows = [
      [$this->t('Modern editor shell'), $state((bool) $config->get('enabled')), $this->t('Runtime compatibility layer and responsive editor canvas.')],
      [$this->t('Drupal field bridge'), $state((bool) $config->get('show_field_panel')), $this->t('Entity fields are exposed to the Gutenberg document sidebar.')],
      [$this->t('Canvas style injection'), $state((bool) $config->get('inject_canvas_styles')), $this->t('Constrains normal and wide blocks to the configured widths.')],
      [$this->t('Integration badge'), $state((bool) $config->get('show_drupal_badge')), $this->t('Shows Drupal integration status in the editor header.')],
      [$this->t('Drupal Media'), $this->t('Upstream'), $this->t('Provided by Drupal Gutenberg 4.x.')],
      [$this->t('Patterns / synced patterns'), $this->t('Upstream'), $this->t('Uses the Gutenberg functionality shipped by the installed 4.x build.')],
      [$this->t('Global Styles'), $this->t('Upstream'), $this->t('Provided by the Gutenberg 4.x base-theme/globalStyles work.')],
      [$this->t('Block bindings to Drupal fields'), $this->t('Planned'), $this->t('Next adapter milestone; field catalog is already exposed by this build.')],
      [$this->t('Visual Drupal revisions'), $this->t('Planned'), $this->t('Will connect Gutenberg revision UI to Drupal entity revisions.')],
      [$this->t('Realtime collaboration'), $this->t('Planned'), $this->t('Not required for initial production use.')],
    ];

    return [
      'summary' => [
        '#type' => 'container',
        'intro' => [
          '#markup' => '<p><strong>' . $this->t('Gutenberg Next @version', ['@version' => $version]) . '</strong></p>',
        ],
        'upstream' => [
          '#markup' => '<p>' . $this->t('Detected Drupal Gutenberg version: @version', ['@version' => $upstream_version]) . '</p>',
        ],
      ],
      'matrix' => [
        '#type' => 'table',
        '#header' => [$this->t('Capability'), $this->t('State'), $this->t('Notes')],
        '#rows' => $rows,
        '#empty' => $this->t('No compatibility information is available.'),
      ],
    ];
  }

}
