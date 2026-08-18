# Gutenberg Next 0.4 — Revision Parity (Design)

Date: 2026-08-17
Status: Approved for planning
Repo: https://github.com/bfrye26/drupal-gutenberg-next

## 1. Goals

Complete the 0.4 roadmap milestone on top of Drupal Gutenberg 4.0.x:

1. Drupal entity revision browser.
2. Gutenberg visual change comparison.
3. Restore revision.
4. Revision author/message metadata.

Upstream context (researched, 4.0.x branch):

- The fake WP post carries `revisions: { count: 0, last_id: 1 }` and nothing
  else; upstream renders no revision UI and its WP-admin revision links are
  dead (no `_links`/`wp:action-publish` on the fake post). 0.4 is net-new,
  like 0.3.
- Drupal core provides the revision data model (vid, revision_timestamp,
  revision_uid, revision_log), the revision view route
  `entity.node.revision` guarded by the `_access_node_revision: 'view'`
  requirement (service `access_check.node.revision`), and the revert form at
  `/node/{node}/revisions/{node_revision}/revert`.

## 2. Hard constraints

- Routes are GET-only and non-mutating. No write endpoints this milestone.
- Restore = jump-out to core's revert confirmation form. No programmatic
  revert (Content Moderation transition handling stays core's job).
- Comparison = server-rendered revision HTML shown side by side. No markup
  diff algorithm.
- No build step: plain JS over `window.wp.*` globals, feature-detect and
  degrade (0.1-0.3 pattern). Plain `fetch` with same-origin credentials
  (upstream replaces `wp.apiFetch`).
- Demo verification on `http://drupal-test-2.test:8080/`
  (`C:\laragon\www\Drupal-Test-2`); node 1 (gnt_article, moderated) currently
  has one revision — setup creates more.

## 3. Architecture

```
form_alter ──> drupalSettings.gutenbergNext.revisions
               { enabled, listUrl, revertUrlBase }   (lightweight)
                                        │
                                        ▼
                        js/revisions.js — "Revisions" document panel
                        (lazy fetch of the list on first open)
                                        │
              ┌─────────────────────────┼─────────────────────────┐
              ▼                         ▼                         ▼
     GET .../revisions/node/{node}   Compare (pick 2)        Restore button
     list: vid/author/date/log/      → wp.components.Modal   → window.location
     default flag                      → GET rendered payload    to core revert
                                       per selected revision     form (vid URL)
                                        │
                                        ▼
                 side-by-side rendered HTML (real render pipeline)
```

## 4. PHP components

### 4.1 `RevisionInfoBuilder` (service `gutenberg_next.revision_info_builder`)

Constructor deps: `entity_type.manager`, `renderer`, `entity.repository`
(translation-safe label/user resolution where needed).

Methods:

- `buildList(ContentEntityInterface $entity): array` — entity query
  `allRevisions()` on the entity's id; raw rows are passed through
  `formatList` (below) before being returned, so ordering/shaping has one
  home.
- `buildRevisionView(ContentEntityInterface $revision): array` — returns
  `{vid, title, html, timestamp, authorName, log}`; `html` is the revision
  rendered through the node view builder (view mode `full`) via the renderer
  service, so Gutenberg blocks pass through the real render pipeline
  (GutenbergFilter etc.).
- `formatList(array $rows): array` — pure shaping/ordering helper
  (standalone-check covered): input rows `{vid, isDefault, timestamp,
  authorId, authorName, log}` with possibly missing log/authorName; output
  newest-first by timestamp then vid, defaults applied (`log` `''`,
  `authorName` `''`).

### 4.2 `RevisionController`

Two GET routes, `_format: json`:

- `gutenberg_next.revision_list` —
  `GET /editor/gutenberg-next/revisions/node/{node}`
  → `{"revisions": [...]}` from `buildList`.
  Access: `_custom_access` on the controller mirroring core's
  `NodeRevisionAccessCheck` semantics (verify the exact core logic during
  implementation and copy it: revision-view permission check plus
  `$node->access('view')`), with proper cacheability metadata.
- `gutenberg_next.revision_view` —
  `GET /editor/gutenberg-next/revisions/node/{node}/{node_revision}`
  → `buildRevisionView` payload.
  Access: `_access_node_revision: 'view'` — identical to core's
  `entity.node.revision` route. Route options mirror core: parameters
  `node: {type: entity:node}`, `node_revision: {type: entity_revision:node}`.

Route options carry `no_cache: TRUE` (revision data changes with every
save) and the `parameters` upcasting above for both routes.

### 4.3 Payload

`gutenberg_next_form_alter` adds (node-only section, saved entities only):

```js
gutenbergNext.revisions = {
  enabled: true,
  listUrl: "/editor/gutenberg-next/revisions/node/<nid>",
  revertUrlBase: "/node/<nid>/revisions/",   // + <vid> + "/revert" in JS
}
```

`enabled` is true only when the current user passes the same revision-view
access logic as the list route (computed in form_alter); otherwise the whole
block is omitted. New entities get no block.

## 5. JS: `js/revisions.js`

Registered via the existing `gutenberg_next/editor` library (after
`js/pre-publish.js`). Feature gate: `wp.plugins`, `wp.data`, `wp.element`,
`wp.components` + `drupalSettings.gutenbergNext.revisions` present.

- **Panel**: `PluginDocumentSettingPanel` "Revisions" (plugin name
  `gutenberg-next-revisions`), registered once (`window.__gutenbergNextRevisionsRegistered`).
  The list is fetched once at panel mount, guarded by a `loaded` flag:
  `fetch(listUrl, {credentials: 'same-origin'})` → list state; loading text;
  fetch failure → error notice state in the panel.
- **List rows** (newest first): formatted date, author name, log message or
  "—", `Current` badge on `isDefault`. Each row: a selection checkbox
  (max two selected — selecting a third deselects the oldest) and a
  **Restore** button on non-current rows:
  `window.location = revertUrlBase + vid + '/revert'`.
- **Compare button**: enabled when exactly two revisions are selected; opens
  `wp.components.Modal` (title "Compare revisions").
- **Modal content**: two panes in a CSS grid; each pane fetches its revision
  payload on open (per-pane loading/error states); pane renders a header
  (title, date, author, log) and the rendered `html` inside a scoped
  container. The HTML is server-rendered node content the user already has
  view access to (same trust level as the node page); it is inserted with
  `dangerouslySetInnerHTML` inside the scoped container — no client-side
  string building from revision data.
- Closing the modal clears the panes; selection persists until changed.

## 6. Data flow

- Render: form_alter → access check → `revisions` settings block.
- List: panel open → GET list route → rows.
- Compare: select two → modal → GET view route ×2 → rendered panes.
- Restore: button → core revert form (Drupal confirmation + moderation
  handling + save) → user returns to the editor via normal navigation.

## 7. Error handling & security

- Both routes are access-gated (revision-view semantics + node view); 403
  otherwise. Unknown node/revision → 404 via param upcasting.
- GET-only: no CSRF surface, no mutation, no new permissions invented.
- Rendered HTML carries the same trust as the live node view; no raw user
  input is interpolated client-side (all React text children escape).
- Fetch failures surface as panel/modal error states; nothing silent.

## 8. Config, versioning, release

- No new settings (permission-gated feature).
- Version `0.4.0-alpha1` in info.yml + composer.json; CHANGELOG section;
  README feature bullets; ROADMAP 0.4 items ticked.

## 9. Testing & verification

Environment: `http://drupal-test-2.test:8080/`.

1. Setup: create two additional revisions of node 1 via drush (distinct log
   messages, one changing the body), so the list has ≥3 rows including the
   default.
2. Automated checks:
   - New standalone check `tests/check-revision-info.php` for the pure
     `formatList` helper (ordering, defaults, shaping) — same dependency-free
     pattern as the other checks; CI gains the step.
   - Existing checks + lints stay green.
   - curl smokes with the admin jar: list route returns ≥3 rows newest-first
     with author/log/default fields; view route returns non-empty `html`
     containing rendered block output; anonymous request → 403; unknown vid
     → 404.
3. Browser checklist (added to docs/TESTING.md, executed by the user):
   panel lists revisions with metadata; selecting two enables Compare; the
   modal shows the two rendered versions side by side; Restore lands on
   core's revert form; reverting changes the current content.

## 10. Done definition

- All four roadmap items implemented and ticked in ROADMAP.md.
- Version 0.4.0-alpha1 everywhere; CHANGELOG/README updated.
- Standalone check + lints green; demo-site smokes pass.
- Work merged to main and pushed to GitHub.

## 11. Out of scope for 0.4

- Programmatic in-editor revert (moderation transition handling stays core's).
- Markup-level diffing, more-than-two-way comparison, translation revisions,
- revision pagination (list is fetched whole; alpha-scale histories),
- autosave/revision interplay.
