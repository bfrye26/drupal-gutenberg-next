# Changelog

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
