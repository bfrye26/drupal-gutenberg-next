<?php

declare(strict_types=1);

/**
 * Self-check for the PublishInfoBuilder pure helpers.
 *
 * Runs without a Drupal installation (the helpers are static and the class is
 * never instantiated here). Usage:
 *
 *   php tests/check-publish-info.php
 *
 * Exits non-zero on the first failure.
 */

namespace {
  require __DIR__ . '/../src/Bridge/PublishInfoBuilder.php';

  use Drupal\gutenberg_next\Bridge\PublishInfoBuilder;

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $check('parse overrides basic', PublishInfoBuilder::parseOverrides("article: field_image\npage: none") === ['article' => 'field_image', 'page' => FALSE]);
  $check('parse overrides whitespace and comments', PublishInfoBuilder::parseOverrides("  article :  field_photo \n# comment\n\n") === ['article' => 'field_photo']);
  $check('parse overrides later duplicate wins', PublishInfoBuilder::parseOverrides("a: x\na: y") === ['a' => 'y']);
  $check('parse overrides empty value disables', PublishInfoBuilder::parseOverrides('a:') === ['a' => FALSE]);
  $check('parse overrides empty string', PublishInfoBuilder::parseOverrides('') === []);

  $catalog = [
    ['name' => 'field_photo', 'kind' => 'entity_reference', 'targetType' => 'media', 'type' => 'entity_reference'],
    ['name' => 'field_image', 'kind' => 'complex', 'type' => 'image'],
    ['name' => 'field_doc', 'kind' => 'entity_reference', 'targetType' => 'media', 'type' => 'entity_reference'],
  ];
  $check('detect prefers first media reference', PublishInfoBuilder::detectFeaturedField($catalog, [], 'article') === 'field_photo');
  $check('detect override wins', PublishInfoBuilder::detectFeaturedField($catalog, ['article' => 'field_image'], 'article') === 'field_image');
  $check('detect override none disables', PublishInfoBuilder::detectFeaturedField($catalog, ['article' => FALSE], 'article') === NULL);
  $check('detect falls back to image field', PublishInfoBuilder::detectFeaturedField([['name' => 'field_image', 'kind' => 'complex', 'type' => 'image']], [], 'article') === 'field_image');
  $check('detect null when nothing matches', PublishInfoBuilder::detectFeaturedField([['name' => 'field_x', 'kind' => 'text', 'type' => 'string']], [], 'article') === NULL);

  exit($failures === 0 ? 0 : 1);
}
