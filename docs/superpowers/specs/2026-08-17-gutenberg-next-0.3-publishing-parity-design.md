# Gutenberg Next 0.3 — Publishing Parity (Design)

Date: 2026-08-17
Status: Approved for planning
Repo: https://github.com/bfrye26/drupal-gutenberg-next

## 1. Goals

Complete the 0.3 roadmap milestone on top of Drupal Gutenberg 4.0.x:

1. Drupal status in Gutenberg pre-publish flow.
2. Scheduled publishing.
3. Author/entity-reference controls.
4. Taxonomy controls.
5. URL alias/permalink integration.
6. Featured media integration.
7. Content Moderation workflow states.

Upstream context (researched, 4.0.x branch):

- The real persistence path is the Drupal form submit; upstream re-clicks
  `#edit-submit` after its `savePost` mock. WP's own publish button only runs
  the mock and never persists.
- Upstream's `drupal-node-settings` plugin already adopts the status/langcode,
  URL alias, author, promotion-options and menu widgets into document-tab
  panels. 0.3 does not re-implement those widgets; it adds the pre-publish
  flow, typed summaries, and the controls upstream lacks.
- `PluginPrePublishPanel` exists in the build but only renders inside the
  publish sidebar, which upstream never opens (its only trigger is parked
  offscreen). `editor.__unstableSavePost` is an awaited filter — rejecting it
  fails the save. `wp.node.status` is an untrustworthy client mock.
- Featured media, scheduler and content-moderation integration do not exist
  upstream at all.

## 2. Hard constraints

- Write path stays form-submit-backed: every 0.3 control writes into the real
  Drupal form widget; Drupal validation and submit stay authoritative. No new
  validation rules are invented client-side.
- Scheduler and Content Moderation surfaces are feature-detected at runtime
  (module present + enabled for the bundle); no hard dependencies.
- No build step: plain JS over `window.wp.*` globals, feature-detect and
  degrade (0.1/0.2 pattern).
- No new REST routes; permissions unchanged.
- Verification against the user's local Drupal 11 site
  `http://drupal-test-2.test:8080/` (`C:\laragon\www\Drupal-Test-2`), which
  gets `drupal/scheduler` + `content_moderation` + a taxonomy field added
  during setup.

## 3. Architecture

```
PHP (form render)
  PublishInfoBuilder ──> drupalSettings.gutenbergNext.publish
                          {status, author, alias, moderation, scheduler, featuredMedia}
                                        │
                                        ▼
                        js/pre-publish.js
      ┌──────────────────┬──────────────────┬─────────────────────┐
      ▼                  ▼                  ▼                     ▼
PluginPrePublishPanel  header button    save guard            widget writes
(native publish        (opens publish   (editor.__unstable-   via GutenbergNext
 sidebar content)       sidebar)         SavePost filter)      bridge helpers
      │                                                           │
      └──────────── writes target ────────────────────────────────┘
                 hidden Drupal widgets (status, moderation_state,
                 publish_on/unpublish_on, path alias, featured field)
                                        │
                                        ▼
                 Drupal form submit (unchanged, authoritative)
```

Author and taxonomy/entity-reference items are delivered as: read-only
summaries + jumps for author (upstream owns the widget), and the 0.2
entity-reference adapter (which already covers taxonomy-term references) plus
pre-publish visibility.

## 4. PHP components

### 4.1 `PublishInfoBuilder` (service `gutenberg_next.publish_info_builder`)

Constructor deps: `module_handler`, `entity_type.manager`, `path_alias.manager`.

`build(ContentEntityInterface $entity, array $catalog_fields): array` returns:

```php
[
  'status' => ['published' => (bool) $entity->isPublished()],
  'author' => ['id' => (int) $owner_id, 'name' => (string) $owner_label],
  'alias' => $alias_or_null,
  'moderation' => NULL | [
    'state' => 'draft',
    'states' => ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'],
  ],
  'scheduler' => NULL | [
    'publishOn' => 1787000000 | NULL,
    'unpublishOn' => 1787100000 | NULL,
  ],
  'featuredMedia' => NULL | [
    'field' => 'field_photo',
    'kind' => 'entity_reference',
    'label' => 'Photo',
    'value' => [['id' => 7, 'label' => 'logo.png']],
    'autocompleteUrl' => '/entity_reference_autocomplete/media/default/<key>',
  ],
]
```

`kind` is the 0.2 catalog kind of the detected field; `value` is `[{id,label}]`
for media entity-reference fields and the complex `{summary, detail}` shape for
image/other fields. The panel offers autocomplete + write only for
`entity_reference` kind; other kinds render a summary plus an "Edit in form"
jump.

Rules:

- `alias`: `path_alias.manager->getAliasByPath('/node/<id>')` for saved nodes;
  NULL when the node is new or the result equals the system path.
- `moderation`: NULL unless `content_moderation` is installed AND its
  moderation-information service says the bundle is moderated. `state` from
  `$entity->moderation_state->value` (fallback the workflow's default state);
  `states` = every state of the applied workflow (id => label) so the editor
  can offer all targets; the workflow is resolved by scanning workflow storage
  for one whose content-moderation type plugin applies to this entity
  type + bundle (exact core API verified during implementation).
- `scheduler`: NULL unless the `scheduler` module is installed AND the node
  type enables publish/unpublish scheduling (third-party settings). Values
  from `publish_on`/`unpublish_on` if those fields carry data.
- `featuredMedia`: field chosen by
  `detectFeaturedField(array $catalog_fields, array $overrides, string $bundle)`
  (static, pure — see 4.2); `label` and `value` taken from the matching
  catalog entry (already serialized by the 0.2 catalog). `autocompleteUrl` is
  NOT produced by the builder — `form_alter` attaches it to
  `publish.featuredMedia` via the 0.2 `gutenberg_next_entity_autocomplete_url()`
  helper, exactly as it does for catalog fields. NULL when detection yields
  nothing or the field is not in the catalog.
- `author`: owner via `$entity->getOwner()` guarded for entities without one
  (falls back to `['id' => 0, 'name' => '']`).

### 4.2 Pure helpers (standalone-check covered)

Static methods on `PublishInfoBuilder`:

- `parseOverrides(string $raw): array` — lines of `bundle: field_name`
  (whitespace tolerant), `bundle: none` maps to FALSE; later duplicates win;
  blank/`#` lines ignored.
- `detectFeaturedField(array $catalog_fields, array $overrides, string $bundle): ?string`
  — override wins (FALSE → NULL); else first catalog entry with
  `kind === 'entity_reference'` and `targetType === 'media'`; else first entry
  with `type === 'image'`; else NULL.

### 4.3 Payload + config

`gutenberg_next_form_alter` adds (fields payload rules unchanged from 0.2):

```js
gutenbergNext.publish = <PublishInfoBuilder output>
```

New config key `featured_media_overrides` (string, default `''`), schema
entry, and a settings-form textarea under "Drupal integration" with
description "One `content_type: field_name` per line; `content_type: none`
disables featured media for that type. Empty = auto-detect the first media or
image field."

## 5. JS components

### 5.1 Bridge refactor (`js/editor-bridge.js`, `js/data-store.js`)

Canonical widget helpers move to the bridge (0.2 pattern, fixes the
selector-duplication risk flagged in the 0.2 final review):

```js
GutenbergNext.findWidgetRoot = function (fieldName) {
  // '[data-drupal-selector="edit-<dashes>-wrapper"]'
  // → '[data-drupal-selector="edit-<dashes>"]'
  // → '[data-drupal-selector^="edit-<dashes>-"]'
};
GutenbergNext.setWidgetValue = function (element, value) {
  // native value setter + dispatched input/change events
  // (checkboxes: set .checked + change)
};
```

`data-store.js` delegates its `widgetRoot`/`setNativeValue` to these (keeping
local fallbacks if the bridge is missing). Public store API unchanged.

### 5.2 `js/pre-publish.js`

Registered via the existing `gutenberg_next/editor` library (after
`js/bindings.js`). Feature gate: `wp.plugins`, `wp.data`, `wp.element`,
`wp.components`, `wp.hooks` present; `drupalSettings.gutenbergNext.publish`
present.

- **Panel content** (one render function, used by both registrations below):
  - Status: `ToggleControl` "Published", initial from `publish.status.published`,
    writes the `status` checkbox widget. Section omitted at render time when
    the status widget does not exist — content_moderation removes it on
    moderated bundles, where the workflow controls publishing instead.
  - Workflow (only when `publish.moderation`): `SelectControl` of
    `states`, initial `state`, writes the `moderation_state` select widget.
  - Schedule (only when `publish.scheduler`): two datetime inputs
    (datetime-local; date-only when the widget has no time input) writing the
    `publish_on` / `unpublish_on` widgets; clearable.
  - URL alias: `TextControl` initial `publish.alias`, writes the
    `path[0][alias]` textfield.
  - Featured media (only when `publish.featuredMedia`): current value summary;
    for `kind === 'entity_reference'` a media-entity autocomplete control
    (0.2 entity-reference pattern against `autocompleteUrl`) with write + clear;
    for other kinds (image/complex) an "Edit in form" jump instead.
  - Author: read-only "by <name>" line + "Edit in form" jump (upstream owns
    the author widget panel).
  - Fields: list from the 0.2 store — required/invalid markers, click jumps to
    the field (panel or form widget). Section omitted when the store has no
    fields (both 0.2 flags off).
  - Save button: clicks `#edit-submit` (runs upstream's validated submit
    flow). Label "Save".
- **Registration**: if `wp.data.dispatch('core/editor').togglePublishSidebar`
  is a function → `wp.plugins.registerPlugin('gutenberg-next-pre-publish', {
  render })` rendering `PluginPrePublishPanel` (from `wp.editor ||
  wp.editPost`). Otherwise register the same content as a
  `PluginDocumentSettingPanel` (degraded mode) and skip the header button.
- **Header button** (sidebar mode only): once-guarded observer inserts
  `<button type="button" class="gutenberg-next-publish-toggle button">Publish…</button>`
  into `.gutenberg-header-settings` (fallback `.editor-header__settings`)
  next to Drupal's relocated form actions; click →
  `dispatch('core/editor').togglePublishSidebar()`.
- **Publish sidebar visibility**: upstream CSS parks
  `.interface-interface-skeleton__actions` (where the publish panel renders)
  offscreen. A `wp.data.subscribe` watches `isPublishSidebarOpened()` and
  toggles body class `gutenberg-next-publish-open`; CSS un-parks the actions
  region only while that class is present.
- **WP publish button hidden**: `css/editor-shell.css` addition —
  `.gutenberg-next-enabled .editor-post-publish-button,
  .gutenberg-next-enabled .editor-post-publish-panel__toggle { display: none; }`
  (selectors verified against the demo build during implementation; WP's
  button only triggers the non-persisting mock save).
- **Save guard**: `wp.hooks.addFilter('editor.__unstableSavePost',
  'gutenberg-next/pre-publish-guard', (pending, options) => ...)` — when the
  0.2 store is ready and any field is invalid, return a rejected promise
  (message names the first invalid field) so upstream's submit flow stops
  before the form POST; otherwise return `pending` untouched. Guard is
  registered regardless of sidebar mode.

### 5.3 Widget targets

| Control | Widget root (data-drupal-selector) |
|---|---|
| Status toggle | `edit-status` (checkbox inside) |
| Moderation state | `edit-moderation-state` (select inside) |
| Publish on | `edit-publish-on` (date/time inputs inside) |
| Unpublish on | `edit-unpublish-on` (date/time inputs inside) |
| URL alias | `edit-path-0` (alias textfield inside) |
| Featured media | 0.2 entity-reference write path for the detected field |

Exact inner selectors are verified on the demo site during implementation
(the roots follow Drupal's standard wrapper scheme confirmed in 0.2).

## 6. Data flow

- Render: form_alter → PublishInfoBuilder → `drupalSettings.gutenbergNext.publish`
  → pre-publish.js hydrates panel state.
- Edit: panel control → bridge widget write → Drupal widget state → form
  submit persists (Drupal validation authoritative).
- Save: any save trigger → upstream submit flow → `editor.__unstableSavePost`
  guard (rejects when the 0.2 store has invalid fields) → savePost mock →
  real form POST.
- Moderation: state select → `moderation_state` widget → submit →
  content_moderation applies the transition.
- Scheduling: dates → scheduler widgets → submit → scheduler module queues
  publish/unpublish.
- Featured media: autocomplete pick → target id written into the detected
  field's widget → submit.

## 7. Feature-detection matrix

| Surface | Requires | Behavior when absent |
|---|---|---|
| Alias / author / fields sections | nothing | always shown |
| Status section | the status widget in the DOM | section omitted (moderated bundles) |
| Workflow section | content_moderation + bundle moderated | section omitted |
| Schedule section | scheduler + bundle scheduling enabled | section omitted |
| Featured media section | detectable media/image field | section omitted |
| Publish sidebar mode | `togglePublishSidebar` in core/editor | document-panel fallback |

## 8. Error handling & security

- Payload carries only metadata already visible on the edit form to a user
  with update access; no new secrets, no new routes, no permission changes.
- All writes flow through widgets → Drupal validation; client-side the guard
  only surfaces the 0.2 store's existing invalid state.
- Widget-write failures (missing root) surface the 0.2-style "widget not
  available" invalid state; nothing is silently dropped.
- Autocomplete access remains core's (route requirements + selection-handler
  entity access) via the 0.2 URL helper.

## 9. Config, versioning, release

- New config: `featured_media_overrides` (schema + settings form).
- Version `0.3.0-alpha1` in info.yml + composer.json; CHANGELOG section;
  README feature bullets; ROADMAP 0.3 items ticked.

## 10. Testing & verification

Environment: `http://drupal-test-2.test:8080/`.

1. Site additions:
   - `composer require drupal/scheduler`, enable, enable publish+unpublish
     scheduling for gnt_article.
   - Enable `content_moderation`, create an editorial workflow (draft →
     review → published) applied to gnt_article.
   - Add vocabulary `topics` + `field_topics` taxonomy_term reference
     (autocomplete widget) to gnt_article.
2. Automated checks:
   - New standalone check `tests/check-publish-info.php` for
     `parseOverrides` + `detectFeaturedField` (stub-free pure methods).
   - Existing catalog/serializer checks + all lints stay green; CI gains the
     new check step.
   - curl smokes on the node add/edit forms: `publish` block present;
     moderation/scheduler/featuredMedia sections present when enabled;
     autosave round-trip unaffected.
3. Browser checklist (added to docs/TESTING.md, executed by the user):
   publish sidebar opens from the header button; status toggle persists;
   moderation state change persists as a transition; schedule dates persist;
   alias edit persists; featured media pick persists; invalid required field
   blocks save with a snackbar; taxonomy term field works through the 0.2
   panel.

## 11. Done definition

- All seven roadmap items implemented and ticked in ROADMAP.md.
- Version 0.3.0-alpha1 everywhere; CHANGELOG/README updated.
- Standalone checks + lints green; demo-site smokes pass with scheduler and
  content_moderation enabled.
- Work merged to main and pushed to GitHub.

## 12. Out of scope for 0.3

- REST-native publishing, revision parity (0.4), media-library UI integration
  (autocomplete + jump only), WP publish-button behavior beyond hiding it,
  per-user publishing preferences, scheduled-publish notifications.
