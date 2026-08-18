<?php

declare(strict_types=1);

/**
 * Self-check for the RevisionInfoBuilder pure helper.
 *
 * Runs without a Drupal installation (the helper is static and the class is
 * never instantiated here). Usage:
 *
 *   php tests/check-revision-info.php
 *
 * Exits non-zero if any check fails.
 */

namespace {
  require __DIR__ . '/../src/Bridge/RevisionInfoBuilder.php';

  use Drupal\gutenberg_next\Bridge\RevisionInfoBuilder;

  $failures = 0;
  $check = static function (string $message, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
  };

  $rows = [
    ['vid' => 1, 'isDefault' => FALSE, 'timestamp' => 9000, 'authorId' => 2, 'authorName' => 'Ada', 'log' => 'first'],
    ['vid' => 3, 'isDefault' => TRUE, 'timestamp' => 3000, 'authorId' => 1],
    ['vid' => 2, 'isDefault' => FALSE, 'timestamp' => 3000, 'authorId' => 2, 'authorName' => 'Ada', 'log' => ''],
  ];
  $formatted = RevisionInfoBuilder::formatList($rows);

  $check('newest-first by timestamp', array_column($formatted, 'vid') === [1, 3, 2]);
  $check('timestamp tie broken by vid descending', $formatted[1]['vid'] === 3 && $formatted[2]['vid'] === 2);
  $check('missing log defaults to empty string', $formatted[1]['log'] === '');
  $check('missing authorName defaults to empty string', $formatted[1]['authorName'] === '');
  $check('defaults preserved on complete rows', $formatted[0]['log'] === 'first' && $formatted[0]['authorName'] === 'Ada');
  $check('isDefault cast to bool', $formatted[1]['isDefault'] === TRUE && $formatted[0]['isDefault'] === FALSE);
  $check('empty input returns empty list', RevisionInfoBuilder::formatList([]) === []);

  exit($failures === 0 ? 0 : 1);
}
