<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\AliasManagerInterface;

/**
 * Builds the publish-state payload for the editor pre-publish flow.
 */
final class PublishInfoBuilder {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Builds the publish payload for the editor.
   *
   * Task 3 fills in the instance methods; this task ships the pure helpers.
   */
  public function build(ContentEntityInterface $entity, array $catalog_fields): array {
    return [];
  }

  /**
   * Parses "bundle: field_name" override lines; "bundle: none" disables.
   *
   * @return array<string, string|false>
   */
  public static function parseOverrides(string $raw): array {
    $overrides = [];
    foreach (preg_split('/\R/', $raw) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $parts = explode(':', $line, 2);
      if (count($parts) !== 2) {
        continue;
      }
      $bundle = trim($parts[0]);
      $field = trim($parts[1]);
      if ($bundle === '') {
        continue;
      }
      $overrides[$bundle] = ($field === '' || strcasecmp($field, 'none') === 0) ? FALSE : $field;
    }
    return $overrides;
  }

  /**
   * Chooses the featured-media field for a bundle.
   *
   * Override wins; otherwise the first media-target entity reference in the
   * catalog, then the first image field.
   */
  public static function detectFeaturedField(array $catalog_fields, array $overrides, string $bundle): ?string {
    if (array_key_exists($bundle, $overrides)) {
      $override = $overrides[$bundle];
      return is_string($override) && $override !== '' ? $override : NULL;
    }
    foreach ($catalog_fields as $field) {
      if (($field['kind'] ?? NULL) === 'entity_reference' && ($field['targetType'] ?? NULL) === 'media') {
        return $field['name'];
      }
    }
    foreach ($catalog_fields as $field) {
      if (($field['type'] ?? NULL) === 'image') {
        return $field['name'];
      }
    }
    return NULL;
  }

}
