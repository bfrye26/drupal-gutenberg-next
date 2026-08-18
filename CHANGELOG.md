# Changelog

## 0.4.0-alpha1 - 2026-08-17

### Added

- In-editor Drupal revision browser: newest-first list with author, date, log message and current-revision badge.
- Visual revision comparison: server-rendered side-by-side view of any two revisions in a modal.
- Restore entry point from the revision browser, jumping to Drupal core's revert confirmation form.
- Read-only revision endpoints with core revision-view access semantics.

## 0.3.0-alpha1 - 2026-08-17

### Added

- Gutenberg-native pre-publish flow: a publish sidebar panel with Drupal status, workflow state, scheduling, URL alias, featured media, author and field summaries.
- Content Moderation workflow states in the editor (feature-detected; writes through the moderation widget).
- Scheduled publishing through the Scheduler module (feature-detected; writes publish_on/unpublish_on widgets).
- Featured media integration with per-bundle auto-detection and config overrides.
- Save guard blocking the editor save path while Drupal fields are invalid.
- Canonical widget helpers (findWidgetRoot/setWidgetValue) in the editor bridge, shared by the field store and the publishing controls.

## 0.2.0-alpha1 - 2026-08-17

### Added

- Drupal entity data store (`wp.data` store `gutenberg-next/fields`) as the editor-side source of truth for non-body fields.
- Typed field value serialization (string, text, number, boolean, list, datetime, timestamp, entity reference) with human-readable snapshots for complex fields.
- Store-driven Drupal fields panel with native controls per field kind.
- Drupal field block binding source (`gutenberg-next/field`) for heading, paragraph, button and image blocks.
- Per-user autosave snapshots of unsaved field changes with restore-on-reload.
- Client validation and Drupal server error synchronization into the field panel.
- Entity-reference autocomplete through Drupal core's autocomplete endpoint.

## 0.1.0-alpha1 - 2026-08-17

### Added

- Drupal 10.3+ and Drupal 11 module scaffold on top of Drupal Gutenberg 4.x.
- Modern Gutenberg editor-shell compatibility layer.
- Configurable normal and wide editor content widths.
- Iframe and non-iframe editor canvas support.
- Drupal toolbar-aware sticky editor header.
- Drupal content-entity field catalog service.
- Gutenberg-native Drupal field document panel with safe DOM fallback.
- Runtime Gutenberg package capability detection.
- Administration settings and compatibility status pages.
- GitHub Actions syntax checks and project documentation.
