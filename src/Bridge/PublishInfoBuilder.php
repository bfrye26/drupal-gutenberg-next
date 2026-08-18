<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\path_alias\AliasManagerInterface;

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
   * @param array<int, array<string, mixed>> $catalog_fields
   *   The 0.2 field catalog entries for this entity.
   *
   * @return array<string, mixed>
   *   The serializable publish payload.
   */
  public function build(ContentEntityInterface $entity, array $catalog_fields): array {
    return [
      'status' => [
        'published' => method_exists($entity, 'isPublished') ? (bool) $entity->isPublished() : FALSE,
      ],
      'author' => $this->authorInfo($entity),
      'alias' => $this->aliasInfo($entity),
      'moderation' => $this->moderationInfo($entity),
      'scheduler' => $this->schedulerInfo($entity),
      'featuredMedia' => $this->featuredMediaInfo($entity, $catalog_fields),
    ];
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

  private function authorInfo(ContentEntityInterface $entity): array {
    if (method_exists($entity, 'getOwner')) {
      $owner = $entity->getOwner();
      if ($owner) {
        return ['id' => (int) $owner->id(), 'name' => (string) $owner->label()];
      }
    }
    return ['id' => 0, 'name' => ''];
  }

  private function aliasInfo(ContentEntityInterface $entity): ?string {
    $id = $entity->id();
    if ($id === NULL) {
      return NULL;
    }
    $system_path = '/node/' . $id;
    $alias = $this->aliasManager->getAliasByPath($system_path);
    return $alias !== '' && $alias !== $system_path ? $alias : NULL;
  }

  private function moderationInfo(ContentEntityInterface $entity): ?array {
    if (!$this->moduleHandler->moduleExists('content_moderation')) {
      return NULL;
    }
    $moderation_info = \Drupal::service('content_moderation.moderation_information');
    if (!$moderation_info->shouldModerateEntitiesOfBundle($entity->getEntityType(), $entity->bundle())) {
      return NULL;
    }
    $workflow = $this->resolveWorkflow($entity);
    if (!$workflow) {
      return NULL;
    }
    $configuration = $workflow->getTypePlugin()->getConfiguration();
    $states = [];
    foreach ($configuration['states'] ?? [] as $state_id => $state) {
      $states[$state_id] = $state['label'] ?? $state_id;
    }
    $current = '';
    if ($entity->hasField('moderation_state') && !$entity->get('moderation_state')->isEmpty()) {
      $current = (string) $entity->get('moderation_state')->value;
    }
    if ($current === '') {
      $current = (string) ($configuration['default_moderation_state'] ?? '');
    }
    return ['state' => $current, 'states' => $states];
  }

  private function resolveWorkflow(ContentEntityInterface $entity): ?object {
    foreach ($this->entityTypeManager->getStorage('workflow')->loadMultiple() as $workflow) {
      if ($workflow->getTypePlugin()->getPluginId() !== 'content_moderation') {
        continue;
      }
      $bundles = $workflow->getTypePlugin()->getConfiguration()['entity_types'][$entity->getEntityTypeId()] ?? [];
      if (in_array($entity->bundle(), (array) $bundles, TRUE)) {
        return $workflow;
      }
    }
    return NULL;
  }

  private function schedulerInfo(ContentEntityInterface $entity): ?array {
    if (!$this->moduleHandler->moduleExists('scheduler')) {
      return NULL;
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($entity->bundle());
    if (!$type) {
      return NULL;
    }
    $publish_enabled = (bool) $type->getThirdPartySetting('scheduler', 'publish_enable', FALSE);
    $unpublish_enabled = (bool) $type->getThirdPartySetting('scheduler', 'unpublish_enable', FALSE);
    if (!$publish_enabled && !$unpublish_enabled) {
      return NULL;
    }
    return [
      'publishOn' => $this->timestampValue($entity, 'publish_on'),
      'unpublishOn' => $this->timestampValue($entity, 'unpublish_on'),
    ];
  }

  private function timestampValue(ContentEntityInterface $entity, string $field): ?int {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return (int) $entity->get($field)->value;
  }

  private function featuredMediaInfo(ContentEntityInterface $entity, array $catalog_fields): ?array {
    $raw = (string) \Drupal::config('gutenberg_next.settings')->get('featured_media_overrides');
    $field_name = self::detectFeaturedField($catalog_fields, self::parseOverrides($raw), $entity->bundle());
    if ($field_name === NULL) {
      return NULL;
    }
    foreach ($catalog_fields as $candidate) {
      if ($candidate['name'] === $field_name) {
        return [
          'field' => $field_name,
          'kind' => $candidate['kind'] ?? 'complex',
          'label' => $candidate['label'] ?? $field_name,
          'value' => $candidate['value'] ?? [],
        ];
      }
    }
    return NULL;
  }

}
