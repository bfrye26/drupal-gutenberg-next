<?php

declare(strict_types=1);

/**
 * Self-check for the FieldCatalog filtering/sorting logic.
 *
 * Runs without a Drupal installation by stubbing the Drupal interfaces the
 * catalog consumes. Usage:
 *
 *   php tests/check-field-catalog.php
 *
 * Exits non-zero on the first failure.
 */

namespace Drupal\Core\Entity {
  interface EntityFieldManagerInterface {
    public function getFieldDefinitions($entityTypeId, $bundle);
  }

  interface EntityDisplayRepositoryInterface {
    public function getFormDisplay($entityTypeId, $bundle);
  }

  interface ContentEntityInterface {
    public function getEntityTypeId();
    public function bundle();
  }

  interface FieldStorageDefinitionInterface {
    public function getCardinality();
    public function getSetting($name);
  }

  final class FakeFieldItemListValue {
    public function __construct(private readonly array $values) {}
    public function getValue(): array {
      return $this->values;
    }
    public function referencedEntities(): array {
      return [];
    }
  }
}

namespace Drupal\Core\Field {
  interface FieldDefinitionInterface {
    public function getName();
    public function getType();
    public function getSettings();
    public function getFieldStorageDefinition();
  }
}

namespace {
  require __DIR__ . '/../src/Bridge/FieldCatalog.php';
  require __DIR__ . '/../src/Bridge/FieldValueSerializer.php';

  use Drupal\Core\Entity\ContentEntityInterface;
  use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
  use Drupal\Core\Entity\EntityFieldManagerInterface;
  use Drupal\gutenberg_next\Bridge\FieldCatalog;
  use Drupal\gutenberg_next\Bridge\FieldValueSerializer;

  final class FakeFieldDefinition implements \Drupal\Core\Field\FieldDefinitionInterface {
    public function __construct(
      private readonly string $label,
      private readonly string $type,
      private readonly bool $required = FALSE,
      private readonly bool $computed = FALSE,
      private readonly bool $readOnly = FALSE,
      private readonly array $settings = [],
      private readonly ?\Drupal\Core\Entity\FieldStorageDefinitionInterface $storage = NULL,
    ) {}

    public function getLabel(): string {
      return $this->label;
    }

    // ponytail: name-agnostic fake — FakeEntity::get() ignores the field name.
    public function getName(): string {
      return '';
    }

    public function getType(): string {
      return $this->type;
    }

    public function isRequired(): bool {
      return $this->required;
    }

    public function isComputed(): bool {
      return $this->computed;
    }

    public function isReadOnly(): bool {
      return $this->readOnly;
    }

    public function getSettings(): array {
      return $this->settings;
    }

    public function getFieldStorageDefinition(): ?\Drupal\Core\Entity\FieldStorageDefinitionInterface {
      return $this->storage;
    }
  }

  final class FakeFieldStorage implements \Drupal\Core\Entity\FieldStorageDefinitionInterface {
    public function __construct(
      private readonly int $cardinality,
      private readonly array $settings = [],
    ) {}

    public function getCardinality(): int {
      return $this->cardinality;
    }

    public function getSetting($name) {
      return $this->settings[$name] ?? NULL;
    }
  }

  final class FakeFieldManager implements EntityFieldManagerInterface {
    public function __construct(private readonly array $definitions) {}

    public function getFieldDefinitions($entityTypeId, $bundle): array {
      return $this->definitions;
    }
  }

  final class FakeFormDisplay {
    public function __construct(private readonly array $components) {}

    public function getComponents(): array {
      return $this->components;
    }
  }

  final class FakeDisplayRepository implements EntityDisplayRepositoryInterface {
    public function __construct(private readonly FakeFormDisplay $display) {}

    public function getFormDisplay($entityTypeId, $bundle): FakeFormDisplay {
      return $this->display;
    }
  }

  final class FakeEntity implements ContentEntityInterface {
    public function getEntityTypeId(): string {
      return 'node';
    }

    public function bundle(): string {
      return 'article';
    }

    public function get($fieldName) {
      return new \Drupal\Core\Entity\FakeFieldItemListValue([[]]);
    }
  }

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $catalog = new FieldCatalog(
    new FakeFieldManager([
      'uuid' => new FakeFieldDefinition('UUID', 'uuid'),
      'vid' => new FakeFieldDefinition('Revision ID', 'integer'),
      'langcode' => new FakeFieldDefinition('Language', 'language'),
      'nid' => new FakeFieldDefinition('ID', 'integer'),
      'created' => new FakeFieldDefinition('Authored on', 'created'),
      'body' => new FakeFieldDefinition('Body', 'text_with_summary'),
      'field_tags' => new FakeFieldDefinition('Tags', 'entity_reference', required: TRUE, settings: ['handler' => 'default:node'], storage: new FakeFieldStorage(-1, ['target_type' => 'node'])),
      'field_summary' => new FakeFieldDefinition('Summary', 'string', computed: TRUE, settings: ['max_length' => 255], storage: new FakeFieldStorage(1)),
      'field_topic' => new FakeFieldDefinition('Topic', 'list_string', settings: ['allowed_values' => ['news' => 'News', 'guide' => 'Guide']], storage: new FakeFieldStorage(1)),
      'field_when' => new FakeFieldDefinition('When', 'datetime', settings: ['datetime_type' => 'datetime'], storage: new FakeFieldStorage(1)),
      'field_hidden' => new FakeFieldDefinition('Hidden helper', 'string'),
    ]),
    new FakeDisplayRepository(new FakeFormDisplay([
      'body' => [],
      'field_tags' => [],
      'field_summary' => [],
      'field_topic' => [],
      'field_when' => [],
    ])),
    new FieldValueSerializer(),
  );

  $fields = $catalog->forEntity(new FakeEntity());
  $names = array_column($fields, 'name');

  $check('internal fields are excluded', !in_array('uuid', $names, TRUE) && !in_array('vid', $names, TRUE) && !in_array('langcode', $names, TRUE));
  $check('fields missing from the form display are excluded', !in_array('nid', $names, TRUE) && !in_array('created', $names, TRUE) && !in_array('field_hidden', $names, TRUE));
  $check('body is excluded', !in_array('body', $names, TRUE));
  $check('only form-visible editorial fields remain', $names === ['field_summary', 'field_tags', 'field_topic', 'field_when']);
  $check('fields are sorted by label', array_column($fields, 'label') === ['Summary', 'Tags', 'Topic', 'When']);
  $tags = $fields[array_search('field_tags', $names, TRUE)];
  $summary = $fields[array_search('field_summary', $names, TRUE)];
  $check('required flag is exposed', $tags['required'] === TRUE && $summary['required'] === FALSE);
  $check('computed/readOnly flags are exposed', $summary['computed'] === TRUE && $summary['readOnly'] === FALSE);

  $byName = array_combine($names, $fields);
  $check('kind mapping (entity_reference)', $byName['field_tags']['kind'] === 'entity_reference');
  $check('kind mapping (list)', $byName['field_topic']['kind'] === 'list');
  $check('kind mapping (datetime)', $byName['field_when']['kind'] === 'datetime');
  $check('cardinality and multiple', $byName['field_tags']['cardinality'] === -1 && $byName['field_tags']['multiple'] === TRUE && $byName['field_summary']['multiple'] === FALSE);
  $check('list options', $byName['field_topic']['options'] === ['news' => 'News', 'guide' => 'Guide']);
  $check('max length', $byName['field_summary']['maxLength'] === 255);
  $check('entity reference target type', $byName['field_tags']['targetType'] === 'node');
  $check('datetime storage format', $byName['field_when']['datetimeStorageFormat'] === 'Y-m-d\TH:i:s');
  $check('serialized values present', $byName['field_tags']['value'] === [] && $byName['field_topic']['value'] === []);

  exit($failures === 0 ? 0 : 1);
}
