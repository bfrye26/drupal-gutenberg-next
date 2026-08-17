# Gutenberg Next 0.2 — Drupal Entity Data Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the 0.2 data adapter: a `wp.data` store for Drupal fields with panel editing, block bindings, validation sync, autosave snapshots, and entity-reference autocomplete.

**Architecture:** PHP serializes typed field values + adapter metadata into `drupalSettings.gutenbergNext`; a `gutenberg-next/fields` wp.data store becomes the editor-side source of truth; edits write back into hidden Drupal form widgets so the form submit stays authoritative; a per-user autosave table backs restore; block bindings and the field panel consume the same store.

**Tech Stack:** Drupal 11 (site) / Drupal core ^10.3||^11, PHP >=8.1, Drupal Gutenberg 4.0.x (WordPress 6.9 `@wordpress/*` packages as globals), vanilla JS (no build step), MySQL, Windows PowerShell + curl + drush for verification.

**Spec:** `docs/superpowers/specs/2026-08-17-gutenberg-next-0.2-data-adapter-design.md`

## Global Constraints

- Repo root: `C:\Users\User\Downloads\gutenberg-next-0.1.0-alpha1\gutenberg_next` (git branch `main`, remote `origin` = github.com/bfrye26/drupal-gutenberg-next).
- Demo site: `C:\laragon\www\Drupal-Test-2`, URL `http://drupal-test-2.test:8080/`, PHP CLI for site ops: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, Composer: `C:\laragon\bin\composer\composer.phar`, drush invoked as `& $php vendor\drush\drush\drush.php <args>` from the site root.
- No new composer/npm dependencies in the module; no build step; JS is plain files using `window.wp.*` globals; every JS entry point must feature-detect `wp.*` and exit cleanly (0.1 pattern).
- PHP classes: `final`, `declare(strict_types=1)`, constructor-promoted readonly properties; services registered in `gutenberg_next.services.yml`; no comments unless they explain a deliberate shortcut (prefix `ponytail:`).
- JS files: IIFE `(function (Drupal, drupalSettings, once) { 'use strict'; ... })(Drupal, drupalSettings, once)`, no lint rule exists beyond `node --check`.
- Every task: run `php -l` on touched PHP, `node --check` on touched JS, then commit with a `feat:`/`fix:`/`docs:` message. Kernel-level PHP tests run on the demo site only (CI stays syntax + standalone checks).
- Autosave applies only to saved entities (`entity_id` present); new nodes rely on Drupal's own form-value preservation.
- Entity type restriction: the autosave route accepts `entity_type: node` only (module only integrates node forms today).

---

### Task 1: Amend spec (serializer check swap + autosave scope note)

**Files:**
- Modify: `docs/superpowers/specs/2026-08-17-gutenberg-next-0.2-data-adapter-design.md`

**Interfaces:**
- Produces: nothing (docs only).

- [ ] **Step 1: Edit section 11.4** — replace the kernel-test bullet with a standalone check bullet.

In section 11 "Testing & verification", replace:

```markdown
   - Kernel test `tests/src/Kernel/FieldValueSerializerTest.php` (run via the
     site's phpunit after `composer require --dev drupal/core-dev`): per-type
     normalization + complex fallback + unknown-type fallback.
```

with:

```markdown
   - Standalone check `tests/check-field-serializer.php` (dependency-free,
     stub-based, like the existing field-catalog check; also runnable in CI):
     per-type normalization + complex fallback + unknown-type fallback.
```

- [ ] **Step 2: Edit section 3.3** — add the scope note. After the paragraph ending "No entity writes." add:

```markdown
  Autosave covers saved entities only (`entity_id` set). For new nodes the
  store skips server autosave; Drupal already re-renders widget values on
  validation reloads.
```

- [ ] **Step 3: Commit**

```powershell
git add docs/superpowers/specs/2026-08-17-gutenberg-next-0.2-data-adapter-design.md
git commit -m "docs: adjust 0.2 spec — standalone serializer check, autosave scope"
```

---

### Task 2: Demo site setup (environment, no repo commits)

**Files:** none in the repo. Site dir: `C:\laragon\www\Drupal-Test-2`.

**Interfaces:**
- Produces: a working Drupal 11 + Gutenberg 4.0.x + gutenberg_next dev site with a `gnt_article` content type carrying the full field matrix; `use gutenberg` permission granted; admin session cookie jar `C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar` for curl checks.

- [ ] **Step 1: Composer setup**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
$composer = "C:\laragon\bin\composer\composer.phar"
& $php $composer config minimum-stability dev
& $php $composer config prefer-stable true
& $php $composer require drush/drush drupal/gutenberg:4.0.x-dev@dev
```

Run from `C:\laragon\www\Drupal-Test-2`. Expected: composer installs without conflicts (site has no contrib yet).

- [ ] **Step 2: Link the module**

```powershell
New-Item -ItemType Directory -Force web\modules\custom | Out-Null
New-Item -ItemType Junction -Path web\modules\custom\gutenberg_next -Target "C:\Users\User\Downloads\gutenberg-next-0.1.0-alpha1\gutenberg_next" | Out-Null
& $php vendor\drush\drush\drush.php pm:list --filter=gutenberg_next --format=json
```

Expected: the module is discovered (junction = live edits to the repo are visible to the site).

- [ ] **Step 3: Enable modules + permissions**

```powershell
& $php vendor\drush\drush\drush.php en gutenberg gutenberg_next -y
& $php vendor\drush\drush\drush.php role:perm:add authenticated "use gutenberg"
& $php vendor\drush\drush\drush.php role:perm:add authenticated "use text format gutenberg"
& $php vendor\drush\drush\drush.php cr
```

Expected: both modules enabled; if `use text format gutenberg` doesn't exist, use `drush role:perm:list authenticated` and add whatever `use text format *` permission covers the gutenberg format.

- [ ] **Step 4: Create the GNT Article content type + field matrix**

```powershell
& $php vendor\drush\drush\drush.php php:eval "`$etm = \Drupal::entityTypeManager(); `$type = `$etm->getStorage('node_type')->create(['type' => 'gnt_article', 'name' => 'GNT Article']); `$type->save(); `$sm = \Drupal::service('field_storage_definition'); "
```

then continue with `drush php:eval` snippets (one per field; copy verbatim, one line each):

```php
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'type' => 'string']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_subtitle', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Subtitle', 'required' => TRUE])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_notes', 'entity_type' => 'node', 'type' => 'text_long']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_notes', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Notes'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_count', 'entity_type' => 'node', 'type' => 'integer']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_count', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Count'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'node', 'type' => 'decimal']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_price', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Price'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_active', 'entity_type' => 'node', 'type' => 'boolean']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_active', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Active'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_topic', 'entity_type' => 'node', 'type' => 'list_string', 'settings' => ['allowed_values' => ['news' => 'News', 'guide' => 'Guide', 'review' => 'Review']]]); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_topic', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Topic'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_when', 'entity_type' => 'node', 'type' => 'datetime']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_when', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'When'])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']]); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Related articles', 'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['gnt_article']]]])->save();
$f = \Drupal\field\Entity\FieldStorageConfig::create(['field_name' => 'field_photo', 'entity_type' => 'node', 'type' => 'image']); $f->save(); \Drupal\field\Entity\FieldConfig::create(['field_name' => 'field_photo', 'entity_type' => 'node', 'bundle' => 'gnt_article', 'label' => 'Photo'])->save();
```

Then make `field_related` multi-value and place widgets in the form display:

```php
$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_related'); $fs->setCardinality(-1); $fs->save();
$form_display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.gnt_article.default');
$form_display->setComponent('body', ['type' => 'text_textarea_with_summary', 'region' => 'content']);
$form_display->setComponent('field_subtitle', ['type' => 'string_textfield', 'region' => 'content']);
$form_display->setComponent('field_notes', ['type' => 'text_textarea', 'region' => 'content']);
$form_display->setComponent('field_count', ['type' => 'number', 'region' => 'content']);
$form_display->setComponent('field_price', ['type' => 'number', 'region' => 'content']);
$form_display->setComponent('field_active', ['type' => 'boolean_checkbox', 'region' => 'content']);
$form_display->setComponent('field_topic', ['type' => 'options_select', 'region' => 'content']);
$form_display->setComponent('field_when', ['type' => 'datetime_default', 'region' => 'content']);
$form_display->setComponent('field_related', ['type' => 'entity_reference_autocomplete', 'region' => 'content']);
$form_display->setComponent('field_photo', ['type' => 'image_image', 'region' => 'content']);
$form_display->save();
```

- [ ] **Step 5: Enable Gutenberg on the bundle**

```powershell
& $php vendor\drush\drush\drush.php config:get gutenberg.settings
```

Inspect the printed structure and enable the Gutenberg experience for `gnt_article` the same way the content-type edit form does (the upstream UI checkbox writes into `gutenberg.settings`). If the structure isn't obvious, use `drush uli --uri=http://drupal-test-2.test:8080` and tick "Enable Gutenberg experience" on `/admin/structure/types/manage/gnt_article`. Then set our module's bundle scope:

```powershell
& $php vendor\drush\drush\drush.php php:eval "\Drupal::configFactory()->getEditable('gutenberg_next.settings')->set('content_types', ['gnt_article'])->save();"
& $php vendor\drush\drush\drush.php cr
```

- [ ] **Step 6: Create a node + authenticated curl session**

```powershell
& $php vendor\drush\drush\drush.php php:eval "`$n = \Drupal\node\Entity\Node::create(['type' => 'gnt_article', 'title' => 'Autosave target']); `$n->save();"
$login = & $php vendor\drush\drush\drush.php uli --uri=http://drupal-test-2.test:8080 --no-browser
curl.exe -s -c C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -L -o NUL $login
```

Note: `drush uli` output includes an extra line; capture only the http(s) URL. Then verify the session:

```powershell
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/add/gnt_article
if ($page -match "gutenbergNext" -and $page -match "drupalSettings.gutenberg") { "EDITOR+PAYLOAD OK" } else { "FAILED"; $page | Select-Object -First 1 }
```

Expected: `EDITOR+PAYLOAD OK` (the Gutenberg editor and our drupalSettings both render on the add form). If the editor isn't loading, revisit step 5 (format editor assignment / text format access).

- [ ] **Step 7: No commit** — environment only; note any deviations in the final PR message.

---

### Task 3: `FieldValueSerializer` + standalone check

**Files:**
- Create: `src/Bridge/FieldValueSerializer.php`
- Create: `tests/check-field-serializer.php`
- Modify: `gutenberg_next.services.yml` (register `gutenberg_next.field_value_serializer`)
- Modify: `.github/workflows/ci.yml` (add serializer check step)

**Interfaces:**
- Produces: `Drupal\gutenberg_next\Bridge\FieldValueSerializer::serialize(ContentEntityInterface $entity, FieldDefinitionInterface $definition): mixed` with the JSON shapes from the spec's section 3.1 table. Service id `gutenberg_next.field_value_serializer`. Used by Task 4 (catalog).

- [ ] **Step 1: Write the failing standalone check**

`tests/check-field-serializer.php` (stub pattern identical to `tests/check-field-catalog.php`):

```php
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

  interface FieldDefinitionInterface {
    public function getName();
    public function getType();
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

namespace {
  require __DIR__ . '/../src/Bridge/FieldValueSerializer.php';

  use Drupal\Core\Entity\ContentEntityInterface;
  use Drupal\Core\Entity\EntityInterface;
  use Drupal\Core\Entity\FieldDefinitionInterface;
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
```

- [ ] **Step 2: Run it, expect failure**

```powershell
php tests\check-field-serializer.php
```

Expected: PHP fatal "Failed to open stream ... FieldValueSerializer.php" (class missing) → exit non-zero.

- [ ] **Step 3: Implement the serializer**

`src/Bridge/FieldValueSerializer.php`:

```php
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
```

- [ ] **Step 4: Register the service**

`gutenberg_next.services.yml` — add after `gutenberg_next.field_catalog`:

```yml
  gutenberg_next.field_value_serializer:
    class: Drupal\gutenberg_next\Bridge\FieldValueSerializer
```

- [ ] **Step 5: Run the check, expect pass**

```powershell
php tests\check-field-serializer.php
php -l src\Bridge\FieldValueSerializer.php
```

Expected: all `ok   ...` lines, exit 0.

- [ ] **Step 6: Wire into CI**

`.github/workflows/ci.yml` — after the "Field catalog self-check" step add:

```yaml
      - name: Field serializer self-check
        run: php tests/check-field-serializer.php
```

- [ ] **Step 7: Commit**

```powershell
git add src/Bridge/FieldValueSerializer.php tests/check-field-serializer.php gutenberg_next.services.yml .github/workflows/ci.yml
git commit -m "feat: typed field value serializer with standalone check"
```

---

### Task 4: FieldCatalog adapter metadata

**Files:**
- Modify: `src/Bridge/FieldCatalog.php`
- Modify: `gutenberg_next.services.yml` (add serializer argument)
- Modify: `tests/check-field-catalog.php` (extend fakes + assertions)

**Interfaces:**
- Consumes: `gutenberg_next.field_value_serializer` (Task 3).
- Produces: each `forEntity()` entry is `{name, label, type, required, computed, readOnly, kind, cardinality, multiple, maxLength, numberMin, numberMax, options, datetimeStorageFormat, targetType, value}` where:
  - `kind` ∈ `text|number|boolean|list|datetime|entity_reference|complex` per type map
  - `cardinality` int (`-1` = unlimited), `multiple` = `cardinality !== 1`
  - `options` = allowed_values map (list types only), `maxLength` (string only), `numberMin`/`numberMax` (integer/float only), `datetimeStorageFormat` = `'Y-m-d\TH:i:s'` (datetime) or `'Y-m-d'` (date-only per `datetime_type` setting)
  - `targetType` = storage `target_type` setting (entity_reference only)
  - `value` = serializer output
  - `autocompleteUrl` is NOT produced here (Task 5, needs full bootstrap).

- [ ] **Step 1: Extend the catalog check fakes first**

In `tests/check-field-catalog.php`:

a) Extend the `Drupal\Core\Entity` stub namespace — add to `FieldDefinitionInterface` stub: `getSettings()`, `getFieldStorageDefinition()`. Add new stub interface:

```php
  interface FieldStorageDefinitionInterface {
    public function getCardinality();
    public function getSetting($name);
  }
```

b) Extend `FakeFieldDefinition`:

```php
  final class FakeFieldDefinition {
    public function __construct(
      private readonly string $label,
      private readonly string $type,
      private readonly bool $required = FALSE,
      private readonly bool $computed = FALSE,
      private readonly bool $readOnly = FALSE,
      private readonly array $settings = [],
      private readonly ?\Drupal\Core\Entity\FieldStorageDefinitionInterface $storage = NULL,
    ) {}

    // existing getters unchanged; add:

    public function getSettings(): array {
      return $this->settings;
    }

    public function getFieldStorageDefinition(): ?\Drupal\Core\Entity\FieldStorageDefinitionInterface {
      return $this->storage;
    }
  }
```

c) Add `FakeFieldStorage`:

```php
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
```

d) Extend `FakeEntity` with `get()`:

```php
    public function get($fieldName) {
      return new \Drupal\Core\Entity\FakeFieldItemListValue([[]]);
    }
```

and add the tiny stub list class in the `Drupal\Core\Entity` namespace block:

```php
  final class FakeFieldItemListValue {
    public function __construct(private readonly array $values) {}
    public function getValue(): array {
      return $this->values;
    }
    public function referencedEntities(): array {
      return [];
    }
  }
```

e) Extend the fixture definitions with the new metadata (replace the existing `$catalog = new FieldCatalog(...)` block):

```php
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
```

f) Add assertions after the existing ones:

```php
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
```

Note `array_combine` requires the `$names`/`$fields` computed earlier in the file; keep existing checks.

- [ ] **Step 2: Run the check, expect failure**

```powershell
php tests\check-field-catalog.php
```

Expected: FAIL on every new assertion (keys missing) — the old checks still pass.

- [ ] **Step 3: Implement the catalog extensions**

`src/Bridge/FieldCatalog.php`:

```php
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

      $fields[] = $this->buildEntry($entity, $definition);
    }

    usort($fields, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $fields;
  }

  private function buildEntry(ContentEntityInterface $entity, object $definition): array {
    $type = (string) $definition->getType();
    $kind = self::KIND_MAP[$type] ?? 'complex';
    $storage = method_exists($definition, 'getFieldStorageDefinition')
      ? $definition->getFieldStorageDefinition()
      : NULL;
    $cardinality = $storage ? (int) $storage->getCardinality() : 1;
    $settings = method_exists($definition, 'getSettings') ? (array) $definition->getSettings() : [];

    $entry = [
      'name' => $definition->getName(),
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
```

Note the full `use` list stays as before plus nothing new (`object` type for `$definition` keeps the file stub-compatible and runtime-safe; real definitions are objects).

- [ ] **Step 4: Register the new constructor argument**

`gutenberg_next.services.yml` — change the catalog arguments:

```yml
    arguments: ['@entity_field.manager', '@entity_display.repository', '@gutenberg_next.field_value_serializer']
```

- [ ] **Step 5: Run checks, expect pass**

```powershell
php tests\check-field-catalog.php
php tests\check-field-serializer.php
php -l src\Bridge\FieldCatalog.php
```

- [ ] **Step 6: Commit**

```powershell
git add src/Bridge/FieldCatalog.php tests/check-field-catalog.php gutenberg_next.services.yml
git commit -m "feat: adapter metadata and serialized values in the field catalog"
```

---

### Task 5: drupalSettings payload + config surface

**Files:**
- Modify: `gutenberg_next.module` (payload: values, autosave, bindings; autocomplete URL helper)
- Modify: `src/Form/SettingsForm.php` (two new toggles)
- Modify: `config/schema/gutenberg_next.schema.yml`
- Modify: `config/install/gutenberg_next.settings.yml`

**Interfaces:**
- Consumes: catalog entries from Task 4.
- Produces:
  - `drupalSettings.gutenbergNext.entity.fields[]` entries each additionally carrying `autocompleteUrl` (entity_reference only, absolute-path string like `/entity_reference_autocomplete/node/default:node/<hmac>`) — attached when `show_field_panel` OR `field_bindings` is enabled.
  - `drupalSettings.gutenbergNext.autosave = {enabled: bool, url: string|null, token: string}` where `url` = `/editor/gutenberg-next/autosave/node/{id}` for saved entities and `null` for new ones.
  - `drupalSettings.gutenbergNext.bindings = {enabled: bool}`.
  - Config keys `autosave_fields`, `field_bindings` (booleans, default true) with schema + form toggles.

- [ ] **Step 1: Extend the payload in `gutenberg_next.module`**

Replace the `$form['#attached']['drupalSettings']['gutenbergNext'] = [...]` block with:

```php
  /** @var \Drupal\gutenberg_next\Bridge\FieldCatalog $catalog */
  $catalog = \Drupal::service('gutenberg_next.field_catalog');
  $module_info = \Drupal::service('extension.list.module')->getExtensionInfo('gutenberg_next');

  $show_fields = $config->get('show_field_panel') || $config->get('field_bindings');
  $fields = $show_fields ? $catalog->forEntity($entity) : [];
  foreach ($fields as &$field) {
    if (($field['kind'] ?? NULL) === 'entity_reference') {
      $field['autocompleteUrl'] = gutenberg_next_entity_autocomplete_url($entity, $field['name']);
    }
  }
  unset($field);

  $gutenberg_settings = \Drupal::config('gutenberg.settings');
  $csrf_token = $gutenberg_settings->get('csrf_token');
  // The token is normally exposed via drupalSettings.gutenberg.csrfToken on
  // editor pages; fall back to the session token endpoint value for safety.
  if (!$csrf_token) {
    $csrf_token = \Drupal::service('csrf_token')->get('rest');
  }

  $entity_id = $entity->id();
  $autosave_url = ($config->get('autosave_fields') && $entity_id !== NULL)
    ? sprintf('/editor/gutenberg-next/autosave/node/%d', (int) $entity_id)
    : NULL;

  $form['#attached']['library'][] = 'gutenberg_next/editor';
  $form['#attached']['drupalSettings']['gutenbergNext'] = [
    'version' => $module_info['version'] ?? '0.1.0-alpha1',
    'contentWidth' => max(480, (int) $config->get('content_width')),
    'wideWidth' => max(640, (int) $config->get('wide_width')),
    'stickyHeader' => (bool) $config->get('sticky_header'),
    'showDrupalBadge' => (bool) $config->get('show_drupal_badge'),
    'showFieldPanel' => (bool) $config->get('show_field_panel'),
    'injectCanvasStyles' => (bool) $config->get('inject_canvas_styles'),
    'debug' => (bool) $config->get('debug'),
    'autosave' => [
      'enabled' => (bool) $config->get('autosave_fields'),
      'url' => $autosave_url,
      'token' => $csrf_token,
    ],
    'bindings' => [
      'enabled' => (bool) $config->get('field_bindings'),
    ],
    'entity' => [
      'type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'id' => $entity->id(),
      'fields' => $fields,
    ],
  ];
```

- [ ] **Step 2: Add the autocomplete URL helper to `gutenberg_next.module`**

Append at the end of the file (imports: add `use Drupal\Component\Utility\Crypt;` and `use Drupal\Core\Site\Settings;` to the `use` block at the top):

```php
/**
 * Builds a core entity-reference autocomplete URL for a field.
 *
 * Mirrors \Drupal\Core\Entity\Element\EntityAutocomplete::processEntityAutocomplete:
 * the selection settings are stored in the entity_autocomplete key/value store
 * under an hmac key derived from the settings, target type and handler.
 */
function gutenberg_next_entity_autocomplete_url(\Drupal\Core\Entity\ContentEntityInterface $entity, string $field_name): ?string {
  $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions(
    $entity->getEntityTypeId(),
    $entity->bundle(),
  );
  $definition = $definitions[$field_name] ?? NULL;
  if (!$definition) {
    return NULL;
  }

  $storage = $definition->getFieldStorageDefinition();
  $target_type = (string) $storage->getSetting('target_type');
  $handler = (string) $definition->getSetting('handler');
  $selection_settings = (array) ($definition->getSetting('handler_settings') ?? []);

  $data = serialize($selection_settings) . $target_type . $handler;
  $key = Crypt::hmacBase64($data, Settings::getHashSalt());

  $store = \Drupal::keyValue('entity_autocomplete');
  if (!$store->has($key)) {
    $store->set($key, $selection_settings);
  }

  return \Drupal\Core\Url::fromRoute('system.entity_autocomplete', [
    'target_type' => $target_type,
    'selection_handler' => $handler,
    'selection_settings_key' => $key,
  ])->toString();
}
```

- [ ] **Step 3: Settings form toggles**

In `src/Form/SettingsForm.php`, inside the `integration` details (after the `show_field_panel` element):

```php
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
```

And in `submitForm()`, after the `debug` line:

```php
      ->set('field_bindings', (bool) $form_state->getValue('field_bindings'))
      ->set('autosave_fields', (bool) $form_state->getValue('autosave_fields'))
```

- [ ] **Step 4: Schema + defaults**

`config/schema/gutenberg_next.schema.yml` — add after `debug`:

```yml
    autosave_fields:
      type: boolean
      label: 'Autosave Drupal field changes'
    field_bindings:
      type: boolean
      label: 'Expose Drupal fields as block binding sources'
```

`config/install/gutenberg_next.settings.yml` — append:

```yml
autosave_fields: true
field_bindings: true
```

- [ ] **Step 5: Verify on the demo site**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php vendor\drush\drush\drush.php cr
& $php vendor\drush\drush\drush.php php:eval "\Drupal::configFactory()->getEditable('gutenberg_next.settings')->set('content_types', ['gnt_article'])->save();"
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/add/gnt_article
$checks = @('field_subtitle', 'autocompleteUrl', '"autosave"', '"bindings"', '"kind":"text"', '"kind":"entity_reference"')
foreach ($c in $checks) { if ($page -match $c) { "OK  $c" } else { "FAIL $c" } }
```

Run from the site root. Expected: all `OK`. Also run `php -l gutenberg_next.module` and `php -l src\Form\SettingsForm.php` in the repo.

- [ ] **Step 6: Commit**

```powershell
git add gutenberg_next.module src/Form/SettingsForm.php config/schema/gutenberg_next.schema.yml config/install/gutenberg_next.settings.yml
git commit -m "feat: drupalSettings payload for values, autosave and bindings"
```

---

### Task 6: Autosave controller, schema, routes, cleanup hooks

**Files:**
- Create: `src/Controller/FieldAutosaveController.php`
- Create: `gutenberg_next.install`
- Modify: `gutenberg_next.routing.yml`
- Modify: `gutenberg_next.module` (hook_cron, hook_entity_delete)

**Interfaces:**
- Consumes: `drupalSettings.gutenbergNext.autosave.url`/`token` from Task 5.
- Produces: `POST|GET|DELETE /editor/gutenberg-next/autosave/node/{entity_id}`
  - POST body `{"fields": {fieldName: value, ...}}` → `{"saved": true, "changed": <ts>}`; 400 on invalid JSON/`fields` not an object.
  - GET → `{"data": <object>|null, "changed": <ts>|null}`.
  - DELETE → `{"cleared": true}`.
  - 403 when the user lacks `use gutenberg` or update access on the node; 404 when the node doesn't exist; only field names that exist on the node's bundle are stored (others silently dropped).

- [ ] **Step 1: Write the controller**

`src/Controller/FieldAutosaveController.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-user autosave snapshots of Drupal field values.
 */
final class FieldAutosaveController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function save(Request $request, string $entity_type, string $entity_id): JsonResponse {
    $entity = $this->checkAccess($entity_type, $entity_id);
    $payload = json_decode($request->getContent(), TRUE);
    $fields = is_array($payload) && is_array($payload['fields'] ?? NULL) ? $payload['fields'] : NULL;
    if ($fields === NULL) {
      return new JsonResponse(['error' => 'Invalid payload: "fields" object required.'], 400);
    }

    $fields = $this->filterKnownFields($entity_type, $entity->bundle(), $fields);

    $this->database->merge('gutenberg_next_field_autosave')
      ->keys([
        'uid' => (int) $this->currentUser()->id(),
        'entity_type' => $entity_type,
        'entity_id' => (int) $entity_id,
      ])
      ->fields([
        'bundle' => $entity->bundle(),
        'data' => serialize($fields),
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    return new JsonResponse(['saved' => TRUE, 'changed' => \Drupal::time()->getRequestTime()]);
  }

  public function load(string $entity_type, string $entity_id): JsonResponse {
    $this->checkAccess($entity_type, $entity_id);
    $row = $this->database->select('gutenberg_next_field_autosave', 'a')
      ->fields('a', ['data', 'changed'])
      ->condition('uid', (int) $this->currentUser()->id())
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', (int) $entity_id)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return new JsonResponse(['data' => NULL, 'changed' => NULL]);
    }

    return new JsonResponse([
      'data' => unserialize($row['data'], ['allowed_classes' => FALSE]),
      'changed' => (int) $row['changed'],
    ]);
  }

  public function clear(string $entity_type, string $entity_id): JsonResponse {
    $this->checkAccess($entity_type, $entity_id);
    $this->database->delete('gutenberg_next_field_autosave')
      ->condition('uid', (int) $this->currentUser()->id())
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', (int) $entity_id)
      ->execute();

    return new JsonResponse(['cleared' => TRUE]);
  }

  private function checkAccess(string $entity_type, string $entity_id): object {
    $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      throw new NotFoundHttpException();
    }
    if (!$entity->access('update')) {
      throw new AccessDeniedHttpException();
    }
    return $entity;
  }

  private function filterKnownFields(string $entity_type, string $bundle, array $fields): array {
    $definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($entity_type, $bundle);

    return array_intersect_key($fields, $definitions);
  }

}
```

- [ ] **Step 2: Schema + hooks**

Create `gutenberg_next.install`:

```php
<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityInterface;

/**
 * @file
 * Install hooks for Gutenberg Next.
 */

/**
 * Implements hook_schema().
 */
function gutenberg_next_schema(): array {
  return [
    'gutenberg_next_field_autosave' => [
      'description' => 'Per-user editor autosave snapshots of Drupal field values.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'uid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'entity_type' => [
          'type' => 'varchar_ascii',
          'length' => 32,
          'not null' => TRUE,
        ],
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'bundle' => [
          'type' => 'varchar_ascii',
          'length' => 128,
          'not null' => TRUE,
          'default' => '',
        ],
        'data' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => TRUE,
        ],
        'changed' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'autosave_owner' => ['uid', 'entity_type', 'entity_id'],
      ],
      'indexes' => [
        'autosave_changed' => ['changed'],
      ],
    ],
  ];
}
```

In `gutenberg_next.module`, append:

```php
/**
 * Implements hook_cron().
 */
function gutenberg_next_cron(): void {
  \Drupal::database()->delete('gutenberg_next_field_autosave')
    ->condition('changed', \Drupal::time()->getRequestTime() - 86400 * 30, '<')
    ->execute();
}

/**
 * Implements hook_entity_delete().
 */
function gutenberg_next_entity_delete(EntityInterface $entity): void {
  $id = $entity->id();
  if ($id === NULL || !is_numeric($id)) {
    return;
  }
  \Drupal::database()->delete('gutenberg_next_field_autosave')
    ->condition('entity_type', $entity->getEntityTypeId())
    ->condition('entity_id', (int) $id)
    ->execute();
}
```

- [ ] **Step 3: Routes**

In `gutenberg_next.routing.yml` append:

```yml
gutenberg_next.field_autosave.save:
  path: '/editor/gutenberg-next/autosave/{entity_type}/{entity_id}'
  defaults:
    _controller: '\Drupal\gutenberg_next\Controller\FieldAutosaveController::save'
    _format: 'json'
  methods: [POST]
  requirements:
    _permission: 'use gutenberg'
    _csrf_request_header_token: 'TRUE'
    entity_type: 'node'
  options:
    no_cache: TRUE

gutenberg_next.field_autosave.load:
  path: '/editor/gutenberg-next/autosave/{entity_type}/{entity_id}'
  defaults:
    _controller: '\Drupal\gutenberg_next\Controller\FieldAutosaveController::load'
    _format: 'json'
  methods: [GET]
  requirements:
    _permission: 'use gutenberg'
    entity_type: 'node'
  options:
    no_cache: TRUE

gutenberg_next.field_autosave.clear:
  path: '/editor/gutenberg-next/autosave/{entity_type}/{entity_id}'
  defaults:
    _controller: '\Drupal\gutenberg_next\Controller\FieldAutosaveController::clear'
    _format: 'json'
  methods: [DELETE]
  requirements:
    _permission: 'use gutenberg'
    _csrf_request_header_token: 'TRUE'
    entity_type: 'node'
  options:
    no_cache: TRUE
```

- [ ] **Step 4: Verify with curl on the demo site**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php vendor\drush\drush\drush.php cr
# Rebuild the authenticated session (module install may reset session):
$login = (& $php vendor\drush\drush\drush.php uli --uri=http://drupal-test-2.test:8080 --no-browser | Select-String -Pattern "http").Matches.Value
curl.exe -s -c C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -L -o NUL $login
$token = (& $php vendor\drush\drush\drush.php php:eval "echo \Drupal::service('csrf_token')->get('rest');")
# POST:
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -H "Content-Type: application/json" -H "X-CSRF-Token: $token" -X POST -d "{\"fields\":{\"field_subtitle\":\"autosaved value\",\"field_notes\":\"n1\"}}" http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
# Expected: {"saved":true,"changed":<ts>}
# GET:
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
# Expected: {"data":{"field_subtitle":"autosaved value","field_notes":"n1"},"changed":<ts>}
# Anonymous 403:
curl.exe -s -o NUL -w "%{http_code}" -X POST -d "{\"fields\":{}}" http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
# Expected: 403
# DELETE:
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -H "X-CSRF-Token: $token" -X DELETE http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
# Expected: {"cleared":true}
```

If the CSRF token from `csrf_token->get('rest')` is rejected, extract the token from the add-form HTML instead: `([regex]::Match($page, 'csrfToken\\":\\"([^\\"]+)')).Groups[1].Value`. Run `php -l` on the new files in the repo first.

- [ ] **Step 5: Commit**

```powershell
git add src/Controller/FieldAutosaveController.php gutenberg_next.install gutenberg_next.routing.yml gutenberg_next.module
git commit -m "feat: per-user field autosave endpoint with cleanup hooks"
```

---

### Task 7: JS data store (`js/data-store.js`)

**Files:**
- Create: `js/data-store.js`
- Modify: `gutenberg_next.libraries.yml` (register the file)

**Interfaces:**
- Consumes: `drupalSettings.gutenbergNext` (Task 5 payload), `window.wp.*` globals.
- Produces: registered store `gutenberg-next/fields` with:
  - actions: `load(payload)`, `setFieldValue(name, value)` (validates + writes widget + autosave-schedules; returns `{ok, message}`), `setInvalid(name, message|null)`, `markSaved()` (clears dirty + DELETE snapshot)
  - selectors: `getField(name)`, `getFields()`, `getValue(name)`, `isDirty()`, `isFieldDirty(name)`, `getEntity()`, `isReady()`, `wasAutosaveRestored()`
  - side effects: dirty → `core/editor` dirty marker (feature-detected), `beforeunload` guard, 2s-debounced autosave POST, restore-on-init, server error scan.

- [ ] **Step 1: Write the file**

`js/data-store.js`:

```js
/**
 * Gutenberg Next: wp.data store for Drupal field state.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  if (!wp || !wp.data || !wp.i18n) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const { createReduxStore, register, select, dispatch, subscribe } = wp.data;
  const { __ } = wp.i18n;

  const settings = function () {
    return drupalSettings.gutenbergNext || {};
  };

  const DEFAULT_STATE = {
    ready: false,
    entity: { type: 'node', bundle: '', id: null },
    fields: {},
    autosaveRestored: false,
  };

  function isBlank(value) {
    return value === '' || value === null || value === undefined ||
      (Array.isArray(value) && value.length === 0);
  }

  function validateField(field, value) {
    if (field.required && isBlank(value)) {
      return { ok: false, message: __('This field is required.') };
    }
    if (field.kind === 'text' && field.maxLength && String(value).length > field.maxLength) {
      return { ok: false, message: __('The value is too long.') };
    }
    if (field.kind === 'number' && !isBlank(value)) {
      const n = Number(value);
      if (Number.isNaN(n)) {
        return { ok: false, message: __('Enter a number.') };
      }
      if (field.numberMin !== undefined && n < field.numberMin) {
        return { ok: false, message: __('The value is below the minimum.') };
      }
      if (field.numberMax !== undefined && n > field.numberMax) {
        return { ok: false, message: __('The value is above the maximum.') };
      }
    }
    if (field.kind === 'list' && !field.multiple && !isBlank(value)) {
      const options = field.options || {};
      if (!Object.prototype.hasOwnProperty.call(options, value)) {
        return { ok: false, message: __('Invalid option selected.') };
      }
    }
    return { ok: true };
  }

  function widgetRoot(fieldName) {
    const selectorName = String(fieldName).replaceAll('_', '-');
    return document.querySelector('[data-drupal-selector="edit-' + selectorName + '"]');
  }

  function setNativeValue(input, value) {
    const proto = Object.getPrototypeOf(input);
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) {
      descriptor.set.call(input, value);
    } else {
      input.value = value;
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function writeWidget(field, value) {
    const root = widgetRoot(field.name);
    if (!root) {
      return false;
    }

    if (field.kind === 'text' || field.kind === 'number') {
      const target = root.querySelector('input[type="text"], input[type="number"], textarea') ||
        (root.matches('input, textarea') ? root : null);
      if (!target) {
        return false;
      }
      setNativeValue(target, value === null || value === undefined ? '' : String(value));
      return true;
    }

    if (field.kind === 'boolean') {
      const checkbox = root.querySelector('input[type="checkbox"]');
      if (!checkbox) {
        return false;
      }
      checkbox.checked = Boolean(value);
      checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }

    if (field.kind === 'list') {
      if (field.multiple) {
        const checkboxes = root.querySelectorAll('input[type="checkbox"]');
        if (!checkboxes.length) {
          return false;
        }
        const selected = new Set(value || []);
        checkboxes.forEach(function (checkbox) {
          checkbox.checked = selected.has(checkbox.value);
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
        return true;
      }
      const select = root.querySelector('select');
      if (select) {
        setNativeValue(select, value === null || value === undefined ? '' : String(value));
        return true;
      }
      const radio = value ? root.querySelector('input[type="radio"][value="' + CSS.escape(String(value)) + '"]') : null;
      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
      return false;
    }

    if (field.kind === 'datetime') {
      const dateInput = root.querySelector('input[type="date"]');
      const timeInput = root.querySelector('input[type="time"]');
      if (field.datetimeStorageFormat === 'Y-m-d') {
        if (!dateInput) {
          return false;
        }
        setNativeValue(dateInput, value === null || value === undefined ? '' : String(value));
        return true;
      }
      if (!dateInput && !timeInput) {
        return false;
      }
      const parts = String(value || '').split('T');
      if (dateInput) {
        setNativeValue(dateInput, parts[0] || '');
      }
      if (timeInput) {
        setNativeValue(timeInput, (parts[1] || '').slice(0, 5));
      }
      return true;
    }

    if (field.kind === 'entity_reference') {
      const input = root.querySelector('input[data-autocomplete-path]');
      if (input) {
        const items = Array.isArray(value) ? value : [value];
        const label = items
          .filter(function (item) { return item && item.id; })
          .map(function (item) { return item.label + ' (' + item.id + ')'; })
          .join(', ');
        setNativeValue(input, label);
        return true;
      }
      const select = root.querySelector('select');
      if (select && !field.multiple) {
        const item = Array.isArray(value) ? value[0] : value;
        setNativeValue(select, item && item.id ? String(item.id) : '');
        return true;
      }
      const checkboxes = root.querySelectorAll('input[type="checkbox"]');
      if (checkboxes.length) {
        const ids = new Set((Array.isArray(value) ? value : []).map(function (item) { return String(item.id); }));
        checkboxes.forEach(function (checkbox) {
          checkbox.checked = ids.has(checkbox.value);
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
        return true;
      }
      return false;
    }

    return false;
  }

  const actions = {
    load: function (payload) {
      return { type: 'LOAD', payload: payload };
    },
    setFieldValue: function (name, value) {
      return { type: 'SET_FIELD_VALUE', name: name, value: value };
    },
    setInvalid: function (name, message) {
      return { type: 'SET_INVALID', name: name, message: message };
    },
    markSaved: function () {
      return { type: 'MARK_SAVED' };
    },
    setAutosaveRestored: function (restored) {
      return { type: 'SET_AUTOSAVE_RESTORED', restored: restored };
    },
  };

  const reducer = (function (state, action) {
    switch (action.type) {
      case 'LOAD': {
        const fields = {};
        (action.payload.entity.fields || []).forEach(function (field) {
          fields[field.name] = Object.assign({}, field, { dirty: false, invalid: null });
        });
        return Object.assign({}, DEFAULT_STATE, {
          ready: true,
          entity: action.payload.entity,
          fields: fields,
        });
      }
      case 'SET_FIELD_VALUE': {
        const field = state.fields[action.name];
        if (!field) {
          return state;
        }
        const result = validateField(field, action.value);
        if (!result.ok) {
          return Object.assign({}, state, {
            fields: Object.assign({}, state.fields, {
              [action.name]: Object.assign({}, field, { invalid: { message: result.message } }),
            }),
          });
        }
        if (!writeWidget(field, action.value)) {
          return Object.assign({}, state, {
            fields: Object.assign({}, state.fields, {
              [action.name]: Object.assign({}, field, { invalid: { message: __('The form widget for this field is not available in the editor.') } }),
            }),
          });
        }
        return Object.assign({}, state, {
          fields: Object.assign({}, state.fields, {
            [action.name]: Object.assign({}, field, { value: action.value, dirty: true, invalid: null }),
          }),
        });
      }
      case 'SET_INVALID':
        if (!state.fields[action.name]) {
          return state;
        }
        return Object.assign({}, state, {
          fields: Object.assign({}, state.fields, {
            [action.name]: Object.assign({}, state.fields[action.name], {
              invalid: action.message ? { message: action.message } : null,
            }),
          }),
        });
      case 'MARK_SAVED': {
        const fields = {};
        Object.keys(state.fields).forEach(function (name) {
          fields[name] = Object.assign({}, state.fields[name], { dirty: false });
        });
        return Object.assign({}, state, { fields: fields });
      }
      case 'SET_AUTOSAVE_RESTORED':
        return Object.assign({}, state, { autosaveRestored: state.autosaveRestored || action.restored });
      default:
        return state;
    }
  });

  const selectors = {
    getField: function (state, name) {
      return state.fields[name] || null;
    },
    getFields: function (state) {
      return state.fields;
    },
    getValue: function (state, name) {
      const field = state.fields[name];
      return field ? field.value : undefined;
    },
    isDirty: function (state) {
      return Object.values(state.fields).some(function (field) { return field.dirty; });
    },
    isFieldDirty: function (state, name) {
      return Boolean(state.fields[name] && state.fields[name].dirty);
    },
    getEntity: function (state) {
      return state.entity;
    },
    isReady: function (state) {
      return state.ready;
    },
    wasAutosaveRestored: function (state) {
      return state.autosaveRestored;
    },
  };

  const store = createReduxStore(STORE_NAME, {
    reducer: reducer,
    actions: actions,
    selectors: selectors,
  });
  register(store);

  function createNotice(message, status) {
    if (!wp.data.dispatch('core/notices')) {
      return;
    }
    wp.data.dispatch('core/notices').createNotice(status || 'info', message, {
      type: 'snackbar',
      isDismissible: true,
    });
  }

  function autosaveToken() {
    const cfg = settings().autosave || {};
    return cfg.token || (drupalSettings.gutenberg && drupalSettings.gutenberg.csrfToken) || '';
  }

  function autosaveRequest(method, body) {
    const cfg = settings().autosave || {};
    if (!cfg.enabled || !cfg.url) {
      return Promise.resolve(null);
    }
    return fetch(cfg.url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': autosaveToken(),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('autosave ' + method + ' failed: ' + response.status);
      }
      return response.json();
    });
  }

  let autosaveTimer = null;
  let autosaveFailed = false;

  function scheduleAutosave() {
    window.clearTimeout(autosaveTimer);
    autosaveTimer = window.setTimeout(function () {
      const current = select(STORE_NAME).getFields();
      const payload = {};
      Object.keys(current).forEach(function (name) {
        if (current[name].dirty) {
          payload[name] = current[name].value;
        }
      });
      autosaveRequest('POST', { fields: payload })
        .then(function () { autosaveFailed = false; })
        .catch(function () {
          if (!autosaveFailed) {
            autosaveFailed = true;
            createNotice(__('Could not autosave Drupal field changes.'), 'warning');
          }
        });
    }, 2000);
  }

  let lastDirty = false;
  subscribe(function () {
    if (!select(STORE_NAME).isReady()) {
      return;
    }
    const dirty = select(STORE_NAME).isDirty();
    if (dirty && !lastDirty) {
      if (wp.data.dispatch('core/editor') && wp.data.dispatch('core/editor').__unstableMarkEditorAsDirty) {
        wp.data.dispatch('core/editor').__unstableMarkEditorAsDirty();
      }
    }
    if (dirty) {
      scheduleAutosave();
    }
    lastDirty = dirty;
  });

  document.addEventListener('submit', function () {
    window.clearTimeout(autosaveTimer);
    dispatch(STORE_NAME).markSaved();
    autosaveRequest('DELETE').catch(function () {});
  }, true);

  window.addEventListener('beforeunload', function (event) {
    if (select(STORE_NAME).isReady() && select(STORE_NAME).isDirty()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  function scanServerErrors() {
    const fields = select(STORE_NAME).getFields();
    Object.keys(fields).forEach(function (name) {
      const root = widgetRoot(name);
      if (!root) {
        return;
      }
      const error = root.closest('.form-item--error') ||
        root.querySelector('[aria-invalid="true"], .form-item--error');
      if (error) {
        const messageEl = error.querySelector('.form-item--error-message');
        dispatch(STORE_NAME).setInvalid(name, messageEl ? messageEl.textContent.trim() : __('Validation error.'));
      }
    });
  }

  function restoreAutosave() {
    const cfg = settings().autosave || {};
    if (!cfg.enabled || !cfg.url) {
      return;
    }
    autosaveRequest('GET')
      .then(function (response) {
        const data = response && response.data;
        if (!data || typeof data !== 'object') {
          return;
        }
        const current = select(STORE_NAME).getFields();
        const differs = Object.keys(data).some(function (name) {
          const field = current[name];
          if (!field) {
            return false;
          }
          return JSON.stringify(data[name]) !== JSON.stringify(field.value);
        });
        if (!differs) {
          autosaveRequest('DELETE').catch(function () {});
          return;
        }
        Object.keys(data).forEach(function (name) {
          if (current[name]) {
            dispatch(STORE_NAME).setFieldValue(name, data[name]);
          }
        });
        dispatch(STORE_NAME).setAutosaveRestored(true);
        createNotice(__('Drupal field changes restored from autosave.'));
      })
      .catch(function () {});
  }

  Drupal.behaviors.gutenbergNextDataStore = {
    attach: function () {
      once('gutenberg-next-data-store', 'body').forEach(function () {
        const payload = settings();
        if (!payload.entity) {
          return;
        }
        dispatch(STORE_NAME).load(payload);
        scanServerErrors();
        restoreAutosave();
      });
    },
  };
})(Drupal, drupalSettings, once);
```

- [ ] **Step 2: Register the library file**

`gutenberg_next.libraries.yml` — add after `js/editor-bridge.js`:

```yml
    js/data-store.js: {}
```

- [ ] **Step 3: Verify**

```powershell
node --check js\data-store.js
```

Then on the demo site (`C:\laragon\www\Drupal-Test-2`):

```powershell
& $php vendor\drush\drush\drush.php cr
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/1/edit
if ($page -match "data-store.js") { "LIBRARY ATTACHED OK" } else { "FAIL" }
```

- [ ] **Step 4: Commit**

```powershell
git add js/data-store.js gutenberg_next.libraries.yml
git commit -m "feat: wp.data store for Drupal fields with widget write-back and autosave"
```

---

### Task 8: Store-driven field panel (`js/field-panel.js`)

**Files:**
- Create: `js/field-panel.js`
- Modify: `js/editor-shell.js` (remove the old DOM-jump `registerFieldPanel` + its call in `activate()`)
- Modify: `gutenberg_next.libraries.yml` (register the new file)

**Interfaces:**
- Consumes: store from Task 7 (`getFields`, `getField`, `setFieldValue`, `setInvalid`, `getValue`), `window.GutenbergNext.focusDrupalField` (0.1 bridge), `window.wp.plugins/element/components/editor|editPost/apiFetch/notices`.
- Produces: `PluginDocumentSettingPanel` "Drupal fields" rendering one row per catalog field: native controls for `text|number|boolean|list|datetime` and entity_reference autocomplete (via `field.autocompleteUrl`), summary + "Edit in form" button for `complex`, error badges from `invalid`, required markers. Registers plugin `gutenberg-next-drupal-fields` (same name as 0.1 to avoid double registration).

- [ ] **Step 1: Write the file**

`js/field-panel.js`:

```js
/**
 * Gutenberg Next: store-driven Drupal field panel.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.components) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const { createElement, useState, useEffect } = wp.element;
  const { Button, Notice, TextControl, TextareaControl, ToggleControl, SelectControl, CheckboxControl, FormTokenField, ComboboxControl } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const PluginDocumentSettingPanel = editorApi && editorApi.PluginDocumentSettingPanel;
  const { select, dispatch } = wp.data;

  function createNotice(message, status) {
    if (wp.data.dispatch('core/notices')) {
      wp.data.dispatch('core/notices').createNotice(status || 'info', message, {
        type: 'snackbar',
        isDismissible: true,
      });
    }
  }

  function FieldControl(props) {
    const field = props.field;
    const onChange = function (value) {
      dispatch(STORE_NAME).setFieldValue(field.name, value);
    };

    if (field.kind === 'text' && field.maxLength && field.maxLength <= 512) {
      return createElement(TextControl, {
        label: field.label,
        value: field.value || '',
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'text') {
      return createElement(TextareaControl, {
        label: field.label,
        value: field.value || '',
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'number') {
      return createElement(TextControl, {
        type: 'number',
        label: field.label,
        value: field.value === null || field.value === undefined ? '' : String(field.value),
        onChange: function (next) { onChange(next === '' ? null : Number(next)); },
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'boolean') {
      return createElement(ToggleControl, {
        label: field.label,
        checked: Boolean(field.value),
        onChange: function (checked) { onChange(checked); },
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'list' && field.multiple) {
      const options = field.options || {};
      return createElement(
        'div',
        { className: 'gutenberg-next-checkboxes' },
        Object.keys(options).map(function (key) {
          return createElement(CheckboxControl, {
            key: key,
            label: options[key],
            checked: (field.value || []).includes(key),
            onChange: function (checked) {
              const next = new Set(field.value || []);
              if (checked) {
                next.add(key);
              } else {
                next.delete(key);
              }
              onChange([...next]);
            },
          });
        }),
        field.invalid ? createElement(Notice, { status: 'error', isDismissible: false }, field.invalid.message) : null,
      );
    }
    if (field.kind === 'list') {
      const options = field.options || {};
      const choices = Object.keys(options).map(function (key) {
        return { value: key, label: options[key] };
      });
      choices.unshift({ value: '', label: '- None -' });
      return createElement(SelectControl, {
        label: field.label,
        value: field.value || '',
        options: choices,
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'datetime') {
      return createElement(DateTimeControl, {
        field: field,
        onChange: onChange,
      });
    }
    if (field.kind === 'entity_reference') {
      return createElement(EntityReferenceControl, {
        field: field,
        onChange: onChange,
      });
    }
    return createElement(ComplexControl, {
      field: field,
    });
  }

  function formatStorageValue(field) {
    const value = field.value || '';
    if (field.datetimeStorageFormat === 'Y-m-d') {
      return value;
    }
    return value.length >= 16 ? value.slice(0, 16) : value;
  }

  function DateTimeControl(props) {
    const { field } = props;
    const [localValue, setLocalValue] = useState(formatStorageValue(field));

    useEffect(function () {
      setLocalValue(formatStorageValue(field));
    }, [field.value]);

    if (field.datetimeStorageFormat === 'Y-m-d') {
      return createElement(TextControl, {
        type: 'date',
        label: field.label,
        value: localValue,
        onChange: function (next) {
          setLocalValue(next);
          props.onChange(next ? next : null);
        },
        help: field.invalid ? field.invalid.message : null,
      });
    }

    return createElement(TextControl, {
      type: 'datetime-local',
      label: field.label,
      value: localValue,
      onChange: function (next) {
        setLocalValue(next);
        props.onChange(next ? next + ':00' : null);
      },
      help: field.invalid ? field.invalid.message : null,
    });
  }

  function EntityReferenceControl(props) {
    const { field } = props;
    const items = Array.isArray(field.value) ? field.value : [];
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);

    function searchEntities(query) {
      if (!field.autocompleteUrl) {
        return;
      }
      setLoading(true);
      wp.apiFetch({ path: field.autocompleteUrl + '&q=' + encodeURIComponent(query) })
        .then(function (matches) {
          setSuggestions(matches.map(function (match) {
            const idMatch = String(match.value).match(/\((\d+)\)$/);
            return {
              id: Number(idMatch ? idMatch[1] : 0),
              label: match.label,
            };
          }));
        })
        .catch(function () {
          setSuggestions([]);
        })
        .finally(function () {
          setLoading(false);
        });
    }

    const tokenValues = items.map(function (item) { return item.label + ' (' + item.id + ')'; });

    if (field.multiple) {
      return createElement(FormTokenField, {
        label: field.label,
        value: tokenValues,
        suggestions: suggestions.map(function (s) { return s.label + ' (' + s.id + ')'; }),
        onChange: function (tokens) {
          const next = tokens.map(function (token) {
            const idMatch = String(token).match(/\((\d+)\)$/);
            const existing = items.find(function (item) { return String(item.id) === String(idMatch ? idMatch[1] : ''); });
            return existing || { id: Number(idMatch ? idMatch[1] : 0), label: String(token).replace(/\s*\(\d+\)$/, '') };
          }).filter(function (item) { return item.id > 0; });
          props.onChange(next);
        },
        onInputChange: searchEntities,
        tokenizeOnBlur: false,
        help: loading ? 'Searching…' : (field.invalid ? field.invalid.message : null),
      });
    }

    return createElement(ComboboxControl, {
      label: field.label,
      value: items.length ? { value: String(items[0].id), label: items[0].label } : null,
      options: suggestions.map(function (s) { return { value: String(s.id), label: s.label }; }),
      onInputChange: function (inputValue) {
        searchEntities(inputValue || '');
      },
      onChange: function (option) {
        props.onChange(option ? [{ id: Number(option.value), label: option.label }] : []);
      },
      help: field.invalid ? field.invalid.message : null,
    });
  }

  function ComplexControl(props) {
    const { field } = props;
    const detail = (field.value && field.value.detail) || [];
    return createElement(
      'div',
      { className: 'gutenberg-next-complex-field' },
      createElement('p', null, detail.length ? detail.join('; ') : '(empty)'),
      field.invalid ? createElement(Notice, { status: 'error', isDismissible: false }, field.invalid.message) : null,
      createElement(Button, {
        variant: 'secondary',
        size: 'compact',
        onClick: function () {
          if (!window.GutenbergNext || !window.GutenbergNext.focusDrupalField(field.name)) {
            createNotice('Drupal field "' + field.label + '" is not currently visible on the form.', 'warning');
          }
        },
      }, 'Edit in form'),
    );
  }

  function FieldPanel() {
    const fields = select(STORE_NAME).getFields();
    const names = Object.keys(fields).sort(function (a, b) {
      return fields[a].label.localeCompare(fields[b].label);
    });

    return createElement(
      PluginDocumentSettingPanel,
      {
        name: 'gutenberg-next-drupal-fields',
        title: 'Drupal fields',
        className: 'gutenberg-next-document-panel',
      },
      names.map(function (name) {
        const field = fields[name];
        return createElement(
          'div',
          { key: name, className: 'gutenberg-next-field-row' },
          createElement(FieldControl, { field: field }),
        );
      }),
    );
  }

  Drupal.behaviors.gutenbergNextFieldPanel = {
    attach: function () {
      once('gutenberg-next-field-panel', 'body').forEach(function () {
        const config = drupalSettings.gutenbergNext || {};
        if (!config.showFieldPanel || !PluginDocumentSettingPanel) {
          return;
        }
        if (window.__gutenbergNextFieldPanelRegistered) {
          return;
        }
        wp.plugins.registerPlugin('gutenberg-next-drupal-fields', { render: FieldPanel });
        window.__gutenbergNextFieldPanelRegistered = true;
      });
    },
  };
})(Drupal, drupalSettings, once);
```

- [ ] **Step 2: Remove the old panel from `js/editor-shell.js`**

Delete the entire `registerFieldPanel()` function (0.1 implementation) and remove the `registerFieldPanel();` line from `activate()` so `activate()` becomes:

```js
  function activate() {
    if (!editorExists()) {
      return false;
    }
    applyDocumentClasses();
    addDrupalBadge();
    injectCanvasStyles();
    return true;
  }
```

- [ ] **Step 3: Register the library file**

`gutenberg_next.libraries.yml` — after `js/data-store.js`:

```yml
    js/field-panel.js: {}
```

- [ ] **Step 4: Add panel styles**

`css/editor-shell.css` — append:

```css
.gutenberg-next-enabled .gutenberg-next-field-row {
  margin-bottom: 16px;
}

.gutenberg-next-enabled .gutenberg-next-field-invalid .components-base-control__help {
  color: var(--wp-admin-theme-color-darker-10, #cc1818);
}

.gutenberg-next-enabled .gutenberg-next-complex-field p {
  margin: 0 0 8px;
}
```

- [ ] **Step 5: Verify**

```powershell
node --check js\field-panel.js
node --check js\editor-shell.js
```

On the demo site: `& $php vendor\drush\drush\drush.php cr`, then re-fetch the edit page and assert `field-panel.js` is attached (`curl ... | Select-String field-panel.js`). Full interaction (typing in the panel) is on the browser checklist in Task 10.

- [ ] **Step 6: Commit**

```powershell
git add js/field-panel.js js/editor-shell.js gutenberg_next.libraries.yml css/editor-shell.css
git commit -m "feat: store-driven Drupal field panel with native controls"
```

---

### Task 9: Block bindings (`js/bindings.js`)

**Files:**
- Create: `js/bindings.js`
- Modify: `gutenberg_next.libraries.yml`

**Interfaces:**
- Consumes: store (Task 7), `wp.blocks.registerBlockBindingsSource`.
- Produces: binding source `gutenberg-next/field` (`label` "Drupal field"), read via `getValues` mapping `args.field` (field name) → attribute value; write via `setValues` when the API supports it (feature-detected), otherwise read-only.

- [ ] **Step 1: Write the file**

`js/bindings.js`:

```js
/**
 * Gutenberg Next: Drupal field block binding source.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  if (!wp || !wp.blocks || !wp.data || !wp.blocks.registerBlockBindingsSource) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const { select, dispatch } = wp.data;

  const source = {
    name: 'gutenberg-next/field',
    label: 'Drupal field',

    getValues: function (args) {
      const bindings = args.bindings || {};
      const values = {};
      Object.keys(bindings).forEach(function (attribute) {
        const binding = bindings[attribute];
        if (!binding || binding.source !== 'gutenberg-next/field') {
          return;
        }
        const fieldName = binding.args && binding.args.field;
        if (!fieldName) {
          return;
        }
        const value = select(STORE_NAME).getValue(fieldName);
        values[attribute] = value === undefined || value === null ? '' : String(value);
      });
      return values;
    },

    setValues: function (args) {
      const attributeName = args.attributeName;
      const fieldName = args.binding && args.binding.args && args.binding.args.field;
      if (!fieldName) {
        return;
      }
      dispatch(STORE_NAME).setFieldValue(fieldName, args.value);
    },
  };

  // setValues support depends on the installed Gutenberg generation; only pass
  // the callback when the API can consume it (older builds read-only).
  if (!source.setValues || typeof source.setValues !== 'function') {
    delete source.setValues;
  }

  wp.blocks.registerBlockBindingsSource(source);

  Drupal.behaviors.gutenbergNextBindings = {
    attach: function () {
      once('gutenberg-next-bindings', 'body').forEach(function () {
        const config = drupalSettings.gutenbergNext || {};
        if (config.debug) {
          // eslint-disable-next-line no-console
          console.info('[Gutenberg Next] block binding source registered', {
            enabled: Boolean(config.bindings && config.bindings.enabled),
            setValuesSupported: typeof wp.blocks.getBlockBindingsSource('gutenberg-next/field').setValues === 'function',
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
```

Note: the binding source registration itself is unconditional (harmless when `bindings.enabled` is false — no UI surfaces it), matching the module's feature-detect philosophy.

- [ ] **Step 2: Register the library file**

`gutenberg_next.libraries.yml` — after `js/field-panel.js`:

```yml
    js/bindings.js: {}
```

- [ ] **Step 3: Verify**

```powershell
node --check js\bindings.js
```

On the demo site: `cr`, then open `/node/1/edit` in a browser (via `drush uli`) and confirm in the browser console that `wp.blocks.getBlockBindingsSource('gutenberg-next/field')` exists, and that the native block bindings UI (paragraph block → advanced/link icon) lists "Drupal field". If the native UI is missing in this build, the source is still registered but not reachable — then (and only then) add a minimal BlockEdit fallback panel to this file using the 0.1 `mapping-fields` precedent:

```js
  const { createHigherOrderComponent } = wp.compose;
  const { InspectorControls, PanelBody, SelectControl } = wp.blockEditor || wp.editor;
  if (createHigherOrderComponent && InspectorControls && SelectControl) {
    const withFieldBinding = createHigherOrderComponent(function (BlockEdit) {
      return function (props) {
        const bindableAttributes = ['content', 'text', 'url', 'alt', 'title'];
        const available = Object.keys(props.attributes).filter(function (name) {
          return bindableAttributes.includes(name);
        });
        return createElement(
          wp.element.Fragment,
          null,
          createElement(BlockEdit, props),
          available.length ? createElement(InspectorControls, null,
            createElement(PanelBody, { title: 'Drupal field binding', initialOpen: false },
              available.map(function (attribute) {
                const fields = select(STORE_NAME).getFields();
                const choices = [{ value: '', label: '- None -' }].concat(
                  Object.keys(fields).map(function (name) { return { value: name, label: fields[name].label }; }),
                );
                return createElement(SelectControl, {
                  key: attribute,
                  label: attribute,
                  value: (props.attributes.metadata && props.attributes.metadata.bindings &&
                    props.attributes.metadata.bindings[attribute] &&
                    props.attributes.metadata.bindings[attribute].args &&
                    props.attributes.metadata.bindings[attribute].args.field) || '',
                  options: choices,
                  onChange: function (next) {
                    const metadata = Object.assign({}, props.attributes.metadata);
                    metadata.bindings = Object.assign({}, metadata.bindings || {});
                    if (next) {
                      metadata.bindings[attribute] = { source: 'gutenberg-next/field', args: { field: next } };
                    } else {
                      delete metadata.bindings[attribute];
                    }
                    props.setAttributes({ metadata: metadata });
                  },
                });
              }),
            ),
          ) : null,
        );
      };
    }, 'withFieldBinding');
    wp.hooks.addFilter('editor.BlockEdit', 'gutenberg-next/field-binding', withFieldBinding);
  }
```

(Requires adding `const { createElement } = wp.element;` at the top of the IIFE.)

- [ ] **Step 4: Commit**

```powershell
git add js/bindings.js gutenberg_next.libraries.yml
git commit -m "feat: Drupal field block binding source"
```

---

### Task 10: Release tasks, docs, final verification, push

**Files:**
- Modify: `gutenberg_next.info.yml` (version `0.2.0-alpha1`)
- Modify: `composer.json` (version `0.2.0-alpha1`)
- Modify: `CHANGELOG.md` (0.2.0-alpha1 section)
- Modify: `docs/ROADMAP.md` (tick the six 0.2 items)
- Modify: `README.md` (0.2 feature list + data-adapter paragraph)
- Modify: `docs/TESTING.md` (browser checklist for 0.2 features)

**Interfaces:**
- Consumes: everything from Tasks 3-9.

- [ ] **Step 1: Version bumps**

`gutenberg_next.info.yml`:

```yml
version: '0.2.0-alpha1'
```

`composer.json` — change `"version": "0.1.0-alpha1"` to `"version": "0.2.0-alpha1"` (in `extra.drupal`).

- [ ] **Step 2: CHANGELOG**

Add above the 0.1.0-alpha1 section:

```markdown
## 0.2.0-alpha1 - 2026-08-17

### Added

- Drupal entity data store (`wp.data` store `gutenberg-next/fields`) as the editor-side source of truth for non-body fields.
- Typed field value serialization (string, text, number, boolean, list, datetime, timestamp, entity reference) with human-readable snapshots for complex fields.
- Store-driven Drupal fields panel with native controls per field kind.
- Drupal field block binding source (`gutenberg-next/field`) for heading, paragraph, button and image blocks.
- Per-user autosave snapshots of unsaved field changes with restore-on-reload.
- Client validation and Drupal server error synchronization into the field panel.
- Entity-reference autocomplete through Drupal core's autocomplete endpoint.
```

- [ ] **Step 3: ROADMAP ticks**

In `docs/ROADMAP.md`, change the six 0.2 checkboxes from `- [ ]` to `- [x]`.

- [ ] **Step 4: README update**

Replace the "What alpha 1 does today" section title with "What it does today" and append to its bullet list:

```markdown
- Provides a Gutenberg-native data store for Drupal fields with typed values, dirty tracking and validation sync.
- Lets editors edit supported Drupal fields directly in the document sidebar (strings, numbers, booleans, lists, dates, entity references) and binds heading, paragraph, button and image blocks to Drupal field values.
- Autosaves unsaved Drupal field changes per user and restores them after an accidental reload.
```

- [ ] **Step 5: TESTING.md additions**

Append to `docs/TESTING.md`:

```markdown
## 0.2 data adapter checklist

1. Edit each native field kind in the Drupal fields panel; save; reload; values persisted.
2. Bind a heading block to `field_subtitle` via the block bindings UI; confirm the value renders and follows panel edits.
3. Enter an invalid value (e.g. text in a number field); confirm the inline error appears and nothing is written to the form widget.
4. Make edits, reload without saving; confirm the "restored from autosave" snackbar and the values are back.
5. Save; edit; reload; confirm no stale autosave restore happens.
6. Multi-value entity reference: search "autosave" in the token field; select; save; values persisted.
7. Required field: clear it, submit; confirm the server error message surfaces in the panel.
```

- [ ] **Step 6: Full local verification**

In the repo:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l gutenberg_next.module
node --check js\data-store.js
node --check js\field-panel.js
node --check js\bindings.js
node --check js\editor-bridge.js
node --check js\editor-shell.js
php tests\check-field-catalog.php
php tests\check-field-serializer.php
```

On the demo site (fresh `cr`, re-login if the session expired), re-run the Task 5 drupalSettings checks and the Task 6 curl round-trip once more. Then run the browser checklist from Step 5 (via `drush uli`) — this is the interactive pass.

- [ ] **Step 7: Commit and push**

```powershell
git add -A
git status --short
git commit -m "feat: 0.2.0-alpha1 data adapter release"
git push
gh run watch --repo bfrye26/drupal-gutenberg-next --exit-status (gh run list --repo bfrye26/drupal-gutenberg-next --workflow ci.yml --limit 1 --json databaseId --jq ".[0].databaseId")
```

Expected: CI green (syntax, catalog check, serializer check, composer validate).

---

## Self-review notes

- Spec coverage: 3.1 serializer (Task 3), 3.2 catalog meta (Task 4), 3.3 autosave controller+table (Task 6) with new-entity scope per Task 1 amendment, 3.4 payload (Task 5), 4.1 store (Task 7), 4.2 panel (Task 8), 4.3 bindings incl. conditional fallback (Task 9), 5 tiers (Tasks 4+8), 6 validation sync (Task 7 `validateField` + `scanServerErrors`), 7 DOM write adapters (Task 7 `writeWidget`), 8 config/versioning/release (Tasks 5+10), 9 autocomplete adapter (Tasks 5+8), 10 security (Tasks 5+6), 11 verification (Tasks 2, 5, 6, 8, 9, 10), 12 done definition (Task 10).
- Type consistency: store name `gutenberg-next/fields`, action names `setFieldValue`/`setInvalid`/`markSaved`/`setAutosaveRestored`, selector names as listed in Task 7, used identically in Tasks 8-9. `field.autocompleteUrl` produced in Task 5, consumed in Task 8. `datetimeStorageFormat` produced in Task 4, consumed in Tasks 7-8. `maxLength`/`numberMin`/`numberMax`/`options`/`multiple`/`targetType` produced in Task 4, consumed in Tasks 7-8.
- Deliberate deviations from spec (both recorded in Task 1 spec amendment): standalone serializer check instead of kernel test; autosave for saved entities only.
