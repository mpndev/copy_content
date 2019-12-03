<?php

namespace Drupal\copy_content\Controller;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class CopyContentController extends ControllerBase {

  /**
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function export() {
    $content = [];
    try {
      $path = \Drupal::request()->query->get('path');
      $params = Url::fromUri("internal:" . $path)->getRouteParameters();
      $entity_type = key($params);
      /** @var \Drupal\node\Entity\Node $node */
      $node = Node::load($params[$entity_type]);
      $this->handleEntitiesOnExport($node, $content);
    }
    catch (\Throwable $e) {
      return $this->sendError($e->getMessage());
    }

    return new JsonResponse($content);
  }

  /**
   * @param $entity
   * @param $content
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function handleEntitiesOnExport($entity, &$content) {
    $with_paragraphs = \Drupal::request()->query->get('with_paragraphs');
    $with_images = \Drupal::request()->query->get('with_images');

    foreach ($entity->toArray() as $key => $field) {
      if (!$entity->get($key)->isEmpty()) {
        $entity_field_values = $entity->get($key)->getValue();
        foreach ($entity_field_values as $delta => $entity_field_value) {
          foreach ($entity_field_value as $value_name => $value) {
            $field_definition = $entity->get($key)->getFieldDefinition();
            if ($field_definition->getType() === 'entity_reference_revisions') {
              if ($with_paragraphs) {
                $target_type = $field_definition->getItemDefinition()->getSetting('target_type');
                $target = \Drupal::entityTypeManager()->getStorage($target_type)->load($entity_field_value['target_id']);
                $this->handleEntitiesOnExport($target, $content[$key][$delta]);
              }
              else {
                unset($content[$key]);
              }
            }
            else {
              $content[$key][$value_name] = $value;
              if ($field_definition->getType() === 'image') {
                if ($with_images) {
                  $content[$key]['url'] = file_create_url($entity->get($key)->entity->getFileUri());
                }
                else {
                  unset($content[$key]);
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * @param $message
   * @param int $code
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  private function sendError($message, $code = 404) {
    return new JsonResponse(['error' => $message], $code);
  }

  /**
   * @return array
   */
  public function import() {
    $path = \Drupal::request()->query->get('path');
    $with_paragraphs = \Drupal::request()->query->get('with_paragraphs');
    $with_images = \Drupal::request()->query->get('with_images');

    $url_parsed = parse_url($path);
    $export_path = '';
    if (!empty($url_parsed['path'])) {
      if (!empty($url_parsed['scheme']) && !empty($url_parsed['host'])) {
        $export_path = $url_parsed['scheme'] . '://' . $url_parsed['host'] . '/admin/content/export?path=' . $url_parsed['path'] . '&with_paragraphs=' . $with_paragraphs . '&with_images=' . $with_images;
      }
      else {
        $host = \Drupal::request()->getSchemeAndHttpHost();
        $export_path = $host . '/admin/content/export?path=' . $url_parsed['path'];
      }
    }

    try {
      /** @var \GuzzleHttp\Client $client */
      $client = \Drupal::httpClient();
      /** @var \GuzzleHttp\Psr7\Response $request */
      $request = $client->get($export_path);
      $response = json_decode($request->getBody()->getContents(), TRUE);
      $this->removeBadFields($response);
      $node = Node::create(['type' => $response['type']['target_id']]);
      $this->handleEntitiesOnImport($node, $response);

      return $this->entityFormBuilder()->getForm($node);
    }
    catch (\Throwable $e) {
      return ['#markup' => t('Failed to reach the target url.')];
    }
  }

  /**
   * @param $entity
   * @param $fields
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function handleEntitiesOnImport(&$entity, $fields) {
    foreach ($fields as $key => $field) {
      if ($this->isAssoc($field)) {
        $field = $this->fieldIsImage($field) ? $this->handleImage($field) : $field;
        $entity->set($key, $field);
      }
      else if (is_array($field)) {
        foreach ($field as $key2 => $inner_field_data) {
          $target_id = $inner_field_data['type']['target_id'];
          $target_type = $entity->getFieldDefinition($key)->getSetting('target_type');
          $namespace_entities = 'Drupal\paragraphs\Entity\\';
          $inner_entity = ($namespace_entities . ucfirst($target_type))::create(['type' => $target_id]);

          $inner_entity->save();
          $this->handleEntitiesOnImport($inner_entity, $inner_field_data);
          $entity->get($key)->appendItem($inner_entity);
        }
      }
    }
  }

  /**
   * @param $field
   *
   * @return bool
   */
  private function fieldIsImage($field) {
    return (array_key_exists('alt', $field) && $field['alt'] != '');
  }

  /**
   * @param $field
   *
   * @return mixed
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function handleImage(&$field) {
    /** @var File $file */
    $image_url = $field['url'];
    $file = system_retrieve_file($image_url, NULL, TRUE);
    if ($file) {
      $file->setPermanent();
      $file->save();
      unset($field['image_url']);
      $field['target_id'] = $file->id();
    }
    return $field;
  }

  /**
   * @param array $arr
   *
   * @return bool
   */
  private function isAssoc(array $arr) {
      if (array() === $arr) return false;
      return array_keys($arr) !== range(0, count($arr) - 1);
  }

  /**
   * @param $data
   */
  private function removeBadFields(&$data) {
    $not_allowed_fields = [
      'id',
      'nid',
      'uid',
      'vid',
      'uuid',
      'created',
      'parent_type',
      'parent_field_name',
      'parent_id',
      'revision_id',
      'paragraph__revision_id',
      'comment',
      'field_tags',
    ];
    foreach ($data as $key => &$field) {
      if (in_array($key, $not_allowed_fields)) {
        unset($data[$key]);
      }
      if (is_array($data[$key])) {
        foreach ($data[$key] as $child_key => &$child) {
          if (is_int($child_key)) {
            $this->removeBadFields($child);
          }
        }
      }
    }
  }

}

