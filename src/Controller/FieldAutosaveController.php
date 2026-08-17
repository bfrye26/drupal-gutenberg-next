<?php

declare(strict_types=1);

namespace Drupal\gutenberg_next\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-user autosave snapshots of Drupal field values.
 */
final class FieldAutosaveController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function save(Request $request, string $entity_type, string $entity_id): JsonResponse {
    $entity = $this->checkAccess($entity_type, $entity_id);
    $payload = json_decode($request->getContent(), TRUE);
    $fields = is_array($payload) && is_array($payload['fields'] ?? NULL) ? $payload['fields'] : NULL;
    if ($fields === NULL) {
      return new JsonResponse(['error' => 'Invalid payload: "fields" object required.'], 400);
    }

    $fields = $this->filterKnownFields($entity_type, $entity->bundle(), $fields);

    $this->database->merge('gutenberg_next_field_autosave')
      ->keys([
        'uid' => (int) $this->currentUser()->id(),
        'entity_type' => $entity_type,
        'entity_id' => (int) $entity_id,
      ])
      ->fields([
        'bundle' => $entity->bundle(),
        'data' => serialize($fields),
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    return new JsonResponse(['saved' => TRUE, 'changed' => \Drupal::time()->getRequestTime()]);
  }

  public function load(string $entity_type, string $entity_id): JsonResponse {
    $this->checkAccess($entity_type, $entity_id);
    $row = $this->database->select('gutenberg_next_field_autosave', 'a')
      ->fields('a', ['data', 'changed'])
      ->condition('uid', (int) $this->currentUser()->id())
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', (int) $entity_id)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return new JsonResponse(['data' => NULL, 'changed' => NULL]);
    }

    return new JsonResponse([
      'data' => unserialize($row['data'], ['allowed_classes' => FALSE]),
      'changed' => (int) $row['changed'],
    ]);
  }

  public function clear(string $entity_type, string $entity_id): JsonResponse {
    $this->checkAccess($entity_type, $entity_id);
    $this->database->delete('gutenberg_next_field_autosave')
      ->condition('uid', (int) $this->currentUser()->id())
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', (int) $entity_id)
      ->execute();

    return new JsonResponse(['cleared' => TRUE]);
  }

  private function checkAccess(string $entity_type, string $entity_id): object {
    $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      throw new NotFoundHttpException();
    }
    if (!$entity->access('update')) {
      throw new AccessDeniedHttpException();
    }
    return $entity;
  }

  private function filterKnownFields(string $entity_type, string $bundle, array $fields): array {
    $definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($entity_type, $bundle);

    return array_intersect_key($fields, $definitions);
  }

}
