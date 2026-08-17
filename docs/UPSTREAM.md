# Upstream strategy

Gutenberg Next tracks two upstreams:

1. **Drupal Gutenberg** for Drupal entity, media, rendering and block-processing integration.
2. **WordPress Gutenberg** for the editor packages and UI capabilities.

## Initial compatibility baseline

The development target is the Drupal Gutenberg 4.x line. The May 2026 upstream work moved the branch onto Gutenberg 23.0.1 and supports Drupal 10.3+ alongside Drupal 11.

The alpha intentionally consumes upstream rather than vendoring it. Once the GitHub fork repository is established, we can import the upstream Drupal Gutenberg history and carry the adapter work on top of it.

## Fork policy

- Keep direct modifications to upstream Gutenberg packages close to zero.
- Prefer public Gutenberg extension APIs and package composition.
- Keep Drupal data, permission, media and workflow logic in Drupal adapters.
- Rebase/import upstream Drupal Gutenberg changes regularly.
- Contribute generally useful Drupal integration fixes upstream whenever practical.
- Keep CGM-specific features in separate modules.
