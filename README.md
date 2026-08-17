# Gutenberg Next for Drupal

Gutenberg Next is a modern integration layer for the Drupal Gutenberg 4.x project. The immediate goal is to make the current Gutenberg editor feel production-ready in Drupal today while creating a clean adapter architecture for progressively reaching feature parity with the WordPress post editor.

This repository is intentionally separate from site-specific CGM functionality. Anything generally useful to Drupal/Gutenberg belongs here; CGM-specific blocks and editorial features can consume the extension APIs later.

## What it does today

- Works as a Drupal module alongside Drupal Gutenberg 4.x.
- Supports Drupal 10.3+ and Drupal 11 at the module metadata level.
- Adds configurable normal and wide editor content widths without limiting `alignfull` blocks.
- Handles modern iframe-based Gutenberg canvases as well as older in-document editor canvases.
- Keeps the Gutenberg top header visible without hiding the Drupal toolbar offset.
- Adds an unobtrusive Drupal integration badge to the Gutenberg editor header.
- Exposes the current entity's Drupal fields to a Gutenberg-native Document Settings panel when the installed Gutenberg package exposes `PluginDocumentSettingPanel`.
- Lets editors jump from that panel directly to the underlying Drupal form field.
- Includes a compatibility capability detector so future adapters can feature-detect WordPress packages rather than depending on a single Gutenberg generation.
- Provides an admin settings screen and a status/parity screen.
- Includes a CI baseline for PHP and JavaScript syntax.
- Provides a Gutenberg-native data store for Drupal fields with typed values, dirty tracking and validation sync.
- Lets editors edit supported Drupal fields directly in the document sidebar (strings, numbers, booleans, lists, dates, entity references) and binds heading, paragraph, button and image blocks to Drupal field values.
- Autosaves unsaved Drupal field changes per user and restores them after an accidental reload.

## Installation on a Drupal site today

1. Install Drupal Gutenberg 4.x. For evaluation of the newest upstream work:

   ```bash
   composer config minimum-stability dev
   composer config prefer-stable true
   composer require drupal/gutenberg:4.0.x-dev@dev
   ```

   For a tagged beta instead:

   ```bash
   composer require 'drupal/gutenberg:^4.0@beta'
   ```

2. Add this module via Composer. From the GitHub repository:

   ```bash
   composer config repositories.gutenberg-next vcs https://github.com/bfrye26/drupal-gutenberg-next
    composer require cgm/drupal-gutenberg-next:^0.2
   ```

   Or, for local development, place this repository at `web/modules/custom/gutenberg_next` (or add it as a Composer path repository).
3. Enable both modules:

   ```bash
   drush en gutenberg gutenberg_next -y
   drush cr
   ```

4. Enable the Gutenberg experience on a content type using the normal Drupal Gutenberg configuration.
5. Configure Gutenberg Next at `/admin/config/content/gutenberg-next`.
6. Open a Gutenberg-enabled node edit form.

## Why this is an integration layer first

A hard fork that directly modifies thousands of lines of WordPress Gutenberg code would become expensive to keep current. Gutenberg already publishes the editor as `@wordpress/*` packages. Drupal Gutenberg 4.x has also moved toward package-based upstream consumption. Gutenberg Next therefore keeps Drupal-specific concerns in adapters and aims to change upstream Gutenberg code only when there is no viable extension point.

The long-term repository can absorb the Drupal Gutenberg 4.x source once a full fork is established, but this alpha is deliberately installable **with** the current upstream module so it can be evaluated immediately without waiting for that repository migration.

## Roadmap

See [`docs/ROADMAP.md`](docs/ROADMAP.md). The next engineering milestones are publishing parity (workflow, scheduling, taxonomy controls) followed by revision parity — see the roadmap.

## Upstream

See [`docs/UPSTREAM.md`](docs/UPSTREAM.md) for the tracked projects, compatibility strategy and fork policy.

## License

GPL-2.0-or-later. Gutenberg and Drupal retain their respective copyrights and project trademarks.
