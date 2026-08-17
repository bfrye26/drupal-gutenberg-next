#!/usr/bin/env bash
set -euo pipefail

# Creates a disposable Drupal 11 site that uses the current Drupal Gutenberg 4.x
# development line plus this module. Run from the repository root.
DEMO_DIR="${1:-../gutenberg-next-demo}"
MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

composer create-project drupal/recommended-project:^11 "$DEMO_DIR"
cd "$DEMO_DIR"
composer config minimum-stability dev
composer config prefer-stable true
composer require drush/drush drupal/gutenberg:4.0.x-dev@dev
composer config repositories.gutenberg-next path "$MODULE_DIR"
composer require cgm/drupal-gutenberg-next:@dev

echo
echo "Dependencies are installed. Complete your normal Drupal site install, then run:"
echo "  vendor/bin/drush en gutenberg gutenberg_next -y"
echo "  vendor/bin/drush cr"
