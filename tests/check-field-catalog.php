<?php

declare(strict_types=1);

/**
 * Self-check for the FieldCatalog filtering/sorting logic.
 *
 * Runs without a Drupal installation by stubbing the three Drupal interfaces
 * the catalog consumes. Usage:
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
}

namespace {
  require __DIR__ . '/../src/Bridge/FieldCatalog.php';

  use Drupal\Core\Entity\ContentEntityInterface;
  use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
  use Drupal\Core\Entity\EntityFieldManagerInterface;
  use Drupal\gutenberg_next\Bridge\FieldCatalog;

  final class FakeFieldDefinition {
    public function __construct(
      private readonly string $label,
      private readonly string $type,
      private readonly bool $required = FALSE,
      private readonly bool $computed = FALSE,
      private readonly bool $readOnly = FALSE,
    ) {}

    public function getLabel(): string {
      return $this->label;
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
      'field_tags' => new FakeFieldDefinition('Tags', 'entity_reference', required: TRUE),
      'field_summary' => new FakeFieldDefinition('Summary', 'string', computed: TRUE),
      'field_hidden' => new FakeFieldDefinition('Hidden helper', 'string'),
    ]),
    new FakeDisplayRepository(new FakeFormDisplay([
      'body' => [],
      'field_tags' => [],
      'field_summary' => [],
    ])),
  );

  $fields = $catalog->forEntity(new FakeEntity());
  $names = array_column($fields, 'name');

  $check('internal fields are excluded', !in_array('uuid', $names, TRUE) && !in_array('vid', $names, TRUE) && !in_array('langcode', $names, TRUE));
  $check('fields missing from the form display are excluded', !in_array('nid', $names, TRUE) && !in_array('created', $names, TRUE) && !in_array('field_hidden', $names, TRUE));
  $check('body is excluded', !in_array('body', $names, TRUE));
  $check('only form-visible editorial fields remain', $names === ['field_summary', 'field_tags']);
  $check('fields are sorted by label', array_column($fields, 'label') === ['Summary', 'Tags']);
  $tags = $fields[array_search('field_tags', $names, TRUE)];
  $summary = $fields[array_search('field_summary', $names, TRUE)];
  $check('required flag is exposed', $tags['required'] === TRUE && $summary['required'] === FALSE);
  $check('computed/readOnly flags are exposed', $summary['computed'] === TRUE && $summary['readOnly'] === FALSE);

  exit($failures === 0 ? 0 : 1);
}
