<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Builds a safe field catalog for the Gutenberg editor bridge.
 */
final class FieldCatalog {

  private const INTERNAL_FIELDS = [
    'uuid',
    'vid',
    'revision_id',
    'revision_default',
    'revision_translation_affected',
    'langcode',
    'default_langcode',
    'content_translation_source',
    'content_translation_outdated',
    'content_translation_uid',
    'content_translation_status',
    'content_translation_created',
  ];

  private const KIND_MAP = [
    'string' => 'text',
    'text' => 'text',
    'text_long' => 'text',
    'text_with_summary' => 'text',
    'integer' => 'number',
    'decimal' => 'number',
    'float' => 'number',
    'boolean' => 'boolean',
    'list_string' => 'list',
    'list_integer' => 'list',
    'list_float' => 'list',
    'datetime' => 'datetime',
    'timestamp' => 'datetime',
    'entity_reference' => 'entity_reference',
  ];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly FieldValueSerializer $serializer,
  ) {}

  /**
   * Returns editor-relevant field metadata for a content entity.
   *
   * Only fields that are actually rendered on the entity's edit form are
   * included, so every advertised field can be jumped to from the panel.
   *
   * @return array<int, array<string, mixed>>
   *   A serializable list of field metadata.
   */
  public function forEntity(ContentEntityInterface $entity): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    );
    $form_components = $this->entityDisplayRepository
      ->getFormDisplay($entity->getEntityTypeId(), $entity->bundle())
      ->getComponents();

    $fields = [];
    foreach ($definitions as $name => $definition) {
      if (in_array($name, self::INTERNAL_FIELDS, TRUE)) {
        continue;
      }

      // Keep the body in Gutenberg itself instead of advertising it as a
      // separate Drupal field in the bridge panel.
      if ($name === 'body') {
        continue;
      }

      if (!isset($form_components[$name])) {
        continue;
      }

      $fields[] = $this->buildEntry($entity, $definition, $name);
    }

    usort($fields, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $fields;
  }

  private function buildEntry(ContentEntityInterface $entity, object $definition, string $name): array {
    $type = (string) $definition->getType();
    $kind = self::KIND_MAP[$type] ?? 'complex';
    $storage = method_exists($definition, 'getFieldStorageDefinition')
      ? $definition->getFieldStorageDefinition()
      : NULL;
    $cardinality = $storage ? (int) $storage->getCardinality() : 1;
    $settings = method_exists($definition, 'getSettings') ? (array) $definition->getSettings() : [];

    $entry = [
      'name' => $name,
      'label' => (string) $definition->getLabel(),
      'type' => $type,
      'required' => (bool) $definition->isRequired(),
      'computed' => (bool) $definition->isComputed(),
      'readOnly' => (bool) $definition->isReadOnly(),
      'kind' => $kind,
      'cardinality' => $cardinality,
      'multiple' => $cardinality !== 1,
      'value' => $this->serializer->serialize($entity, $definition),
    ];

    if ($kind === 'text' && isset($settings['max_length'])) {
      $entry['maxLength'] = (int) $settings['max_length'];
    }
    if ($kind === 'number') {
      if (isset($settings['min'])) {
        $entry['numberMin'] = (float) $settings['min'];
      }
      if (isset($settings['max'])) {
        $entry['numberMax'] = (float) $settings['max'];
      }
    }
    if ($kind === 'list' && isset($settings['allowed_values'])) {
      $entry['options'] = $settings['allowed_values'];
    }
    if ($kind === 'datetime') {
      $entry['datetimeStorageFormat'] = ($settings['datetime_type'] ?? 'datetime') === 'datetime'
        ? 'Y-m-d\TH:i:s'
        : 'Y-m-d';
    }
    if ($kind === 'entity_reference' && $storage) {
      $target = $storage->getSetting('target_type');
      if ($target) {
        $entry['targetType'] = (string) $target;
      }
    }

    return $entry;
  }

}
