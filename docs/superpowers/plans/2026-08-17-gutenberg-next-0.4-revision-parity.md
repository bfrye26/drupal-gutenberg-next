# Gutenberg Next 0.4 — Revision Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the 0.4 revision-parity milestone: an in-editor revision browser with author/message metadata, rendered side-by-side revision comparison, and restore via core's revert form.

**Architecture:** `form_alter` exposes a lightweight `drupalSettings.gutenbergNext.revisions` block (enabled/listUrl/revertUrlBase). `js/revisions.js` renders a "Revisions" document panel that fetches a revision list from a read-only GET route, compares two selected revisions in a modal using server-rendered revision HTML from a second GET route (access-gated with core's revision-view semantics), and restores via jump-out to core's revert form. No write endpoints, no new permissions.

**Tech Stack:** Drupal 11 site, Drupal core ^10.3||^11 module (PHP >=8.1), Drupal Gutenberg 4.0.x (`@wordpress/*` globals), vanilla JS (no build step), MySQL, Windows PowerShell + curl + drush for verification.

**Spec:** `docs/superpowers/specs/2026-08-17-gutenberg-next-0.4-revision-parity-design.md`

## Global Constraints

- Repo root: `C:\Users\User\Downloads\gutenberg-next-0.1.0-alpha1\gutenberg_next`. Work on branch `0.4-revision-parity` (create at execution start: `git switch -c 0.4-revision-parity`); merge/push happens after execution.
- Demo site: `C:\laragon\www\Drupal-Test-2`, URL `http://drupal-test-2.test:8080/`, PHP CLI `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, Composer `C:\laragon\bin\composer\composer.phar`, drush `& $php vendor\drush\drush\drush.php <args>` from the site root, cookie jar `C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar`.
- The site's module copy is NOT live: before any site verification run `robocopy <repo> <site>\web\modules\custom\gutenberg_next /MIR /XD .git .superpowers /NJH /NJS` then `& $php vendor\drush\drush\drush.php cr`. If the web returns 500s with stale DI errors afterwards, delete cache_container rows via `drush php:script` (temp file: `\Drupal::database()->delete('cache_container')->execute();`) and re-request.
- PowerShell 5.1 strips double quotes inside `php:eval` strings — always use `drush php:script <tempfile.php>` (write PHP to a temp file under `C:\Users\User\AppData\Local\Temp\opencode\` first). When writing temp PHP files from PowerShell, use the Write tool or `Set-Content -Encoding UTF8`, never double-quoted heredocs with `$` variables.
- No new composer/npm dependencies in the module; no build step; JS is plain files over `window.wp.*` globals with feature-detect gates (0.1-0.3 pattern). Plain `fetch` with `credentials: 'same-origin'` (upstream replaces `wp.apiFetch`).
- PHP classes: `final`, `declare(strict_types=1)`, constructor-promoted readonly properties; services in `gutenberg_next.services.yml`. JS files: IIFE `(function (Drupal, drupalSettings, once) { 'use strict'; ... })(Drupal, drupalSettings, once)`.
- Routes are GET-only and non-mutating; restore is a jump-out to core's revert form.
- Every task: run `php -l` on touched PHP and `node --check` on touched JS, then commit with a `feat:`/`fix:`/`docs:` message.
- Session jar rebuild when stale: `$login = (& $php vendor\drush\drush\drush.php uli --uri=http://drupal-test-2.test:8080 --no-browser | Select-String -Pattern "http").Matches.Value; curl.exe -s -c C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar -L -o NUL $login`
- Site facts: node 1 is a gnt_article moderated by workflow gnt_editorial (state published); gnt_article creates a new revision per save; admin (uid 1) bypasses permissions — use the admin jar for route smokes.

---

### Task 1: Demo site revision setup (environment, no repo commits)

**Files:** none in the repo. Site dir: `C:\laragon\www\Drupal-Test-2`.

**Interfaces:**
- Produces: node 1 with ≥3 revisions (distinct log messages, at least one body change), core revert route confirmed reachable, admin jar valid.

- [ ] **Step 1: Create two additional revisions of node 1**

Write to `C:\Users\User\AppData\Local\Temp\opencode\t1-revisions.php`:

```php
<?php
$storage = \Drupal::entityTypeManager()->getStorage('node');
$node = $storage->load(1);

$node->setNewRevision(TRUE);
$node->setRevisionLogMessage('0.4 test revision: body edit');
$node->setRevisionCreationTime(\Drupal::time()->getRequestTime() - 3600);
$node->set('body', ['value' => '<!-- wp:paragraph --><p>Revision two body.</p><!-- /wp:paragraph -->', 'format' => 'gutenberg']);
$node->save();

$node = $storage->load(1);
$node->setNewRevision(TRUE);
$node->setRevisionLogMessage('0.4 test revision: final body');
$node->set('body', ['value' => '<!-- wp:paragraph --><p>Final body.</p><!-- /wp:paragraph -->', 'format' => 'gutenberg']);
$node->save();

$vids = array_keys(\Drupal::entityQuery('node')->allRevisions()->condition('nid', 1)->accessCheck(FALSE)->execute());
echo "node1 vids: " . implode(',', $vids) . "\n";
```

Run: `& $php vendor\drush\drush\drush.php php:script C:\Users\User\AppData\Local\Temp\opencode\t1-revisions.php`

Expected: three vids printed. If a save fails on a Content Moderation transition error, add `$node->set('moderation_state', 'published');` before each `save()` and re-run (the script reloads node 1 fresh).

- [ ] **Step 2: Verify revision metadata**

Write to `C:\Users\User\AppData\Local\Temp\opencode\t1-revmeta.php`:

```php
<?php
$storage = \Drupal::entityTypeManager()->getStorage('node');
$vids = array_keys(\Drupal::entityQuery('node')->allRevisions()->condition('nid', 1)->accessCheck(FALSE)->execute());
foreach ($vids as $vid) {
  $r = $storage->loadRevision($vid);
  echo "vid=$vid default=" . var_export($r->isDefaultRevision(), TRUE)
    . " log=[" . $r->getRevisionLogMessage() . "]"
    . " ts=" . $r->getRevisionCreationTime() . "\n";
}
```

Run via `drush php:script`. Expected: exactly one `default=true`, distinct logs for the two new revisions.

- [ ] **Step 3: Confirm the core revert route exists**

Rebuild the jar if stale (Global Constraints one-liner), then:

```powershell
$vids = curl.exe -s -o NUL -w "%{http_code}" -b C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar http://drupal-test-2.test:8080/node/1/revisions
echo "revisions page: $vids"
```

Expected: HTTP 200 (admin can view the revisions overview page).

- [ ] **Step 4: No commit** — environment only; record outputs in the report.

---

### Task 2: formatList pure helper + standalone check

**Files:**
- Create: `src/Bridge/RevisionInfoBuilder.php` (this task: class shell + static `formatList` only)
- Create: `tests/check-revision-info.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: `Drupal\gutenberg_next\Bridge\RevisionInfoBuilder::formatList(array $rows): array` — input rows `{vid, isDefault, timestamp, authorId, authorName, log}` (log/authorName may be missing); output normalized rows newest-first by timestamp then vid, defaults `log ''`, `authorName ''`. Task 3 builds the service around this exact signature.

- [ ] **Step 1: Write the failing check**

`tests/check-revision-info.php`:

```php
<?php

declare(strict_types=1);

/**
 * Self-check for the RevisionInfoBuilder pure helper.
 *
 * Runs without a Drupal installation (the helper is static and the class is
 * never instantiated here). Usage:
 *
 *   php tests/check-revision-info.php
 *
 * Exits non-zero if any check fails.
 */

namespace {
  require __DIR__ . '/../src/Bridge/RevisionInfoBuilder.php';

  use Drupal\gutenberg_next\Bridge\RevisionInfoBuilder;

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $rows = [
    ['vid' => 1, 'isDefault' => FALSE, 'timestamp' => 9000, 'authorId' => 2, 'authorName' => 'Ada', 'log' => 'first'],
    ['vid' => 3, 'isDefault' => TRUE, 'timestamp' => 3000, 'authorId' => 1],
    ['vid' => 2, 'isDefault' => FALSE, 'timestamp' => 3000, 'authorId' => 2, 'authorName' => 'Ada', 'log' => ''],
  ];
  $formatted = RevisionInfoBuilder::formatList($rows);

  $check('newest-first by timestamp', array_column($formatted, 'vid') === [1, 3, 2]);
  $check('timestamp tie broken by vid descending', $formatted[1]['vid'] === 3 && $formatted[2]['vid'] === 2);
  $check('missing log defaults to empty string', $formatted[1]['log'] === '');
  $check('missing authorName defaults to empty string', $formatted[1]['authorName'] === '');
  $check('defaults preserved on complete rows', $formatted[0]['log'] === 'first' && $formatted[0]['authorName'] === 'Ada');
  $check('isDefault cast to bool', $formatted[1]['isDefault'] === TRUE && $formatted[0]['isDefault'] === FALSE);
  $check('empty input returns empty list', RevisionInfoBuilder::formatList([]) === []);

  exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Run it, expect failure**

```powershell
php tests\check-revision-info.php
```

Expected: PHP fatal (failed to open stream / class missing), exit non-zero.

- [ ] **Step 3: Implement the class shell + helper**

`src/Bridge/RevisionInfoBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Builds revision list and rendered-revision payloads for the editor.
 */
final class RevisionInfoBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Builds the revision list payload for an entity.
   *
   * Task 3 fills this in; this task ships the pure helper.
   */
  public function buildList(ContentEntityInterface $entity): array {
    return [];
  }

  /**
   * Builds the rendered-revision payload.
   *
   * Task 3 fills this in; this task ships the pure helper.
   */
  public function buildRevisionView(ContentEntityInterface $revision): array {
    return [];
  }

  /**
   * Normalizes and orders revision rows, newest first.
   *
   * @param array<int, array<string, mixed>> $rows
   *
   * @return array<int, array<string, mixed>>
   */
  public static function formatList(array $rows): array {
    $normalized = [];
    foreach ($rows as $row) {
      $normalized[] = [
        'vid' => (int) ($row['vid'] ?? 0),
        'isDefault' => (bool) ($row['isDefault'] ?? FALSE),
        'timestamp' => (int) ($row['timestamp'] ?? 0),
        'authorId' => (int) ($row['authorId'] ?? 0),
        'authorName' => (string) ($row['authorName'] ?? ''),
        'log' => (string) ($row['log'] ?? ''),
      ];
    }
    usort($normalized, static fn (array $a, array $b): int => [$b['timestamp'], $b['vid']] <=> [$a['timestamp'], $a['vid']]);
    return $normalized;
  }

}
```

- [ ] **Step 4: Run the check, expect pass**

```powershell
php tests\check-revision-info.php
php -l src\Bridge\RevisionInfoBuilder.php
```

Expected: all `ok`, exit 0.

- [ ] **Step 5: Wire into CI**

`.github/workflows/ci.yml` — after the "Publish info self-check" step add:

```yaml
      - name: Revision info self-check
        run: php tests/check-revision-info.php
```

- [ ] **Step 6: Commit**

```powershell
git add src/Bridge/RevisionInfoBuilder.php tests/check-revision-info.php .github/workflows/ci.yml
git commit -m "feat: pure revision list formatting helper"
```

---

### Task 3: RevisionInfoBuilder service + controller + routes + payload

**Files:**
- Modify: `src/Bridge/RevisionInfoBuilder.php` (fill in `buildList` + `buildRevisionView`)
- Create: `src/Controller/RevisionController.php`
- Modify: `gutenberg_next.routing.yml`
- Modify: `gutenberg_next.services.yml`
- Modify: `gutenberg_next.module` (access helper + payload)

**Interfaces:**
- Consumes: Task 2's `formatList`; the 0.2 `gutenberg_next_form_alter` structure (payload block added after the `'publish'` attachment logic).
- Produces:
  - Service `gutenberg_next.revision_info_builder` with `buildList(ContentEntityInterface $entity): array` (formatted rows) and `buildRevisionView(ContentEntityInterface $revision): array` (`{vid, title, html, timestamp, authorName, log}`).
  - Routes `GET /editor/gutenberg-next/revisions/node/{node}` → `{"revisions": [...]}` and `GET /editor/gutenberg-next/revisions/node/{node}/{node_revision}` → view payload.
  - Module function `gutenberg_next_revision_view_access(EntityInterface $entity, AccountInterface $account): bool` mirroring core's NodeRevisionAccessCheck.
  - `drupalSettings.gutenbergNext.revisions = {enabled: true, listUrl, revertUrlBase}` for saved nodes the user can view revisions of; omitted otherwise.

- [ ] **Step 1: Read core's revision access check**

Read `C:\laragon\www\Drupal-Test-2\core\modules\node\src\Access\NodeRevisionAccessCheck.php` (site checkout). Note its exact permission logic. The module function below must mirror it; if core checks more than `view all revisions` + node view access, replicate that logic exactly in `gutenberg_next_revision_view_access` and say so in the report.

- [ ] **Step 2: Fill in the builder**

Replace the two stub methods in `src/Bridge/RevisionInfoBuilder.php` with:

```php
  public function buildList(ContentEntityInterface $entity): array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $vids = array_keys($storage->getQuery()
      ->allRevisions()
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->accessCheck(FALSE)
      ->execute());

    $rows = [];
    foreach ($vids as $vid) {
      $revision = $storage->loadRevision($vid);
      if (!$revision) {
        continue;
      }
      $user = method_exists($revision, 'getRevisionUser') ? $revision->getRevisionUser() : NULL;
      $rows[] = [
        'vid' => (int) $vid,
        'isDefault' => (bool) $revision->isDefaultRevision(),
        'timestamp' => method_exists($revision, 'getRevisionCreationTime') ? (int) $revision->getRevisionCreationTime() : 0,
        'authorId' => $user ? (int) $user->id() : 0,
        'authorName' => $user ? (string) $user->label() : '',
        'log' => method_exists($revision, 'getRevisionLogMessage') ? (string) $revision->getRevisionLogMessage() : '',
      ];
    }

    return self::formatList($rows);
  }

  public function buildRevisionView(ContentEntityInterface $revision): array {
    $user = method_exists($revision, 'getRevisionUser') ? $revision->getRevisionUser() : NULL;
    $view_builder = $this->entityTypeManager->getViewBuilder($revision->getEntityTypeId());
    $build = $view_builder->view($revision, 'full');
    $html = (string) $this->renderer->renderPlain($build);

    return [
      'vid' => (int) $revision->getRevisionId(),
      'title' => (string) $revision->label(),
      'html' => $html,
      'timestamp' => method_exists($revision, 'getRevisionCreationTime') ? (int) $revision->getRevisionCreationTime() : 0,
      'authorName' => $user ? (string) $user->label() : '',
      'log' => method_exists($revision, 'getRevisionLogMessage') ? (string) $revision->getRevisionLogMessage() : '',
    ];
  }
```

Keep the class imports/constructor/formatList from Task 2 unchanged.

- [ ] **Step 3: Write the controller**

`src/Controller/RevisionController.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\gutenberg_next\Bridge\RevisionInfoBuilder;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only revision payloads for the editor revision browser.
 */
final class RevisionController extends ControllerBase {

  public function __construct(
    private readonly RevisionInfoBuilder $revisionInfoBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('gutenberg_next.revision_info_builder'));
  }

  /**
   * Custom access for the revision list route.
   *
   * Mirrors core's NodeRevisionAccessCheck semantics (verified against the
   * site's core source during implementation).
   */
  public function revisionListAccess(Node $node, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(gutenberg_next_revision_view_access($node, $account))
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($node);
  }

  public function revisionList(Node $node): JsonResponse {
    return new JsonResponse([
      'revisions' => $this->revisionInfoBuilder->buildList($node),
    ]);
  }

  public function revisionView(Node $node, Node $node_revision): JsonResponse {
    return new JsonResponse($this->revisionInfoBuilder->buildRevisionView($node_revision));
  }

}
```

- [ ] **Step 4: Routes**

Append to `gutenberg_next.routing.yml`:

```yml
gutenberg_next.revision_list:
  path: '/editor/gutenberg-next/revisions/node/{node}'
  defaults:
    _controller: '\Drupal\gutenberg_next\Controller\RevisionController::revisionList'
    _format: 'json'
  methods: [GET]
  requirements:
    _custom_access: '\Drupal\gutenberg_next\Controller\RevisionController::revisionListAccess'
  options:
    no_cache: TRUE
    parameters:
      node:
        type: entity:node

gutenberg_next.revision_view:
  path: '/editor/gutenberg-next/revisions/node/{node}/{node_revision}'
  defaults:
    _controller: '\Drupal\gutenberg_next\Controller\RevisionController::revisionView'
    _format: 'json'
  methods: [GET]
  requirements:
    _access_node_revision: 'view'
  options:
    no_cache: TRUE
    parameters:
      node:
        type: entity:node
      node_revision:
        type: entity_revision:node
```

- [ ] **Step 5: Service registration**

`gutenberg_next.services.yml` — append:

```yml
  gutenberg_next.revision_info_builder:
    class: Drupal\gutenberg_next\Bridge\RevisionInfoBuilder
    arguments: ['@entity_type.manager', '@renderer']
```

- [ ] **Step 6: Module access helper + payload**

In `gutenberg_next.module`, add imports at the top (merge into the existing `use` block):

```php
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
```

Append the access helper function at the end of the file:

```php
/**
 * Revision-view access check mirroring core's NodeRevisionAccessCheck.
 */
function gutenberg_next_revision_view_access(EntityInterface $entity, AccountInterface $account): bool {
  if (!$entity->getEntityType()->hasKey('revision')) {
    return FALSE;
  }
  return $account->hasPermission('view all revisions') && $entity->access('view', $account);
}
```

(If Step 1 found core checking additional permissions, mirror them here instead.)

In `gutenberg_next_form_alter`, after the `'publish' => $publish,` line inside the drupalSettings array, add:

```php
    'revisions' => NULL,
```

and immediately after the drupalSettings assignment block (before the closing brace of the function), add:

```php
  if ($entity->id() !== NULL && gutenberg_next_revision_view_access($entity, \Drupal::currentUser())) {
    $form['#attached']['drupalSettings']['gutenbergNext']['revisions'] = [
      'enabled' => TRUE,
      'listUrl' => sprintf('/editor/gutenberg-next/revisions/node/%d', (int) $entity->id()),
      'revertUrlBase' => sprintf('/node/%d/revisions/', (int) $entity->id()),
    ];
  }
  else {
    unset($form['#attached']['drupalSettings']['gutenbergNext']['revisions']);
  }
```

- [ ] **Step 7: Verify locally, then on the site**

```powershell
php -l src\Bridge\RevisionInfoBuilder.php
php -l src\Controller\RevisionController.php
php -l gutenberg_next.module
php tests\check-revision-info.php
php tests\check-field-catalog.php
php tests\check-field-serializer.php
php tests\check-publish-info.php
```

All green required. Then robocopy refresh + `drush cr`, rebuild the jar if stale, and run the smokes:

```powershell
$jar = "C:\Users\User\AppData\Local\Temp\opencode\drupal-test-2.jar"
# list route:
curl.exe -s -b $jar http://drupal-test-2.test:8080/editor/gutenberg-next/revisions/node/1
# view route (use the newest vid from the list response):
curl.exe -s -b $jar http://drupal-test-2.test:8080/editor/gutenberg-next/revisions/node/1/<vid>
# anonymous 403:
curl.exe -s -o NUL -w "%{http_code}" http://drupal-test-2.test:8080/editor/gutenberg-next/revisions/node/1
# unknown vid 404:
curl.exe -s -o NUL -w "%{http_code}" -b $jar http://drupal-test-2.test:8080/editor/gutenberg-next/revisions/node/1/99999
# payload block on the edit form:
$page = curl.exe -s -b $jar http://drupal-test-2.test:8080/node/1/edit
foreach ($c in @('"revisions":{', '"listUrl"', '"revertUrlBase"')) { if ($page -match [regex]::Escape($c)) { "OK  $c" } else { "FAIL $c" } }
```

Expected: list returns ≥3 rows newest-first with author/log/isDefault; view returns non-empty `html`; 403 anonymous; 404 unknown vid; all three payload markers OK. Record outputs.

- [ ] **Step 8: Commit**

```powershell
git add src/Bridge/RevisionInfoBuilder.php src/Controller/RevisionController.php gutenberg_next.routing.yml gutenberg_next.services.yml gutenberg_next.module
git commit -m "feat: revision list and rendered revision endpoints"
```

---

### Task 4: Revisions panel + compare modal

**Files:**
- Create: `js/revisions.js`
- Modify: `gutenberg_next.libraries.yml`
- Modify: `css/editor-shell.css`

**Interfaces:**
- Consumes: `drupalSettings.gutenbergNext.revisions` (`enabled`, `listUrl`, `revertUrlBase`), the Task 3 routes (`GET listUrl` → `{revisions:[{vid,isDefault,timestamp,authorId,authorName,log}]}`; `GET listUrl + '/' + vid` → `{vid,title,html,timestamp,authorName,log}`), `wp.plugins/wp.element/wp.components/wp.i18n`, `PluginDocumentSettingPanel` from `wp.editor || wp.editPost`.
- Produces: plugin `gutenberg-next-revisions` (document panel), compare modal, restore jump-out.

- [ ] **Step 1: Write js/revisions.js**

```js
/**
 * Gutenberg Next: Drupal revision browser and visual comparison.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  const config = drupalSettings.gutenbergNext || {};
  if (!wp || !wp.plugins || !wp.element || !wp.components || !wp.i18n ||
    !config.revisions || !config.revisions.enabled) {
    return;
  }

  const revisionsConfig = config.revisions;
  const { createElement, useState, useEffect } = wp.element;
  const { Button, CheckboxControl, Modal, Notice, Spinner } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const { __ } = wp.i18n;

  function formatDate(ts) {
    if (!ts) {
      return '';
    }
    return new Date(ts * 1000).toLocaleString();
  }

  function RevisionPane(props) {
    const [state, setState] = useState({ loading: true, error: null, data: null });

    useEffect(function () {
      let cancelled = false;
      fetch(revisionsConfig.listUrl + '/' + props.vid, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('revision fetch failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!cancelled) {
            setState({ loading: false, error: null, data: data });
          }
        })
        .catch(function (error) {
          if (!cancelled) {
            setState({ loading: false, error: String(error.message || error), data: null });
          }
        });
      return function () {
        cancelled = true;
      };
    }, [props.vid]);

    if (state.loading) {
      return createElement('div', { className: 'gutenberg-next-revision-pane' }, createElement(Spinner));
    }
    if (state.error) {
      return createElement('div', { className: 'gutenberg-next-revision-pane' },
        createElement(Notice, { status: 'error', isDismissible: false }, state.error));
    }
    const data = state.data;
    return createElement('div', { className: 'gutenberg-next-revision-pane' },
      createElement('h3', null, data.title),
      createElement('p', { className: 'gutenberg-next-revision-meta' },
        formatDate(data.timestamp) + ' — ' + (data.authorName || __('Anonymous')) +
        (data.log ? ' — ' + data.log : '')),
      createElement('div', {
        className: 'gutenberg-next-revision-view',
        dangerouslySetInnerHTML: { __html: data.html },
      }),
    );
  }

  function RevisionsPanel() {
    const [state, setState] = useState({
      loaded: false,
      loading: false,
      error: null,
      revisions: [],
      selected: [],
      compareOpen: false,
    });

    useEffect(function () {
      if (state.loaded || state.loading) {
        return;
      }
      setState(function (prev) {
        return Object.assign({}, prev, { loading: true });
      });
      fetch(revisionsConfig.listUrl, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('revision list failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (payload) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              loaded: true,
              loading: false,
              revisions: payload.revisions || [],
            });
          });
        })
        .catch(function (error) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              loaded: true,
              loading: false,
              error: String(error.message || error),
            });
          });
        });
    }, [state.loaded, state.loading]);

    function toggleSelect(vid) {
      setState(function (prev) {
        let selected = prev.selected.slice();
        if (selected.includes(vid)) {
          selected = selected.filter(function (v) { return v !== vid; });
        }
        else {
          selected.push(vid);
          if (selected.length > 2) {
            selected.shift();
          }
        }
        return Object.assign({}, prev, { selected: selected });
      });
    }

    if (state.loading) {
      return createElement('p', null, __('Loading revisions…'));
    }
    if (state.error) {
      return createElement(Notice, { status: 'error', isDismissible: false }, state.error);
    }
    if (!state.revisions.length) {
      return createElement('p', null, __('No revisions yet.'));
    }

    return createElement('div', { className: 'gutenberg-next-revisions-panel' },
      state.revisions.map(function (revision) {
        return createElement('div', { key: revision.vid, className: 'gutenberg-next-revision-row' },
          createElement(CheckboxControl, {
            checked: state.selected.includes(revision.vid),
            onChange: function () {
              toggleSelect(revision.vid);
            },
          }),
          createElement('div', { className: 'gutenberg-next-revision-info' },
            createElement('strong', null, formatDate(revision.timestamp)),
            revision.isDefault ? createElement('span', { className: 'gutenberg-next-revision-current' }, ' ' + __('Current')) : null,
            createElement('div', null,
              (revision.authorName || __('Anonymous')) + (revision.log ? ' — ' + revision.log : '')),
          ),
          !revision.isDefault ? createElement(Button, {
            variant: 'link',
            onClick: function () {
              window.location = revisionsConfig.revertUrlBase + revision.vid + '/revert';
            },
          }, __('Restore')) : null,
        );
      }),
      createElement(Button, {
        variant: 'secondary',
        disabled: state.selected.length !== 2,
        onClick: function () {
          setState(function (prev) {
            return Object.assign({}, prev, { compareOpen: true });
          });
        },
      }, __('Compare selected')),
      state.compareOpen ? createElement(Modal, {
        title: __('Compare revisions'),
        className: 'gutenberg-next-revision-compare',
        onRequestClose: function () {
          setState(function (prev) {
            return Object.assign({}, prev, { compareOpen: false });
          });
        },
      },
        createElement('div', { className: 'gutenberg-next-revision-compare-grid' },
          createElement(RevisionPane, { vid: state.selected[0] }),
          createElement(RevisionPane, { vid: state.selected[1] }),
        )) : null,
    );
  }

  Drupal.behaviors.gutenbergNextRevisions = {
    attach: function () {
      once('gutenberg-next-revisions', 'body').forEach(function () {
        if (window.__gutenbergNextRevisionsRegistered) {
          return;
        }
        if (!editorApi || !editorApi.PluginDocumentSettingPanel) {
          return;
        }
        wp.plugins.registerPlugin('gutenberg-next-revisions', {
          render: function () {
            return createElement(editorApi.PluginDocumentSettingPanel, {
              name: 'gutenberg-next-revisions',
              title: 'Revisions',
            }, createElement(RevisionsPanel));
          },
        });
        window.__gutenbergNextRevisionsRegistered = true;
      });
    },
  };
})(Drupal, drupalSettings, once);
```

- [ ] **Step 2: Register the library file**

`gutenberg_next.libraries.yml` — after `js/pre-publish.js: {}`:

```yml
    js/revisions.js: {}
```

- [ ] **Step 3: CSS additions**

Append to `css/editor-shell.css`:

```css
.gutenberg-next-revision-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 12px;
}

.gutenberg-next-revision-info {
  flex: 1;
  min-width: 0;
}

.gutenberg-next-revision-current {
  color: var(--wp-admin-theme-color, #007cba);
  font-weight: 600;
}

.gutenberg-next-revision-compare .components-modal__content {
  max-width: none;
  width: 90vw;
}

.gutenberg-next-revision-compare-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.gutenberg-next-revision-view {
  border: 1px solid #ddd;
  padding: 16px;
  overflow: auto;
  max-height: 70vh;
}
```

- [ ] **Step 4: Verify**

```powershell
node --check js\revisions.js
php tests\check-revision-info.php
```

Then robocopy refresh + `drush cr`, rebuild the jar if stale, fetch the edit page, and assert the served aggregate scripts contain the markers `gutenberg-next-revisions`, `revision list failed`, and `Compare selected` (extract script URLs from the page HTML and grep each, as in earlier tasks). Record evidence.

- [ ] **Step 5: Commit**

```powershell
git add js/revisions.js gutenberg_next.libraries.yml css/editor-shell.css
git commit -m "feat: revision browser panel with rendered side-by-side comparison"
```

---

### Task 5: Release tasks, docs, final verification, push

**Files:**
- Modify: `gutenberg_next.info.yml` (version `0.4.0-alpha1`)
- Modify: `composer.json` (version `0.4.0-alpha1`)
- Modify: `CHANGELOG.md` (0.4.0-alpha1 section)
- Modify: `docs/ROADMAP.md` (tick the four 0.4 items)
- Modify: `README.md` (feature bullets)
- Modify: `docs/TESTING.md` (0.4 browser checklist)

**Interfaces:**
- Consumes: everything from Tasks 1-4.

- [ ] **Step 1: Version bumps**

`gutenberg_next.info.yml`:

```yml
version: '0.4.0-alpha1'
```

`composer.json` — change `"version": "0.3.0-alpha1"` to `"version": "0.4.0-alpha1"` (in `extra.drupal`).

- [ ] **Step 2: CHANGELOG**

Add above the 0.3.0-alpha1 section:

```markdown
## 0.4.0-alpha1 - 2026-08-17

### Added

- In-editor Drupal revision browser: newest-first list with author, date, log message and current-revision badge.
- Visual revision comparison: server-rendered side-by-side view of any two revisions in a modal.
- Restore entry point from the revision browser, jumping to Drupal core's revert confirmation form.
- Read-only revision endpoints with core revision-view access semantics.
```

- [ ] **Step 3: ROADMAP ticks**

In `docs/ROADMAP.md`, change the four 0.4 checkboxes from `- [ ]` to `- [x]`.

- [ ] **Step 4: README update**

Append to the "What it does today" bullet list:

```markdown
- Adds an in-editor revision browser with author/date/log metadata, rendered side-by-side revision comparison, and a restore entry point via Drupal's revert form.
```

- [ ] **Step 5: TESTING.md additions**

Append to `docs/TESTING.md`:

```markdown
## 0.4 revision parity checklist

1. Open a node edit form with at least three revisions; confirm the Revisions panel lists them newest-first with author, date and log messages.
2. Confirm the current revision is badged and has no Restore button.
3. Select two revisions; confirm Compare selected enables and opens the modal.
4. Confirm both panes render the revision content (titles, dates, rendered blocks) side by side.
5. Click Restore on an older revision; confirm Drupal's revert confirmation form opens for that revision.
6. Complete the revert; confirm the node content reflects the restored revision.
7. As a user without revision-view access, confirm the Revisions panel does not appear.
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
node --check js\revisions.js
php tests\check-field-catalog.php
php tests\check-field-serializer.php
php tests\check-publish-info.php
php tests\check-revision-info.php
```

On the demo site (robocopy refresh + `drush cr` first): re-run Task 3 Step 7's smokes once more; record outputs. The browser checklist from Step 5 is interactive — state clearly in the report that it was not executed and is left for the user.

- [ ] **Step 7: Commit and push**

```powershell
git add -A
git status --short
git commit -m "feat: 0.4.0-alpha1 revision parity release"
git push -u origin 0.4-revision-parity
```

Then watch CI on the branch:

```powershell
gh run watch --repo bfrye26/drupal-gutenberg-next --exit-status (gh run list --repo bfrye26/drupal-gutenberg-next --branch 0.4-revision-parity --workflow ci.yml --limit 1 --json databaseId --jq ".[0].databaseId")
```

Expected: CI green (syntax, composer validate, all four self-checks). If CI fails, fix in this task and re-verify.

---

## Self-review notes

- Spec coverage: 4.1 builder (Tasks 2-3), 4.2 controller/routes (Task 3), 4.3 payload (Task 3), 5 JS panel/modal/restore (Task 4), 6 data flows (Tasks 3-4 combined), 7 error/security (Task 3 access + Task 4 error states), 8 config/versioning/release (Task 5), 9 testing (Tasks 1, 2, 3, 5), 10 done definition (Task 5 + merge), 11 out-of-scope (nothing planned).
- Type consistency: `formatList` signature identical in Tasks 2-3; payload keys `vid/isDefault/timestamp/authorId/authorName/log` identical across builder, controller, and JS consumers; view payload keys `vid/title/html/timestamp/authorName/log` identical builder → controller → RevisionPane; settings keys `enabled/listUrl/revertUrlBase` identical form_alter → JS; view URL construction `listUrl + '/' + vid` matches the route path.
- Verified-against-core points recorded as explicit steps (not placeholders): Task 3 Step 1 reads the site's NodeRevisionAccessCheck and mirrors it; the route options copy core's `entity.node.revision` parameter upcasting.
