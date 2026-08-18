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

  public function buildList(ContentEntityInterface $entity): array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $vids = array_keys($storage->getQuery()
      ->allRevisions()
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->accessCheck(FALSE)
      ->execute());

    $rows = [];
    foreach ($vids as $vid) {
      $revision = $storage->loadRevision($vid);
      if (!$revision) {
        continue;
      }
      $user = method_exists($revision, 'getRevisionUser') ? $revision->getRevisionUser() : NULL;
      $rows[] = [
        'vid' => (int) $vid,
        'isDefault' => (bool) $revision->isDefaultRevision(),
        'timestamp' => method_exists($revision, 'getRevisionCreationTime') ? (int) $revision->getRevisionCreationTime() : 0,
        'authorId' => $user ? (int) $user->id() : 0,
        'authorName' => $user ? (string) $user->label() : '',
        'log' => method_exists($revision, 'getRevisionLogMessage') ? (string) $revision->getRevisionLogMessage() : '',
      ];
    }

    return self::formatList($rows);
  }

  public function buildRevisionView(ContentEntityInterface $revision): array {
    $user = method_exists($revision, 'getRevisionUser') ? $revision->getRevisionUser() : NULL;
    $view_builder = $this->entityTypeManager->getViewBuilder($revision->getEntityTypeId());
    $build = $view_builder->view($revision, 'full');
    $html = (string) $this->renderer->renderPlain($build);

    return [
      'vid' => (int) $revision->getRevisionId(),
      'title' => (string) $revision->label(),
      'html' => $html,
      'timestamp' => method_exists($revision, 'getRevisionCreationTime') ? (int) $revision->getRevisionCreationTime() : 0,
      'authorName' => $user ? (string) $user->label() : '',
      'log' => method_exists($revision, 'getRevisionLogMessage') ? (string) $revision->getRevisionLogMessage() : '',
    ];
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
