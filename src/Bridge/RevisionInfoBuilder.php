<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Bridge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Builds revision list and rendered-revision payloads for the editor.
 */
final class RevisionInfoBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Builds the revision list payload for an entity.
   *
   * Task 3 fills this in; this task ships the pure helper.
   */
  public function buildList(ContentEntityInterface $entity): array {
    return [];
  }

  /**
   * Builds the rendered-revision payload.
   *
   * Task 3 fills this in; this task ships the pure helper.
   */
  public function buildRevisionView(ContentEntityInterface $revision): array {
    return [];
  }

  /**
   * Normalizes and orders revision rows, newest first.
   *
   * @param array<int, array<string, mixed>> $rows
   *
   * @return array<int, array<string, mixed>>
   */
  public static function formatList(array $rows): array {
    $normalized = [];
    foreach ($rows as $row) {
      $normalized[] = [
        'vid' => (int) ($row['vid'] ?? 0),
        'isDefault' => (bool) ($row['isDefault'] ?? FALSE),
        'timestamp' => (int) ($row['timestamp'] ?? 0),
        'authorId' => (int) ($row['authorId'] ?? 0),
        'authorName' => (string) ($row['authorName'] ?? ''),
        'log' => (string) ($row['log'] ?? ''),
      ];
    }
    usort($normalized, static fn (array $a, array $b): int => [$b['timestamp'], $b['vid']] <=> [$a['timestamp'], $a['vid']]);
    return $normalized;
  }

}
