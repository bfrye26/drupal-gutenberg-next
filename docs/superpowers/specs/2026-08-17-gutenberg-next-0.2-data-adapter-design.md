# Gutenberg Next 0.2 — Drupal Entity Data Adapter (Design)

Date: 2026-08-17
Status: Approved for planning
Repo: https://github.com/bfrye26/drupal-gutenberg-next

## 1. Goals

Complete the 0.2 roadmap milestone on top of Drupal Gutenberg 4.0.x:

1. Replace field DOM jumps with a real Drupal entity data store.
2. Read/write field values through editor state.
3. Map supported Drupal fields into Block Bindings sources.
4. Validation/error synchronization.
5. Dirty-state and autosave synchronization.
6. Entity-reference autocomplete adapter.

Hard constraints (decided with user):

- **Write path is form-submit-backed.** The JS store serializes edited values back
  into the hidden Drupal form widgets; Drupal's own validation, access checks and
  submit flow stay authoritative. No REST route writes entity data.
- **Every field type is covered** by a two-tier adapter model (section 5):
  native editor controls where a safe typed mapping exists, snapshot + jump-to-form
  widget otherwise.
- No build step: plain JS files using the `wp.*` globals upstream ships; the module
  must feature-detect and degrade when packages are absent (existing 0.1 pattern).
- Verification against the user's local Drupal 11 site:
  `http://drupal-test-2.test:8080/` (`C:\laragon\www\Drupal-Test-2`).

## 2. Architecture

```
                    drupalSettings.gutenbergNext.entity.fields
PHP (form render) ────────────────────────────────────────────┐
  FieldCatalog (adapter meta)                                   │
  FieldValueSerializer (typed JSON values)                      ▼
                                                    wp.data store "gutenberg-next/fields"
                                                                 │
        ┌──────────────────────────┬──────────────────────────────┼───────────────┐
        ▼                          ▼                              ▼               ▼
  Field panel (React)     Block bindings source           Validation sync   Autosave snapshots
  native controls per    "gutenberg-next/field"           client checks +   POST/GET/DELETE
  field kind             getValues/setValues              form error scan   /editor/gutenberg-next/autosave/...
        │                          │
        └──────────────┬───────────┘
                       ▼
        Typed DOM write adapters → hidden Drupal form widgets
                       ▼
        Drupal form submit (unchanged, authoritative)
```

The store is the single editor-side source of truth for non-body field state.
The body field remains Gutenberg's own; it is not part of this adapter.

## 3. PHP components

### 3.1 `FieldValueSerializer` (service `gutenberg_next.field_value_serializer`)

Normalizes entity field values into a stable JSON shape per field type.
Signature: `serialize(ContentEntityInterface $entity, FieldDefinitionInterface $definition): mixed`.

| Field type | JSON value |
|---|---|
| string, text, text_long, text_with_summary | `string` (empty string when empty) |
| integer, decimal, float | `number \| null` |
| boolean | `boolean \| null` |
| list_string, list_integer, list_float | `string[]` of selected option keys |
| datetime | storage string in the field's storage format (`Y-m-d\TH:i:s` or `Y-m-d`) |
| timestamp | `int \| null` |
| entity_reference (single/multi) | `{id: int, label: string}[]` (labels resolved from referenced entities; unresolved ids use `#<id>`) |
| everything else (media, image, file, paragraphs, address, contrib...) | `{summary: string, detail: string[]}` |

Complex-type summaries are human-readable (e.g. "2 paragraphs", "photo.jpg",
"Article: About us"). The serializer never throws on unknown types — it falls
back to the complex summary shape.

### 3.2 `FieldCatalog` extensions

Each emitted field gains adapter metadata (added to the existing 0.1 entry):

- `kind`: `text` (string/text/text_long/text_with_summary), `number`, `boolean`,
  `list` (single-select/radios or multi-checkboxes), `datetime`, `date`,
  `entity_reference`, `complex`
- `cardinality` (int), `maxLength`, `numberMin`, `numberMax`, `options` (list),
  `multiple` (bool for list/entity_reference)
- `datetimeStorageFormat`, `datetimeDisplayFormat`
- entity_reference: `targetType`, `autocompleteUrl` — computed with the same
  handler + `Crypt::hmacBase64` settings-key mechanism as core's
  `EntityAutocomplete` element, pointing at core's
  `entity_reference_autocomplete` route (GET, session-authenticated, access
  enforced by the route requirements + the selection handler's
  `validateReferenceableEntities()`)
- `value`: the serialized value (from 3.1)

### 3.3 `FieldAutosaveController`

New route pair, JSON via `_format: json`, `X-CSRF-Token` header
(`_csrf_request_header_token: 'TRUE'`), permission `use gutenberg` AND
`$entity->access('update')` checked in-controller (403 otherwise):

- `POST /editor/gutenberg-next/autosave/{entity_type}/{entity_id}`
  body `{"fields": {"field_x": <serialized value>, ...}}` → upsert row for the
  current user; response `{"saved": true, "changed": <unix ts>}`
- `GET` same path → `{"data": {...} | null, "changed": <ts> | null}`
- `DELETE` same path → `{"cleared": true}`

Storage: new table `gutenberg_next_field_autosave`
(`id`, `uid`, `entity_type`, `entity_id`, `bundle`, `data` longtext,
`changed` int). No entity writes. Prune rows older than 30 days in
`hook_cron`; delete rows in `hook_entity_delete`. Schema provided via
`gutenberg_next.install`.

Autosave covers saved entities only (`entity_id` set). For new nodes the
store skips server autosave; Drupal already re-renders widget values on
validation reloads.

### 3.4 Form render (`gutenberg_next_form_alter`)

Extends the existing `drupalSettings.gutenbergNext` payload:

```js
gutenbergNext = {
  ...0.1 keys...,
  entity: {
    type, bundle, id,
    fields: [ { name, label, kind, required, cardinality, ..., value } ],
  },
  autosave: {
    enabled: <bool config>,
    url: "/editor/gutenberg-next/autosave/{type}/{id}",
    token: <csrftoken from drupalSettings.gutenberg.csrfToken>,
  },
  bindings: { enabled: <bool config> },
}
```

## 4. JS components

All files load via the existing `gutenberg_next/editor` library, added after
`editor-bridge.js`. Every entry point feature-detects `window.wp` and exits
cleanly if the editor packages are unavailable.

### 4.1 `js/data-store.js`

Registers `wp.data` store `gutenberg-next/fields` (createReduxStore; falls back
to legacy registerStore if needed).

State:

```js
{
  ready: bool,
  entity: { type, bundle, id },
  fields: {
    [name]: {
      name, label, kind, required, cardinality, multiple,
      value, dirty, invalid: { message } | null,
      meta: { ...adapter metadata },
    }
  },
  autosaveRestored: bool,
}
```

Actions/selectors:

- `load(payload)` — hydrate from drupalSettings.
- `setValue(name, value)` — client validation (section 6) → on success: set
  state, write into the hidden widget via the DOM write adapter (section 7),
  mark field + store dirty, debounce autosave POST (2s).
- `setInvalid(name, message | null)`.
- `markSaved()` — clear dirty flags (called after successful form submit).
- selectors: `getField(name)`, `getFields()`, `getValue(name)`,
  `isDirty()`, `isFieldDirty(name)`.

Autosave restore on init: `GET` the snapshot; if present and different from the
current values → apply through the same write path, mark dirty, show snackbar
"Drupal field changes restored from autosave"; if identical → `DELETE`
silently. A failed autosave POST surfaces a dismissible warning notice once.

Dirty-state propagation: on first store change, best-effort call
`wp.data.dispatch('core/editor').__unstableMarkEditorAsDirty?.()` if present,
plus a `beforeunload` guard when `isDirty()`.

### 4.2 `js/field-panel.js`

Replaces the 0.1 DOM-jump panel with a store-driven `PluginDocumentSettingPanel`:

- Native controls per kind: `TextControl`/`TextareaControl` (text),
  `TextControl type=number` (number), `ToggleControl` (boolean),
  `SelectControl` or `CheckboxControl` group (list), date/datetime-local inputs
  converted to the storage format via `datetimeStorageFormat` (datetime/date),
  entity_reference: `FormTokenField` (multi) / combobox (single) fed by the
  core autocomplete route (section 9).
- Complex kind: summary lines + "Edit in form" button → existing
  `focusDrupalField()` jump; values shown from the store snapshot.
- Error badges: `invalid` state renders the message under the control and
  marks the panel row; required fields show the `*` marker (0.1 behavior kept).
- Panel reads/writes exclusively through the store — no DOM scraping.

### 4.3 `js/bindings.js`

Registers binding source `gutenberg-next/field`
(`wp.blocks.registerBlockBindingsSource`):

- `label`: "Drupal field"
- `getValues({bindings, select})` — for each attribute bound with
  `args.field = <field name>`, return the store value mapped to the attribute's
  expected type (string for content/text/alt/title, number→string for url).
- `setValues(...)` — if the installed API exposes it, writes through
  `setValue()` so the widget + autosave stay in sync; otherwise bindings are
  read-only in 0.2 and the panel remains the write surface (verified on the
  demo site).

Bindable attributes: `core/heading` → content; `core/paragraph` → content;
`core/button` → text, url; `core/image` → url, alt, title.

If the native bindings UI is unavailable in the installed build, ship a minimal
fallback inspector panel (BlockEdit HOC filter) that writes the block's
`metadata.bindings` attribute — same mechanism as upstream's mapping-fields
precedent. Which path ships is decided by what the demo site exposes; both are
specified here.

## 5. Field adapter tiers ("every field type")

| Tier | Field types | Editing UX |
|---|---|---|
| Native | string, text, text_long, text_with_summary, integer, decimal, float, boolean, list_string, list_integer, list_float, datetime, timestamp, entity_reference (single + multi) | Gutenberg-native controls in the panel; available as binding sources (scalar-compatible types) |
| Snapshot + jump | media, image, file, paragraphs, address, all contrib types | Store-visible typed summary, dirty/validation sync, "Edit in form" jump to the real Drupal widget |

## 6. Validation / error synchronization

- Client (store `setValue`): required non-empty; cardinality min/max;
  maxLength; number min/max; list option membership; datetime format check.
  Failures set `invalid` and do not touch the widget.
- Server errors: on form render (and after failed submits), scan the form DOM
  for `[aria-invalid="true"]` / `.form-item--error` wrappers belonging to
  catalog fields → `setInvalid(field, message)` so errors appear in the panel
  with Drupal's message text; the editor stays on the page (Drupal renders the
  failed form), so this covers non-ajax validation flows.

## 7. Typed DOM write adapters (JS, per kind)

All writes use native value setters plus dispatched `input`/`change` events so
Drupal widget behaviors (counters, machine-name, states) observe them.
Targets are the field's `[data-drupal-selector^="edit-<name>-"]` roots (same
scoping as 0.1 `findDrupalField`):

- text/number: set `input.value` (number: same)
- boolean: set `input[type=checkbox].checked` and toggle widget classes
- list single: set `select.value` / radio `checked`; list multi: per-option
  checkboxes
- datetime/date: set the date and/or time sub-inputs to the storage-format
  string; timestamp: epoch seconds into the widget input
- entity_reference: write target id(s) into the widget's hidden target_id
  input and the label(s) into the visible autocomplete input
- complex: no write adapter (read-only in the store)

Exact widget DOM specifics are verified against the demo site during
implementation and encoded in a per-kind adapter map.

## 8. Config, versioning, release

- New settings (schema + defaults + settings form): `autosave_fields` (true),
  `field_bindings` (true). `show_field_panel` now means the store-driven panel.
- Version bump to `0.2.0-alpha1`: `gutenberg_next.info.yml`, `composer.json`,
  CHANGELOG entry.
- ROADMAP.md: tick the six 0.2 items.
- README.md: update "What alpha 1 does today" → 0.2 feature list; note the
  two-tier adapter coverage.

## 9. Entity-reference autocomplete adapter

- PHP emits `autocompleteUrl` per entity-reference field (section 3.2).
- JS calls it with `?q=<query>` using the session cookie; suggestions render in
  `FormTokenField`/combobox; selection stores `{id, label}` in the store, which
  writes both the hidden id and the visible label into the widget (section 7).
- Access control is core's: route requirements + selection-handler entity
  access. No new route and no new permission for autocomplete.

## 10. Error handling & security

- All REST traffic is session-authenticated, CSRF-header-token protected,
  gated by `use gutenberg` + entity update access. Autosave data is
  per-user, never shared between users.
- Store writes validate before touching widgets; invalid values leave the
  widget untouched and surface inline.
- Network failures on autosave/autocomplete degrade to notices; editing stays
  functional (autosave is best-effort by design).
- drupalSettings payload carries field values (not site secrets); visible only
  on edit forms the user can already access.

## 11. Testing & verification

Environment: `http://drupal-test-2.test:8080/`
(`C:\laragon\www\Drupal-Test-2`, Drupal 11, no contrib modules yet).

1. Install tooling on the site: `composer require drush/drush
   drupal/gutenberg:4.0.x-dev@dev` (+ `minimum-stability dev`,
   `prefer-stable true`); link this module via a composer path repository
   (or symlink) into `web/modules/custom/gutenberg_next`.
2. `drush en gutenberg gutenberg_next -y`.
3. Create test content type "GNT Article" with the full matrix: string
   (required), text_long, integer, decimal, boolean, list_string, datetime,
   entity_reference→node (multi), media image, paragraphs. Enable Gutenberg
   for it; enable gutenberg_next for the bundle.
4. Automated checks:
   - Standalone check `tests/check-field-serializer.php` (dependency-free,
      stub-based, like the existing field-catalog check; also runnable in CI):
      per-type normalization + complex fallback + unknown-type fallback.
   - curl smoke: authenticated GET of the node add form — assert the
     drupalSettings payload (catalog kinds, serialized values, autosave URL,
     bindings flag).
   - curl round-trip: POST/GET/DELETE autosave with session cookie + CSRF
     header; assert 403 without permission.
   - Existing CI (syntax + standalone field-catalog check + composer validate)
     keeps running; the standalone check is extended for the serializer's
     pure parts if feasible without a full Drupal bootstrap.
5. Browser checklist (docs/TESTING.md, executed by user): panel edits persist
   through submit; bindings show correct values; validation error surfaces in
   the panel; autosave restores after reload-without-save; entity autocomplete
   returns nodes and writes back correctly.

## 12. Done definition

- All six roadmap items implemented and checked in ROADMAP.md.
- Version 0.2.0-alpha1 bumped everywhere.
- Standalone checks + smoke checks pass on the local demo site.
- CI green on GitHub; work pushed to `main`.

Out of scope for 0.2 (explicit): REST-native entity saves, realtime
collaboration, Content Moderation UI in the editor, media/image native
controls (tier 2), upstream fork import.
