# Testing checklist

The first browser validation should be performed on Drupal 11 with Drupal Gutenberg 4.0.x-dev.

## Editor smoke test

1. Create a Gutenberg-enabled Article content type with at least two additional fields.
2. Open a new node form.
3. Confirm the Drupal badge appears in the editor header.
4. Confirm the Drupal fields panel appears in Document Settings.
5. Click a field in that panel and verify the matching Drupal form field is revealed/focused.
6. Insert paragraph, heading, image, gallery, columns and group blocks.
7. Verify normal blocks are constrained to the configured content width.
8. Verify `alignwide` uses the configured wide width.
9. Verify `alignfull` is not constrained.
10. Switch desktop/tablet/mobile preview modes and confirm the editor remains usable.
11. Save, reload and verify serialized block content is unchanged.

## Regression cases

- Existing Gutenberg content
- More than 100 blocks
- Drupal Media image and remote video
- Required Drupal fields
- Validation errors in collapsed details/metaboxes
- Content Moderation enabled
- Gin and Claro admin themes
- Drupal toolbar horizontal and vertical modes
- Autosave/reload after unsaved changes

## 0.2 data adapter checklist

1. Edit each native field kind in the Drupal fields panel; save; reload; values persisted.
2. Bind a heading block to `field_subtitle` via the block bindings UI; confirm the value renders and follows panel edits.
3. Enter an invalid value (e.g. text in a number field); confirm the inline error appears and nothing is written to the form widget.
4. Make edits, reload without saving; confirm the "restored from autosave" snackbar and the values are back.
5. Save; edit; reload; confirm no stale autosave restore happens.
6. Multi-value entity reference: search "autosave" in the token field; select; save; values persisted.
7. Required field: clear it, submit; confirm the server error message surfaces in the panel.
