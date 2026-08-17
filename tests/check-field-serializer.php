<?php

declare(strict_types=1);

/**
 * Self-check for the FieldValueSerializer normalization logic.
 *
 * Runs without a Drupal installation by stubbing the Drupal interfaces the
 * serializer consumes. Usage:
 *
 *   php tests/check-field-serializer.php
 *
 * Exits non-zero on the first failure.
 */

namespace Drupal\Core\Entity {
  interface ContentEntityInterface {
    public function get($fieldName);
  }

  interface FieldItemListInterface {
    public function getValue(): array;
    public function referencedEntities(): array;
  }

  interface EntityInterface {
    public function id();
    public function label();
  }
}

namespace Drupal\Core\Field {
  interface FieldDefinitionInterface {
    public function getName();
    public function getType();
  }
}

namespace {
  require __DIR__ . '/../src/Bridge/FieldValueSerializer.php';

  use Drupal\Core\Entity\ContentEntityInterface;
  use Drupal\Core\Entity\EntityInterface;
  use Drupal\Core\Field\FieldDefinitionInterface;
  use Drupal\Core\Entity\FieldItemListInterface;
  use Drupal\gutenberg_next\Bridge\FieldValueSerializer;

  final class FakeFieldDefinition implements FieldDefinitionInterface {
    public function __construct(private readonly string $name, private readonly string $type) {}

    public function getName(): string {
      return $this->name;
    }

    public function getType(): string {
      return $this->type;
    }
  }

  final class FakeFieldItemList implements FieldItemListInterface {
    public function __construct(
      private readonly array $values,
      private readonly array $referenced = [],
    ) {}

    public function getValue(): array {
      return $this->values;
    }

    public function referencedEntities(): array {
      return $this->referenced;
    }
  }

  final class FakeEntity implements ContentEntityInterface {
    public function __construct(private readonly array $fields) {}

    public function get($fieldName): FakeFieldItemList {
      return $this->fields[$fieldName];
    }
  }

  final class FakeRef implements EntityInterface {
    public function __construct(private readonly int $id, private readonly string $label) {}

    public function id(): int {
      return $this->id;
    }

    public function label(): string {
      return $this->label;
    }
  }

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $serializer = new FieldValueSerializer();
  $entity = new FakeEntity([
    'field_text' => new FakeFieldItemList([['value' => 'hello']]),
    'field_empty' => new FakeFieldItemList([]),
    'field_count' => new FakeFieldItemList([['value' => '7']]),
    'field_price' => new FakeFieldItemList([['value' => '12.50']]),
    'field_active' => new FakeFieldItemList([['value' => '1']]),
    'field_topic' => new FakeFieldItemList([['value' => 'news'], ['value' => 'guide']]),
    'field_when' => new FakeFieldItemList([['value' => '2026-08-17T10:30:00']]),
    'field_stamp' => new FakeFieldItemList([['value' => 1784542200]]),
    'field_related' => new FakeFieldItemList([['target_id' => 42]], [new FakeRef(42, 'About us')]),
    'field_missing_ref' => new FakeFieldItemList([['target_id' => 99]], []),
    'field_photo' => new FakeFieldItemList([['target_id' => 7, 'alt' => 'x']]),
  ]);

  $v = fn (string $name, string $type) => $serializer->serialize($entity, new FakeFieldDefinition($name, $type));

  $check('string value', $v('field_text', 'string') === 'hello');
  $check('empty string value', $v('field_empty', 'string') === '');
  $check('integer value', $v('field_count', 'integer') === 7);
  $check('decimal value', $v('field_price', 'decimal') === 12.5);
  $check('boolean value', $v('field_active', 'boolean') === TRUE);
  $check('list values', $v('field_topic', 'list_string') === ['news', 'guide']);
  $check('datetime storage string', $v('field_when', 'datetime') === '2026-08-17T10:30:00');
  $check('timestamp int', $v('field_stamp', 'timestamp') === 1784542200);
  $check('entity reference with labels', $v('field_related', 'entity_reference') === [['id' => 42, 'label' => 'About us']]);
  $check('entity reference missing entity fallback', $v('field_missing_ref', 'entity_reference') === [['id' => 99, 'label' => '#99']]);
  $unknown = $v('field_photo', 'image');
  $check('complex fallback summary shape', is_array($unknown) && isset($unknown['summary']) && is_string($unknown['summary']));
  $check('complex fallback detail shape', is_array($unknown['detail']));

  exit($failures === 0 ? 0 : 1);
}
