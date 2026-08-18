<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\gutenberg_next\Bridge\RevisionInfoBuilder;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only revision payloads for the editor revision browser.
 */
final class RevisionController extends ControllerBase {

  public function __construct(
    private readonly RevisionInfoBuilder $revisionInfoBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('gutenberg_next.revision_info_builder'));
  }

  /**
   * Custom access for the revision list route.
   *
   * Mirrors core's NodeRevisionAccessCheck semantics (verified against the
   * site's core source during implementation).
   */
  public function revisionListAccess(Node $node, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(gutenberg_next_revision_view_access($node, $account))
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($node);
  }

  public function revisionList(Node $node): JsonResponse {
    return new JsonResponse([
      'revisions' => $this->revisionInfoBuilder->buildList($node),
    ]);
  }

  public function revisionView(Node $node, Node $node_revision): JsonResponse {
    return new JsonResponse($this->revisionInfoBuilder->buildRevisionView($node_revision));
  }

}
