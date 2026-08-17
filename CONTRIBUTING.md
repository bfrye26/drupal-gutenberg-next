# Contributing

Gutenberg Next is intended to remain useful outside any single Drupal site.

## Design rules

1. Prefer public `@wordpress/*` extension APIs over patching Gutenberg internals.
2. Keep Drupal-specific behavior in adapters and services.
3. Do not add CGM-specific content models, blocks or branding to this repository.
4. Feature-detect Gutenberg packages when APIs have moved between upstream releases.
5. Treat Drupal permissions, validation and entity access as authoritative.
6. Add a regression test or a reproducible manual test case for editor behavior changes.

## Local checks

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/check-field-catalog.php
npm run check
bash -n scripts/install-demo.sh
```

Browser testing should follow `docs/TESTING.md` on a real Drupal 11 site with Drupal Gutenberg 4.x.
