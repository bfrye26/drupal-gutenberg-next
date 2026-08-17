# Architecture

## Principle

Gutenberg Next treats WordPress Gutenberg as an upstream editor framework and Drupal as the canonical content/data platform. The adapter boundary is the product.

```text
@wordpress/* packages
        |
        v
Gutenberg editor shell
        |
        v
Gutenberg Next adapters
  |      |       |       |
entity  media  revision  permissions
  |      |       |       |
        Drupal APIs
```

## Current alpha

The first alpha is an overlay on Drupal Gutenberg 4.x. This gives us a deployable test bed today without copying an entire upstream module tree before the fork repository exists.

The runtime bridge deliberately feature-detects globals such as `wp.editor`, `wp.editPost`, `wp.plugins`, `wp.commands` and `wp.preferences`. The module should degrade rather than fatal when a Gutenberg package moves between releases.

## Adapter targets

### Entity adapter

Translate Gutenberg editor expectations into Drupal content entities, bundles and fields. The alpha exposes a safe field catalog and a native Document Settings panel. The next step is read/write bindings rather than DOM navigation.

### Media adapter

Build on Drupal Gutenberg's existing Media integration. The desired endpoint is parity with Gutenberg's current replace/crop/focal-point/editor workflows while retaining Drupal Media entities as the source of truth.

### Revision adapter

Connect Gutenberg's visual revision UI to Drupal entity revisions and Content Moderation states.

### Publishing adapter

Treat Drupal workflow, permissions and scheduling as authoritative while rendering those capabilities in the Gutenberg editor shell.

### Theme adapter

Translate Drupal theme configuration and design tokens into Gutenberg Global Styles/theme-json concepts. Avoid hard-coding site theme values in the module.

## What should not be emulated

We do not plan to emulate the WordPress PHP plugin runtime. JavaScript blocks that rely only on portable Gutenberg APIs should be straightforward to port; WordPress plugins that depend on `WP_Post`, post meta, WordPress REST controllers or PHP hooks require Drupal-native implementations.
