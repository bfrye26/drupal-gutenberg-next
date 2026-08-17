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

  /**
   * Field names that are implementation details rather than editorial fields.
   */
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

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
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

      $fields[] = [
        'name' => $name,
        'label' => (string) $definition->getLabel(),
        'type' => $definition->getType(),
        'required' => $definition->isRequired(),
        'computed' => $definition->isComputed(),
        'readOnly' => $definition->isReadOnly(),
      ];
    }

    usort($fields, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $fields;
  }

}
