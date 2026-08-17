# Gutenberg Next 0.3 — Publishing Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the 0.3 publishing-parity milestone: a Gutenberg-native pre-publish flow with Drupal status, scheduled publishing, author/entity-reference visibility, taxonomy controls, URL alias, featured media, and Content Moderation workflow states.

**Architecture:** A PHP `PublishInfoBuilder` serializes publish state into `drupalSettings.gutenbergNext.publish`; `js/pre-publish.js` renders Gutenberg's `PluginPrePublishPanel` (opening the native publish sidebar from a header button), writes every edit back into the real Drupal widgets via shared bridge helpers, and guards saves through the `editor.__unstableSavePost` filter. Scheduler/moderation surfaces are feature-detected; the form submit stays the only persistence path.

**Tech Stack:** Drupal 11 site, Drupal core ^10.3||^11 module (PHP >=8.1), Drupal Gutenberg 4.0.x (`@wordpress/*` globals, WP 6.9 generation), vanilla JS (no build step), MySQL, Windows PowerShell + curl + drush for verification.

**Spec:** `docs/superpowers/specs/2026-08-17-gutenberg-next-0.3-publishing-parity-design.md`

## Global Constraints

- Repo root: `C:\Users\User\Downloads\gutenberg-next-0.1.0-alpha1\gutenberg_next`. Work on branch `0.3-publishing-parity` (create at execution start: `git switch -c 0.3-publishing-parity`); merge/push happens in the final task.
- Demo site: `C:\laragon\www\Drupal-Test-2`, URL `http://drupal-test-2.test:8080/`, PHP CLI `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, Composer `C:\laragon\bin\composer\composer.phar`, drush `& $php vendor\drush\drush\drush.php <args>` from the site root, cookie jar `C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar`.
- The site's module copy is NOT live: before any site verification run `robocopy <repo> <site>\web\modules\custom\gutenberg_next /MIR /XD .git .superpowers /NJH /NJS` then `& $php vendor\drush\drush\drush.php cr`. If the web returns 500s with stale DI errors afterwards, delete cache_container rows via `drush php:script` (temp file: `\Drupal::database()->delete('cache_container')->execute();`) and re-request.
- PowerShell 5.1 strips double quotes inside `php:eval` strings — always use `drush php:script <tempfile.php>` (write the PHP to a temp file under `C:\Users\User\AppData\Local\Temp\opencode\` first).
- No new composer/npm dependencies in the module; no build step; JS is plain files over `window.wp.*` globals with feature-detect gates (0.1/0.2 pattern).
- PHP classes: `final`, `declare(strict_types=1)`, constructor-promoted readonly properties; services in `gutenberg_next.services.yml`. JS files: IIFE `(function (Drupal, drupalSettings, once) { 'use strict'; ... })(Drupal, drupalSettings, once)`.
- Write path is form-submit-backed: controls write Drupal widgets; no new validation rules; no new REST routes; permissions unchanged.
- Every task: run `php -l` on touched PHP and `node --check` on touched JS, then commit with a `feat:`/`fix:`/`docs:` message.
- Session jar rebuild when stale: `$login = (& $php vendor\drush\drush\drush.php uli --uri=http://drupal-test-2.test:8080 --no-browser | Select-String -Pattern "http").Matches.Value; curl.exe -s -c C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -L -o NUL $login`

---

### Task 1: Demo site additions (environment, no repo commits)

**Files:** none in the repo. Site dir: `C:\laragon\www\Drupal-Test-2`.

**Interfaces:**
- Produces: gnt_article moderated by a `gnt_editorial` workflow (draft/review/published), scheduler enabled for gnt_article, vocabulary `topics` + `field_topics` term-reference field, and a recorded list of the real `data-drupal-selector` widget roots used by Tasks 3-5.

- [ ] **Step 1: Install and enable scheduler**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
$composer = "C:\laragon\bin\composer\composer.phar"
& $php $composer require drupal/scheduler --no-interaction
& $php vendor\drush\drush\drush.php en scheduler -y
```

Run from the site root (allow several minutes for composer). Expected: scheduler enabled.

- [ ] **Step 2: Enable scheduling for gnt_article**

Write this to `C:\Users\User\AppData\Local\Temp\opencode\t1-scheduler.php`:

```php
<?php
$type = \Drupal\node\Entity\NodeType::load('gnt_article');
$type->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
$type->setThirdPartySetting('scheduler', 'unpublish_enable', TRUE);
$type->save();
echo "scheduler enabled for gnt_article\n";
```

Run: `& $php vendor\drush\drush\drush.php php:script C:\Users\User\AppData\Local\Temp\opencode\t1-scheduler.php`

- [ ] **Step 3: Enable content_moderation and create the workflow**

```powershell
& $php vendor\drush\drush\drush.php en content_moderation -y
```

Write this to `C:\Users\User\AppData\Local\Temp\opencode\t1-workflow.php`:

```php
<?php
$existing = \Drupal\workflows\Entity\Workflow::load('gnt_editorial');
if ($existing) {
  $existing->delete();
}
$workflow = \Drupal\workflows\Entity\Workflow::create([
  'id' => 'gnt_editorial',
  'label' => 'GNT Editorial',
  'type' => 'content_moderation',
]);
$plugin = $workflow->getTypePlugin();
$plugin->addState('draft', 'Draft');
$plugin->addState('review', 'In review');
$plugin->addState('published', 'Published');
$plugin->addTransition('create_new_draft', 'Create New Draft', ['draft', 'review', 'published'], 'draft');
$plugin->addTransition('send_to_review', 'Send to Review', ['draft', 'review'], 'review');
$plugin->addTransition('publish', 'Publish', ['draft', 'review', 'published'], 'published');
$plugin->addEntityTypeAndBundle('node', 'gnt_article');
$workflow->save();
echo "workflow gnt_editorial created\n";
```

Run it via `drush php:script`. Expected: printed confirmation; if `addState`/`addTransition`/`addEntityTypeAndBundle` do not exist on this core version, inspect `$plugin` methods (`print_r(get_class_methods($plugin));`) and use the configuration-array equivalent:

```php
<?php
$workflow = \Drupal\workflows\Entity\Workflow::load('gnt_editorial');
$plugin = $workflow->getTypePlugin();
$config = $plugin->getConfiguration();
$config['states'] = [
  'draft' => ['label' => 'Draft', 'published' => FALSE, 'default_revision' => FALSE],
  'review' => ['label' => 'In review', 'published' => FALSE, 'default_revision' => FALSE],
  'published' => ['label' => 'Published', 'published' => TRUE, 'default_revision' => TRUE],
];
$config['transitions'] = [
  'create_new_draft' => ['label' => 'Create New Draft', 'from' => ['draft', 'review', 'published'], 'to' => 'draft'],
  'send_to_review' => ['label' => 'Send to Review', 'from' => ['draft', 'review'], 'to' => 'review'],
  'publish' => ['label' => 'Publish', 'from' => ['draft', 'review', 'published'], 'to' => 'published'],
];
$config['entity_types']['node'] = ['gnt_article'];
$config['default_moderation_state'] = 'draft';
$plugin->setConfiguration($config);
$workflow->save();
echo "workflow gnt_editorial configured\n";
```

- [ ] **Step 4: Taxonomy vocabulary + term reference field**

Write to `C:\Users\User\AppData\Local\Temp\opencode\t1-taxonomy.php`:

```php
<?php
if (!\Drupal\taxonomy\Entity\Vocabulary::load('topics')) {
  \Drupal\taxonomy\Entity\Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
  \Drupal\taxonomy\Entity\Term::create(['vid' => 'topics', 'name' => 'Drupal'])->save();
  \Drupal\taxonomy\Entity\Term::create(['vid' => 'topics', 'name' => 'Gutenberg'])->save();
}
if (!\Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_topics')) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    'field_name' => 'field_topics',
    'entity_type' => 'node',
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => -1,
  ])->save();
}
if (!\Drupal\field\Entity\FieldConfig::loadByName('node', 'gnt_article', 'field_topics')) {
  \Drupal\field\Entity\FieldConfig::create([
    'field_name' => 'field_topics',
    'entity_type' => 'node',
    'bundle' => 'gnt_article',
    'label' => 'Topics',
    'settings' => [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['topics']],
    ],
  ])->save();
}
$form_display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.gnt_article.default');
$form_display->setComponent('field_topics', ['type' => 'entity_reference_autocomplete', 'region' => 'content']);
$form_display->save();
echo "taxonomy ready\n";
```

Run via `drush php:script`, then `& $php vendor\drush\drush\drush.php cr`.

- [ ] **Step 5: Record widget selectors**

Rebuild the session jar (Global Constraints one-liner), fetch `http://drupal-test-2.test:8080/node/1/edit` with the jar, and extract every `data-drupal-selector` value matching these roots: `edit-status`, `edit-moderation-state`, `edit-publish-on`, `edit-unpublish-on`, `edit-path-0`, `edit-field-topics`, `edit-field-photo`. PowerShell:

```powershell
$html = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/1/edit
[regex]::Matches($html, 'data-drupal-selector="(edit-(?:status|moderation-state|publish-on|unpublish-on|path-0|field-topics|field-photo)[^"]*)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
```

Record the exact list in the report. Notes to verify: whether `edit-status` exists at all (content_moderation usually removes it on moderated bundles — that is expected and shapes Task 5), and whether publish-on/unpublish-on render date/time inputs or text inputs.

- [ ] **Step 6: Verify the 0.2 flow is intact**

With the jar, assert the add form still contains `"gutenbergNext"` and the autosave round-trip still works:

```powershell
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/add/gnt_article
if ($page -match '"gutenbergNext"') { "PAYLOAD OK" } else { "PAYLOAD FAIL" }
$token = ([regex]::Match($page, 'csrfToken\\":\\"([^\\"]+)')).Groups[1].Value
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -H "Content-Type: application/json" -H "X-CSRF-Token: $token" -X POST -d "{\"fields\":{\"field_subtitle\":\"0.3 smoke\"}}" http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -H "X-CSRF-Token: $token" -X DELETE http://drupal-test-2.test:8080/editor/gutenberg-next/autosave/node/1
```

Expected: PAYLOAD OK, `{"saved":true,...}`, the stored value on GET, `{"cleared":true}`.

- [ ] **Step 7: No commit** — environment only; record deviations in the report.

---

### Task 2: PublishInfoBuilder pure helpers + standalone check

**Files:**
- Create: `src/Bridge/PublishInfoBuilder.php` (this task: class shell + two static pure methods only)
- Create: `tests/check-publish-info.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: `Drupal\gutenberg_next\Bridge\PublishInfoBuilder::parseOverrides(string $raw): array` (map bundle => field name string, or FALSE for `none`) and `PublishInfoBuilder::detectFeaturedField(array $catalog_fields, array $overrides, string $bundle): ?string`. Task 3 builds the service around these exact signatures.

- [ ] **Step 1: Write the failing check**

`tests/check-publish-info.php`:

```php
<?php

declare(strict_types=1);

/**
 * Self-check for the PublishInfoBuilder pure helpers.
 *
 * Runs without a Drupal installation (the helpers are static and the class is
 * never instantiated here). Usage:
 *
 *   php tests/check-publish-info.php
 *
 * Exits non-zero on the first failure.
 */

namespace {
  require __DIR__ . '/../src/Bridge/PublishInfoBuilder.php';

  use Drupal\gutenberg_next\Bridge\PublishInfoBuilder;

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $check('parse overrides basic', PublishInfoBuilder::parseOverrides("article: field_image\npage: none") === ['article' => 'field_image', 'page' => FALSE]);
  $check('parse overrides whitespace and comments', PublishInfoBuilder::parseOverrides("  article :  field_photo \n# comment\n\n") === ['article' => 'field_photo']);
  $check('parse overrides later duplicate wins', PublishInfoBuilder::parseOverrides("a: x\na: y") === ['a' => 'y']);
  $check('parse overrides empty value disables', PublishInfoBuilder::parseOverrides('a:') === ['a' => FALSE]);
  $check('parse overrides empty string', PublishInfoBuilder::parseOverrides('') === []);

  $catalog = [
    ['name' => 'field_photo', 'kind' => 'entity_reference', 'targetType' => 'media', 'type' => 'entity_reference'],
    ['name' => 'field_image', 'kind' => 'complex', 'type' => 'image'],
    ['name' => 'field_doc', 'kind' => 'entity_reference', 'targetType' => 'media', 'type' => 'entity_reference'],
  ];
  $check('detect prefers first media reference', PublishInfoBuilder::detectFeaturedField($catalog, [], 'article') === 'field_photo');
  $check('detect override wins', PublishInfoBuilder::detectFeaturedField($catalog, ['article' => 'field_image'], 'article') === 'field_image');
  $check('detect override none disables', PublishInfoBuilder::detectFeaturedField($catalog, ['article' => FALSE], 'article') === NULL);
  $check('detect falls back to image field', PublishInfoBuilder::detectFeaturedField([['name' => 'field_image', 'kind' => 'complex', 'type' => 'image']], [], 'article') === 'field_image');
  $check('detect null when nothing matches', PublishInfoBuilder::detectFeaturedField([['name' => 'field_x', 'kind' => 'text', 'type' => 'string']], [], 'article') === NULL);

  exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Run it, expect failure**

```powershell
php tests\check-publish-info.php
```

Expected: PHP fatal (failed to open stream / class missing), exit non-zero.

- [ ] **Step 3: Implement the class shell + helpers**

`src/Bridge/PublishInfoBuilder.php`:

```php
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
```

- [ ] **Step 4: Run the check, expect pass**

```powershell
php tests\check-publish-info.php
php -l src\Bridge\PublishInfoBuilder.php
```

Expected: all `ok`, exit 0.

- [ ] **Step 5: Wire into CI**

`.github/workflows/ci.yml` — after the "Field serializer self-check" step add:

```yaml
      - name: Publish info self-check
        run: php tests/check-publish-info.php
```

- [ ] **Step 6: Commit**

```powershell
git add src/Bridge/PublishInfoBuilder.php tests/check-publish-info.php .github/workflows/ci.yml
git commit -m "feat: pure featured-media detection and override parsing helpers"
```

---

### Task 3: PublishInfoBuilder service + payload + config

**Files:**
- Modify: `src/Bridge/PublishInfoBuilder.php` (replace the `build()` stub with the full implementation)
- Modify: `gutenberg_next.services.yml`
- Modify: `gutenberg_next.module` (payload attachment)
- Modify: `src/Form/SettingsForm.php`
- Modify: `config/schema/gutenberg_next.schema.yml`
- Modify: `config/install/gutenberg_next.settings.yml`

**Interfaces:**
- Consumes: Task 2 helpers; the 0.2 catalog entries (`name`, `label`, `kind`, `type`, `targetType`, `value`); the 0.2 `gutenberg_next_entity_autocomplete_url($entity, $field_name)` module function.
- Produces: `drupalSettings.gutenbergNext.publish` with the exact shape:

```js
publish = {
  status: { published: bool },
  author: { id: int, name: string },
  alias: string|null,
  moderation: null | { state: string, states: { <id>: <label> } },
  scheduler: null | { publishOn: int|null, unpublishOn: int|null },
  featuredMedia: null | { field: string, label: string, value: [{id, label}], autocompleteUrl: string|null },
}
```

Config keys added: `featured_media_overrides` (string, default `''`).

- [ ] **Step 1: Implement the builder**

Replace the whole `src/Bridge/PublishInfoBuilder.php` with:

```php
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
      if ($workflow->getType() !== 'content_moderation') {
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
          'label' => $candidate['label'] ?? $field_name,
          'value' => $candidate['value'] ?? [],
        ];
      }
    }
    return NULL;
  }

}
```

- [ ] **Step 2: Register the service**

`gutenberg_next.services.yml` — append:

```yml
  gutenberg_next.publish_info_builder:
    class: Drupal\gutenberg_next\Bridge\PublishInfoBuilder
    arguments: ['@module_handler', '@entity_type.manager', '@path_alias.manager']
```

- [ ] **Step 3: Attach the payload in form_alter**

In `gutenberg_next.module`, inside `gutenberg_next_form_alter`, immediately after the `foreach ($fields as &$field) { ... } unset($field);` block from 0.2, add:

```php
  /** @var \Drupal\gutenberg_next\Bridge\PublishInfoBuilder $publish_builder */
  $publish_builder = \Drupal::service('gutenberg_next.publish_info_builder');
  $publish = $publish_builder->build($entity, $fields);
  if (isset($publish['featuredMedia']['field'])) {
    $publish['featuredMedia']['autocompleteUrl'] = gutenberg_next_entity_autocomplete_url($entity, $publish['featuredMedia']['field']);
  }
```

and add this entry to the `drupalSettings['gutenbergNext']` array (after the `'bindings'` entry):

```php
    'publish' => $publish,
```

- [ ] **Step 4: Config surface**

`config/schema/gutenberg_next.schema.yml` — after `field_bindings`:

```yml
    featured_media_overrides:
      type: string
      label: 'Featured media overrides'
```

`config/install/gutenberg_next.settings.yml` — append:

```yml
featured_media_overrides: ''
```

`src/Form/SettingsForm.php` — in the `integration` details, after the `autosave_fields` element:

```php
    $form['integration']['featured_media_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Featured media overrides'),
      '#default_value' => $config->get('featured_media_overrides'),
      '#description' => $this->t('One "content_type: field_name" per line; "content_type: none" disables featured media for that type. Leave empty to auto-detect the first media or image field.'),
    ];
```

and in `submitForm()`, after the `autosave_fields` line:

```php
      ->set('featured_media_overrides', (string) $form_state->getValue('featured_media_overrides'))
```

- [ ] **Step 5: Verify on the demo site**

```powershell
php -l src\Bridge\PublishInfoBuilder.php
php -l gutenberg_next.module
php -l src\Form\SettingsForm.php
php tests\check-publish-info.php
php tests\check-field-catalog.php
php tests\check-field-serializer.php
```

All green required. Then robocopy refresh + `drush cr` (Global Constraints), rebuild the jar if stale, and:

```powershell
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/1/edit
foreach ($c in @('"publish":{', '"moderation":{', '"scheduler":{', '"featuredMedia":{', '"field_photo"', '"In review"')) { if ($page -match [regex]::Escape($c)) { "OK  $c" } else { "FAIL $c" } }
```

Expected: all OK (node 1 is moderated by gnt_editorial — the "In review" state label proves the workflow states landed in the payload, scheduler enabled for the bundle, field_photo auto-detected as featured media). Also fetch the add form and assert `"publish":{` appears there with `"moderation":{` present and `"alias":null`.

- [ ] **Step 6: Commit**

```powershell
git add src/Bridge/PublishInfoBuilder.php gutenberg_next.services.yml gutenberg_next.module src/Form/SettingsForm.php config/schema/gutenberg_next.schema.yml config/install/gutenberg_next.settings.yml
git commit -m "feat: publish info builder and editor publish payload"
```

---

### Task 4: Bridge widget helper refactor

**Files:**
- Modify: `js/editor-bridge.js` (add `findWidgetRoot` + `setWidgetValue`)
- Modify: `js/data-store.js` (delegate to the bridge helpers)

**Interfaces:**
- Consumes: nothing new.
- Produces: `window.GutenbergNext.findWidgetRoot(fieldName) -> Element|null` (wrapper-exact → exact → prefix precedence, same as the 0.2 widgetRoot) and `window.GutenbergNext.setWidgetValue(element, value)` (checkbox-aware native setter + input/change events). Task 5 builds all its widget writes on these two.

- [ ] **Step 1: Add the helpers to editor-bridge.js**

In `js/editor-bridge.js`, after the existing `API.focusDrupalField` definition, add:

```js
  API.findWidgetRoot = function (fieldName) {
    const selectorName = String(fieldName).replaceAll('_', '-');
    return (
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '-wrapper"]') ||
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '"]') ||
      document.querySelector('[data-drupal-selector^="edit-' + selectorName + '-"]')
    );
  };

  API.setWidgetValue = function (element, value) {
    if (!element) {
      return;
    }
    if (element.type === 'checkbox') {
      element.checked = Boolean(value);
      element.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    const proto = Object.getPrototypeOf(element);
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) {
      descriptor.set.call(element, value);
    }
    else {
      element.value = value;
    }
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  };
```

- [ ] **Step 2: Delegate from data-store.js**

In `js/data-store.js`, replace the body of `widgetRoot` with:

```js
  function widgetRoot(fieldName) {
    const api = window.GutenbergNext;
    if (api && api.findWidgetRoot) {
      return api.findWidgetRoot(fieldName);
    }
    const selectorName = String(fieldName).replaceAll('_', '-');
    return (
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '-wrapper"]') ||
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '"]') ||
      document.querySelector('[data-drupal-selector^="edit-' + selectorName + '-"]')
    );
  }
```

and replace the body of `setNativeValue` with:

```js
  function setNativeValue(input, value) {
    const api = window.GutenbergNext;
    if (api && api.setWidgetValue) {
      api.setWidgetValue(input, value);
      return;
    }
    const proto = Object.getPrototypeOf(input);
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) {
      descriptor.set.call(input, value);
    }
    else {
      input.value = value;
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
```

Keep every other line of both files unchanged.

- [ ] **Step 3: Verify**

```powershell
node --check js\editor-bridge.js
node --check js\data-store.js
node --check js\field-panel.js
node --check js\bindings.js
node --check js\editor-shell.js
```

All must pass. Then robocopy refresh + `drush cr`, and confirm the served aggregate still contains the store markers:

```powershell
$page = curl.exe -s -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/1/edit
$js = ([regex]::Match($page, 'src="([^"]+\.js\?[^"]*)"')).Groups[1].Value
# if the page aggregates, fetch each listed script URL and assert one contains both markers:
#   'findWidgetRoot' and 'gutenberg-next/fields'
```

(Fetch the script URLs found in the page HTML; record which one contains both markers.)

- [ ] **Step 4: Commit**

```powershell
git add js/editor-bridge.js js/data-store.js
git commit -m "refactor: canonical widget helpers in the editor bridge"
```

---

### Task 5: Pre-publish panel, header button, save guard

**Files:**
- Create: `js/pre-publish.js`
- Modify: `gutenberg_next.libraries.yml`
- Modify: `css/editor-shell.css`

**Interfaces:**
- Consumes: `drupalSettings.gutenbergNext.publish` (Task 3 shape), `window.GutenbergNext.findWidgetRoot`/`setWidgetValue`/`focusDrupalField` (Tasks 1/4 of 0.2 + Task 4 here), the 0.2 store `gutenberg-next/fields` (selectors `isReady`, `getFields`), `wp.hooks` filter `editor.__unstableSavePost`, `wp.data` `core/editor` actions `togglePublishSidebar` + selector `isPublishSidebarOpened`.
- Produces: plugin `gutenberg-next-pre-publish` (PluginPrePublishPanel in sidebar mode, PluginDocumentSettingPanel fallback), body class `gutenberg-next-publish-open` while the publish sidebar is open, header button `.gutenberg-next-publish-toggle`, and the save-blocking guard.

Widget roots (verified in Task 1 Step 5; the standard scheme below is what the code targets — adjust inner selectors ONLY if Task 1's recorded list contradicts them, and record any adjustment):

| Control | Root via `findWidgetRoot(...)` | Inner target |
|---|---|---|
| Status | `status` | `input[type="checkbox"]` |
| Moderation | `moderation_state` | `select` |
| Publish on | `publish_on` | date/time inputs (fallback first text input) |
| Unpublish on | `unpublish_on` | date/time inputs (fallback first text input) |
| URL alias | `path` | `input[type="text"]` |
| Featured media | the detected field name | `input[data-autocomplete-path]` or `select` |

- [ ] **Step 1: Write js/pre-publish.js**

```js
/**
 * Gutenberg Next: Drupal publishing controls in Gutenberg's pre-publish flow.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  const config = drupalSettings.gutenbergNext || {};
  if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.components || !wp.hooks || !wp.i18n || !config.publish) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const publish = config.publish;
  const { createElement, useState } = wp.element;
  const { ToggleControl, SelectControl, TextControl, Button, Notice } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const { select, dispatch, subscribe } = wp.data;
  const { __ } = wp.i18n;

  function bridge() {
    return window.GutenbergNext || {};
  }

  function epochToLocalInput(ts) {
    if (!ts) {
      return '';
    }
    const d = new Date(ts * 1000);
    const pad = function (n) {
      return String(n).padStart(2, '0');
    };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function writeDatetime(fieldName, storageValue) {
    const root = bridge().findWidgetRoot ? bridge().findWidgetRoot(fieldName) : null;
    if (!root) {
      return false;
    }
    const dateInput = root.querySelector('input[type="date"]');
    const timeInput = root.querySelector('input[type="time"]');
    if (!dateInput && !timeInput) {
      const text = root.querySelector('input[type="text"]');
      if (text) {
        bridge().setWidgetValue(text, storageValue || '');
        return true;
      }
      return false;
    }
    const parts = String(storageValue || '').split('T');
    if (dateInput) {
      bridge().setWidgetValue(dateInput, parts[0] || '');
    }
    if (timeInput) {
      bridge().setWidgetValue(timeInput, (parts[1] || '').slice(0, 5));
    }
    return true;
  }

  function PrePublishPanelBody(props) {
    const [state, setState] = useState({
      published: Boolean(publish.status && publish.status.published),
      moderation: publish.moderation ? publish.moderation.state : '',
      publishOn: epochToLocalInput(publish.scheduler ? publish.scheduler.publishOn : null),
      unpublishOn: epochToLocalInput(publish.scheduler ? publish.scheduler.unpublishOn : null),
      alias: publish.alias || '',
      featured: (publish.featuredMedia && publish.featuredMedia.value) || [],
      featuredQuery: '',
      featuredSuggestions: [],
      notice: null,
    });

    function searchMedia(query) {
      if (!publish.featuredMedia || !publish.featuredMedia.autocompleteUrl) {
        return;
      }
      fetch(publish.featuredMedia.autocompleteUrl + '?q=' + encodeURIComponent(query), {
        method: 'GET',
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('media autocomplete failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (matches) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              featuredSuggestions: matches.map(function (match) {
                const idMatch = String(match.value).match(/\((\d+)\)$/);
                return { id: Number(idMatch ? idMatch[1] : 0), label: match.label };
              }).filter(function (item) {
                return item.id > 0;
              }),
            });
          });
        })
        .catch(function () {});
    }

    function selectFeatured(item) {
      const next = item ? [item] : [];
      const root = bridge().findWidgetRoot ? bridge().findWidgetRoot(publish.featuredMedia.field) : null;
      if (root) {
        const input = root.querySelector('input[data-autocomplete-path]');
        const selectEl = root.querySelector('select');
        if (input) {
          bridge().setWidgetValue(input, next.map(function (i) {
            return i.label + ' (' + i.id + ')';
          }).join(', '));
        }
        else if (selectEl) {
          bridge().setWidgetValue(selectEl, next.length ? String(next[0].id) : '');
        }
      }
      setState(function (prev) {
        return Object.assign({}, prev, { featured: next, featuredQuery: '', featuredSuggestions: [] });
      });
    }

    const sections = [];
    const statusRoot = bridge().findWidgetRoot ? bridge().findWidgetRoot('status') : null;

    if (statusRoot && statusRoot.querySelector('input[type="checkbox"]')) {
      sections.push(createElement(ToggleControl, {
        key: 'status',
        label: __('Published'),
        checked: state.published,
        onChange: function (checked) {
          bridge().setWidgetValue(statusRoot.querySelector('input[type="checkbox"]'), checked);
          setState(function (prev) {
            return Object.assign({}, prev, { published: checked });
          });
        },
      }));
    }

    if (publish.moderation) {
      sections.push(createElement(SelectControl, {
        key: 'moderation',
        label: __('Workflow state'),
        value: state.moderation,
        options: Object.keys(publish.moderation.states).map(function (id) {
          return { value: id, label: publish.moderation.states[id] };
        }),
        onChange: function (next) {
          const root = bridge().findWidgetRoot ? bridge().findWidgetRoot('moderation_state') : null;
          const selectEl = root && root.querySelector('select');
          if (selectEl) {
            bridge().setWidgetValue(selectEl, next);
            setState(function (prev) {
              return Object.assign({}, prev, { moderation: next });
            });
          }
        },
      }));
    }

    if (publish.scheduler) {
      sections.push(createElement(TextControl, {
        key: 'publish-on',
        type: 'datetime-local',
        label: __('Publish on'),
        value: state.publishOn,
        onChange: function (next) {
          setState(function (prev) {
            return Object.assign({}, prev, { publishOn: next });
          });
          writeDatetime('publish_on', next ? next + ':00' : '');
        },
      }));
      sections.push(createElement(TextControl, {
        key: 'unpublish-on',
        type: 'datetime-local',
        label: __('Unpublish on'),
        value: state.unpublishOn,
        onChange: function (next) {
          setState(function (prev) {
            return Object.assign({}, prev, { unpublishOn: next });
          });
          writeDatetime('unpublish_on', next ? next + ':00' : '');
        },
      }));
    }

    sections.push(createElement(TextControl, {
      key: 'alias',
      label: __('URL alias'),
      value: state.alias,
      onChange: function (next) {
        setState(function (prev) {
          return Object.assign({}, prev, { alias: next });
        });
        const root = bridge().findWidgetRoot ? bridge().findWidgetRoot('path') : null;
        const input = root && root.querySelector('input[type="text"]');
        if (input) {
          bridge().setWidgetValue(input, next);
        }
      },
    }));

    if (publish.featuredMedia) {
      sections.push(createElement(
        'div',
        { key: 'featured', className: 'gutenberg-next-featured-media' },
        createElement('p', { className: 'components-base-control__label' }, __('Featured media')),
        createElement('p', null, state.featured.length
          ? state.featured.map(function (item) { return item.label; }).join(', ')
          : __('None')),
        createElement(TextControl, {
          placeholder: __('Search media…'),
          value: state.featuredQuery,
          onChange: function (next) {
            setState(function (prev) {
              return Object.assign({}, prev, { featuredQuery: next });
            });
            searchMedia(next);
          },
        }),
        state.featuredSuggestions.length ? createElement(
          'ul',
          { className: 'gutenberg-next-featured-suggestions' },
          state.featuredSuggestions.map(function (item) {
            return createElement('li', { key: item.id },
              createElement(Button, {
                variant: 'link',
                onClick: function () {
                  selectFeatured(item);
                },
              }, item.label));
          }),
        ) : null,
        state.featured.length ? createElement(Button, {
          variant: 'secondary',
          size: 'compact',
          onClick: function () {
            selectFeatured(null);
          },
        }, __('Clear')) : null,
      ));
    }

    if (publish.author && publish.author.name) {
      sections.push(createElement(
        'p',
        { key: 'author', className: 'gutenberg-next-prepublish-author' },
        __('By') + ' ' + publish.author.name + ' ',
        createElement(Button, {
          variant: 'link',
          onClick: function () {
            const api = bridge();
            const focused = api.focusDrupalField && api.focusDrupalField('uid');
            if (!focused) {
              const author = document.querySelector('#edit-author, [data-gutenberg-panel="author"]');
              if (author) {
                author.scrollIntoView({ behavior: 'smooth', block: 'center' });
              }
            }
          },
        }, __('Edit')),
      ));
    }

    const fields = props.fields || {};
    const fieldNames = Object.keys(fields);
    if (fieldNames.length) {
      sections.push(createElement(
        'div',
        { key: 'fields' },
        createElement('p', { className: 'components-base-control__label' }, __('Drupal fields')),
        createElement(
          'ul',
          { className: 'gutenberg-next-prepublish-fields' },
          fieldNames.map(function (name) {
            const field = fields[name];
            const problems = [];
            if (field.invalid) {
              problems.push(field.invalid.message);
            }
            if (field.required && (field.value === '' || field.value === null || field.value === undefined ||
              (Array.isArray(field.value) && field.value.length === 0))) {
              problems.push(__('Required'));
            }
            return createElement('li', { key: name },
              createElement(Button, {
                variant: problems.length ? 'secondary' : 'link',
                onClick: function () {
                  const api = bridge();
                  if (!api.focusDrupalField || !api.focusDrupalField(name)) {
                    setState(function (prev) {
                      return Object.assign({}, prev, { notice: __('The form widget for this field is not available in the editor.') });
                    });
                  }
                },
              }, field.label + (problems.length ? ' — ' + problems.join('; ') : '')));
          }),
        ),
      ));
    }

    sections.push(createElement(
      'div',
      { key: 'save', className: 'gutenberg-next-prepublish-save' },
      state.notice ? createElement(Notice, { status: 'warning', isDismissible: false }, state.notice) : null,
      createElement(Button, {
        variant: 'primary',
        onClick: function () {
          const submit = document.querySelector('#edit-submit');
          if (submit) {
            submit.click();
          }
        },
      }, __('Save')),
    ));

    return createElement('div', { className: 'gutenberg-next-prepublish-content' }, sections);
  }

  const PrePublishContent = wp.data.withSelect(function (selectFn) {
    const store = selectFn(STORE_NAME);
    return {
      fields: store && store.isReady && store.isReady() ? store.getFields() : {},
    };
  })(PrePublishPanelBody);

  // Save guard: block the editor save path while the field store knows
  // about invalid values. Drupal's own validation remains authoritative on
  // submit; this only stops the round-trip before it starts.
  wp.hooks.addFilter('editor.__unstableSavePost', 'gutenberg-next/pre-publish-guard', function (pending) {
    const store = select(STORE_NAME);
    if (store && store.isReady && store.isReady()) {
      const fields = store.getFields();
      const invalid = Object.keys(fields)
        .map(function (name) { return fields[name]; })
        .filter(function (field) { return field.invalid; });
      if (invalid.length) {
        return Promise.reject(new Error(__('Fix the Drupal field errors before saving:') + ' ' + invalid[0].label));
      }
    }
    return pending;
  });

  function insertHeaderButton() {
    if (document.querySelector('.gutenberg-next-publish-toggle')) {
      return true;
    }
    const target = document.querySelector('.gutenberg-header-settings') || document.querySelector('.editor-header__settings');
    if (!target) {
      return false;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'gutenberg-next-publish-toggle button';
    button.textContent = __('Publish…');
    button.addEventListener('click', function () {
      dispatch('core/editor').togglePublishSidebar();
    });
    target.prepend(button);
    return true;
  }

  function registerPrePublish() {
    if (window.__gutenbergNextPrePublishRegistered) {
      return true;
    }
    const editorActions = wp.data.dispatch('core/editor');
    const editorSelectors = wp.data.select('core/editor');
    const sidebarMode = Boolean(
      editorActions && typeof editorActions.togglePublishSidebar === 'function' &&
      editorSelectors && typeof editorSelectors.isPublishSidebarOpened === 'function' &&
      editorApi && editorApi.PluginPrePublishPanel,
    );

    if (sidebarMode) {
      wp.plugins.registerPlugin('gutenberg-next-pre-publish', {
        render: function () {
          return createElement(editorApi.PluginPrePublishPanel, {
            name: 'gutenberg-next-pre-publish',
            title: 'Drupal publishing',
          }, createElement(PrePublishContent));
        },
      });

      let lastOpen = null;
      subscribe(function () {
        const store = wp.data.select('core/editor');
        if (!store || typeof store.isPublishSidebarOpened !== 'function') {
          return;
        }
        const open = store.isPublishSidebarOpened();
        if (open !== lastOpen) {
          lastOpen = open;
          document.body.classList.toggle('gutenberg-next-publish-open', open);
        }
      });

      if (!insertHeaderButton()) {
        const observer = new MutationObserver(function () {
          if (insertHeaderButton()) {
            observer.disconnect();
          }
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.setTimeout(function () {
          observer.disconnect();
        }, 15000);
      }
    }
    else if (editorApi && editorApi.PluginDocumentSettingPanel) {
      wp.plugins.registerPlugin('gutenberg-next-pre-publish', {
        render: function () {
          return createElement(editorApi.PluginDocumentSettingPanel, {
            name: 'gutenberg-next-pre-publish',
            title: 'Drupal publishing',
          }, createElement(PrePublishContent));
        },
      });
    }
    else {
      return false;
    }

    window.__gutenbergNextPrePublishRegistered = true;
    return true;
  }

  Drupal.behaviors.gutenbergNextPrePublish = {
    attach: function () {
      once('gutenberg-next-pre-publish', 'body').forEach(function () {
        if (registerPrePublish()) {
          return;
        }
        // The core/editor store registers after the editor boots; retry
        // briefly before giving up silently.
        let attempts = 0;
        const timer = window.setInterval(function () {
          attempts += 1;
          if (registerPrePublish() || attempts >= 20) {
            window.clearInterval(timer);
          }
        }, 500);
      });
    },
  };
})(Drupal, drupalSettings, once);
```

- [ ] **Step 2: Register the library file**

`gutenberg_next.libraries.yml` — after `js/bindings.js: {}`:

```yml
    js/pre-publish.js: {}
```

- [ ] **Step 3: CSS additions**

Append to `css/editor-shell.css`:

```css
/* Unpark the editor actions region while the Drupal publish flow is open
   (upstream parks it offscreen). */
.gutenberg-next-publish-open .interface-interface-skeleton__actions {
  position: static;
  top: auto;
}

/* WP's own publish button only triggers the non-persisting mock save. */
.gutenberg-next-enabled .editor-post-publish-button,
.gutenberg-next-enabled .editor-post-publish-panel__toggle {
  display: none;
}

.gutenberg-next-publish-toggle {
  margin-inline-end: 8px;
}

ul.gutenberg-next-prepublish-fields,
ul.gutenberg-next-featured-suggestions {
  margin: 0;
  padding: 0;
  list-style: none;
}

.gutenberg-next-prepublish-save {
  margin-top: 16px;
}
```

- [ ] **Step 4: Verify**

```powershell
node --check js\pre-publish.js
php tests\check-publish-info.php
```

Then robocopy refresh + `drush cr`, rebuild the jar if stale, fetch the edit page, and assert the served JS contains the new markers (`gutenberg-next-pre-publish`, `editor.__unstableSavePost`, `gutenberg-next-publish-toggle`) using the same script-URL extraction as Task 4 Step 3. Record evidence.

- [ ] **Step 5: Commit**

```powershell
git add js/pre-publish.js gutenberg_next.libraries.yml css/editor-shell.css
git commit -m "feat: Drupal publishing controls in the Gutenberg pre-publish flow"
```

---

### Task 6: Release tasks, docs, final verification, push

**Files:**
- Modify: `gutenberg_next.info.yml` (version `0.3.0-alpha1`)
- Modify: `composer.json` (version `0.3.0-alpha1`)
- Modify: `CHANGELOG.md` (0.3.0-alpha1 section)
- Modify: `docs/ROADMAP.md` (tick the seven 0.3 items)
- Modify: `README.md` (feature bullets)
- Modify: `docs/TESTING.md` (0.3 browser checklist)

**Interfaces:**
- Consumes: everything from Tasks 1-5.

- [ ] **Step 1: Version bumps**

`gutenberg_next.info.yml`:

```yml
version: '0.3.0-alpha1'
```

`composer.json` — change `"version": "0.2.0-alpha1"` to `"version": "0.3.0-alpha1"` (in `extra.drupal`).

- [ ] **Step 2: CHANGELOG**

Add above the 0.2.0-alpha1 section:

```markdown
## 0.3.0-alpha1 - 2026-08-17

### Added

- Gutenberg-native pre-publish flow: a publish sidebar panel with Drupal status, workflow state, scheduling, URL alias, featured media, author and field summaries.
- Content Moderation workflow states in the editor (feature-detected; writes through the moderation widget).
- Scheduled publishing through the Scheduler module (feature-detected; writes publish_on/unpublish_on widgets).
- Featured media integration with per-bundle auto-detection and config overrides.
- Save guard blocking the editor save path while Drupal fields are invalid.
- Canonical widget helpers (findWidgetRoot/setWidgetValue) in the editor bridge, shared by the field store and the publishing controls.
```

- [ ] **Step 3: ROADMAP ticks**

In `docs/ROADMAP.md`, change the seven 0.3 checkboxes from `- [ ]` to `- [x]`.

- [ ] **Step 4: README update**

Append to the "What it does today" bullet list:

```markdown
- Adds a Gutenberg-native pre-publish flow with Drupal status, Content Moderation workflow states, Scheduler dates, URL alias, featured media and author summaries.
- Blocks editor saves while Drupal fields are invalid; Drupal's own validation stays authoritative.
```

- [ ] **Step 5: TESTING.md additions**

Append to `docs/TESTING.md`:

```markdown
## 0.3 publishing parity checklist

1. Open a gnt_article node edit form; confirm the "Publish…" button appears in the editor header.
2. Click it; confirm the publish sidebar opens with Drupal publishing sections.
3. Change the workflow state; save; confirm the node's moderation state changed.
4. Set a future "Publish on" date; save; confirm publish_on is stored (scheduler).
5. Edit the URL alias in the panel; save; confirm the alias persists.
6. Search and pick a media item for featured media; save; confirm the field value persists.
7. Clear a required field in the Drupal fields panel, then try to save from the publish panel; confirm the save is blocked with an error naming the field.
8. On a moderated node confirm the Published toggle is absent (workflow owns publishing) and everything else still works.
9. Confirm taxonomy term fields (Topics) still edit via the Drupal fields panel autocomplete.
```

- [ ] **Step 6: Full local verification**

In the repo:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l gutenberg_next.module
node --check js\editor-bridge.js
node --check js\editor-shell.js
node --check js\data-store.js
node --check js\field-panel.js
node --check js\bindings.js
node --check js\pre-publish.js
php tests\check-field-catalog.php
php tests\check-field-serializer.php
php tests\check-publish-info.php
```

On the demo site (robocopy refresh + `drush cr` first): re-run Task 3 Step 5's payload checks and the Task 1 Step 6 autosave round-trip once more; record outputs. The browser checklist from Step 5 is interactive — state clearly in the report that it was not executed and leave it for the user.

- [ ] **Step 7: Commit and push**

```powershell
git add -A
git status --short
git commit -m "feat: 0.3.0-alpha1 publishing parity release"
git push -u origin 0.3-publishing-parity
```

Then watch CI on the branch:

```powershell
gh run watch --repo bfrye26/drupal-gutenberg-next --exit-status (gh run list --repo bfrye26/drupal-gutenberg-next --branch 0.3-publishing-parity --workflow ci.yml --limit 1 --json databaseId --jq ".[0].databaseId")
```

Expected: CI green (syntax, composer validate, all three self-checks). If CI fails, fix in this task and re-verify.

---

## Self-review notes

- Spec coverage: 4.1/4.2 builder + pure helpers (Tasks 2-3), 4.3 payload + config (Task 3), 5.1 bridge refactor (Task 4), 5.2 panel/registration/header button/sidebar visibility/WP-button hiding/save guard (Task 5), 5.3 widget targets (Task 5 table + Task 1 selector recording), 6 data flows (Tasks 3-5 combined), 7 feature-detection matrix (Task 3 NULL branches + Task 5 render-time checks), 8 error/security (no new routes; guard reuses 0.2 invalid state), 9 config/versioning/release (Tasks 3 + 6), 10 testing (Tasks 1, 2, 3, 6), 11 done definition (Task 6 + merge), 12 out-of-scope (nothing planned).
- Type consistency: `parseOverrides`/`detectFeaturedField` signatures identical in Tasks 2-3; payload key names (`status.published`, `moderation.state`/`states`, `scheduler.publishOn`/`unpublishOn`, `featuredMedia.field`/`label`/`value`/`autocompleteUrl`) identical across Tasks 3 and 5; bridge helper names `findWidgetRoot`/`setWidgetValue` identical in Tasks 4 and 5; store name `gutenberg-next/fields` and plugin name `gutenberg-next-pre-publish` consistent.
- Known verification points deferred to execution (recorded, not placeholders): exact scheduler widget input types (Task 1 Step 5 records them; Task 5's writeDatetime handles date/time inputs with a text-input fallback), content_moderation workflow plugin method availability (Task 1 Step 3 carries the configuration-array fallback), publish-sidebar un-park selectors (Task 5 CSS targets the researched class names; the site check in Task 5 Step 4 confirms the markers are served).
