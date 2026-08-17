<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldDefinitionInterface;

/**
 * Normalizes Drupal field values into a stable JSON shape for the editor.
 */
final class FieldValueSerializer {

  private const SCALAR_TYPES = [
    'string',
    'text',
    'text_long',
    'text_with_summary',
  ];

  private const NUMBER_TYPES = [
    'integer',
    'decimal',
    'float',
  ];

  private const LIST_TYPES = [
    'list_string',
    'list_integer',
    'list_float',
  ];

  public function serialize(ContentEntityInterface $entity, FieldDefinitionInterface $definition): mixed {
    $name = $definition->getName();
    $type = $definition->getType();

    try {
      $list = $entity->get($name);
      $values = $list->getValue();
    }
    catch (\Throwable) {
      $values = [];
    }

    if (in_array($type, self::SCALAR_TYPES, TRUE)) {
      return $values[0]['value'] ?? '';
    }

    if (in_array($type, self::NUMBER_TYPES, TRUE)) {
      if (!array_key_exists(0, $values) || $values[0]['value'] === NULL || $values[0]['value'] === '') {
        return NULL;
      }
      return $type === 'integer' ? (int) $values[0]['value'] : (float) $values[0]['value'];
    }

    if ($type === 'boolean') {
      return array_key_exists(0, $values) && $values[0]['value'] !== '' && $values[0]['value'] !== NULL
        ? (bool) $values[0]['value']
        : NULL;
    }

    if (in_array($type, self::LIST_TYPES, TRUE)) {
      return array_values(array_column($values, 'value'));
    }

    if ($type === 'datetime') {
      return $values[0]['value'] ?? NULL;
    }

    if ($type === 'timestamp') {
      return isset($values[0]['value']) ? (int) $values[0]['value'] : NULL;
    }

    if ($type === 'entity_reference') {
      $referenced = isset($list) ? $list->referencedEntities() : [];
      $byId = [];
      foreach ($referenced as $ref) {
        $byId[(int) $ref->id()] = (string) $ref->label();
      }

      $items = [];
      foreach ($values as $item) {
        $id = (int) ($item['target_id'] ?? 0);
        if ($id === 0) {
          continue;
        }
        $items[] = [
          'id' => $id,
          'label' => $byId[$id] ?? '#' . $id,
        ];
      }

      return $items;
    }

    return $this->complexSummary($values);
  }

  private function complexSummary(array $values): array {
    $detail = [];
    foreach ($values as $item) {
      $parts = array_filter((array) $item, static fn ($v): bool => $v !== NULL && $v !== '');
      if ($parts) {
        $detail[] = implode(', ', array_map('strval', $parts));
      }
    }

    return [
      'summary' => sprintf('%d value%s', count($values), count($values) === 1 ? '' : 's'),
      'detail' => $detail,
    ];
  }

}
