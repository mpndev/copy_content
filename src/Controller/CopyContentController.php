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
      $node = Node::load($params[$entity_type]);
      $this->handleDispatchExport($node, $content, (new \ReflectionClass($node))->getName(), $node->bundle());
      $this->removeBadFields($content);
    }
    catch (\Throwable $e) {
      return $this->sendError($e->getMessage());
    }

    return new JsonResponse($content);
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface|Node $entity
   * @param $content
   * @param $full_classname
   * @param $bundle
   *
   * @throws \ReflectionException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  private function handleDispatchExport($entity, &$content, $full_classname, $bundle) {
    $with_field_reference_revision = \Drupal::request()->query->get('with_field_reference_revision');
    $with_field_reference = \Drupal::request()->query->get('with_field_reference');

    $content['full_classname'] = ['value' => $full_classname];
    $content['bundle'] = ['value' => $bundle];
    foreach ($entity->toArray() as $field_name => $entity_field) {
      if (method_exists($entity->get($field_name), 'getFieldDefinition')) {
        /** @var \Drupal\Core\Field\FieldDefinition $field_definition */
        $field_definition = $entity->get($field_name)->getFieldDefinition();
        $field_type = $field_definition->getType();
        $field_value = $entity->get($field_name)->getValue();

        if ($field_type === 'entity_reference_revisions' && $with_field_reference_revision) {
          /** @var \Drupal\Core\Entity\EntityInterface $referenced_entity */
          foreach ($entity->get($field_name)->referencedEntities() as $referenced_entity_index => $referenced_entity) {
            $this->handleDispatchExport($referenced_entity, $content[$field_name][], (new \ReflectionClass($referenced_entity))->getName(), $referenced_entity->bundle());
          }
        }
        else if ($field_type === 'entity_reference' && $with_field_reference) {
          /** @var \Drupal\Core\Entity\EntityInterface $referenced_entity */
          foreach ($entity->get($field_name)->referencedEntities() as $referenced_entity_index => $referenced_entity) {
            $this->handleDispatchExport($referenced_entity, $content[$field_name][], (new \ReflectionClass($referenced_entity))->getName(), $referenced_entity->bundle());
          }
        }
        else if (! ($field_type === 'entity_reference_revisions') && ! ($field_type === 'entity_reference')) {
          foreach ($field_value as $value) {
            $content[$field_name] = $value;
          }
          if ($field_type === 'image' && method_exists($entity->get($field_name)->entity, 'getFileUri')) {
            $content[$field_name]['url'] = $entity->get($field_name)->entity->url();
          }
        }
      }
    }
    unset($content['type']);
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
    $with_field_reference_revision = \Drupal::request()->query->get('with_field_reference_revision');
    $with_field_reference = \Drupal::request()->query->get('with_field_reference');

    $url_parsed = parse_url($path);
    $export_path = '';
    if (!empty($url_parsed['path'])) {
      if (!empty($url_parsed['scheme']) && !empty($url_parsed['host'])) {
        $export_path = $url_parsed['scheme'] . '://' . $url_parsed['host'] . '/admin/content/export?path=' . $url_parsed['path'] . '&with_field_reference_revision=' . $with_field_reference_revision . '&with_field_reference=' . $with_field_reference;
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
      $entity = $response['full_classname']['value']::create(['type' => $response['bundle']['value']]);
      $this->handleEntitiesOnImport($entity, $response);

      return $this->entityFormBuilder()->getForm($entity);
    }
    catch (\Throwable $e) {
      return ['#markup' => t('Failed to reach the target url.')];
    }
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface|Node $entity
   * @param $fields
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function handleEntitiesOnImport(&$entity, $fields) {
    unset($fields['full_classname']);
    unset($fields['bundle']);

    foreach ($fields as $key => $field) {
      if ($this->isSimpleField($field)) {
        $field = $this->fieldIsImage($field) ? $this->handleImage($field) : $field;
        $entity->set($key, $field);
      }
      else if ($this->isNumericArray($field)) {
        foreach ($field as $value) {
          $this->handleEntityCreation($entity, $key, $value);
        }
      }
      else if ($this->isEntity($field)) {
        $this->handleEntityCreation($entity, $key, $field);
      }
    }
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface|Node $entity
   * @param $field_name
   * @param $fields
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function handleEntityCreation(&$entity, $field_name, $fields) {
    $creation_params = ['type' => $fields['bundle']['value']];
    if ($fields['full_classname']['value'] === 'Drupal\taxonomy\Entity\Term') {
      $creation_params = ['vid' => 'tags'];
    }
    $inner_entity = $fields['full_classname']['value']::create($creation_params);
    $this->handleEntitiesOnImport($inner_entity, $fields);
    $entity->get($field_name)->appendItem($inner_entity);
  }

  /**
   * @param $field
   *
   * @return bool
   */
  protected function isSimpleField($field) {
    return array_key_exists('value', $field) || ! empty($field['alt']);
  }

  /**
   * @param $field
   *
   * @return bool
   */
  protected function isNumericArray($field) {
    return ! (count(array_filter(array_keys($field), 'is_string')) > 0);
  }

  /**
   * @param $field
   *
   * @return bool
   */
  protected function isEntity($field) {
    return array_key_exists('full_classname', $field);
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
   */
  public function handleImage(&$field) {
    /** @var File $file */
    $image_url = $field['url'];
    $file = system_retrieve_file($image_url, NULL, TRUE);
    if ($file) {
      $field['target_id'] = $file->id();
    }
    return $field;
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
      'tid',
      'uuid',
      'langcode',
      'created',
      'parent_type',
      'parent_field_name',
      'parent_id',
      'revision_id',
      'paragraph__revision_id',
      'comment',
      'field_tags',
      'revision_timestamp',
      'revision_uid',
      'status',
      'changed',
      'promote',
      'sticky',
      'default_langcode',
      'revision_default',
      'revision_created',
      'revision_translation_affected',
      'path',
      'target_id',
      'behavior_settings',
    ];
    foreach ($data as $key => &$field) {
      if (in_array($key, $not_allowed_fields) && ! is_int($key)) {
        unset($data[$key]);
      }
      if (is_array($data[$key])) {
        $this->removeBadFields($data[$key]);
      }
    }
  }

}

