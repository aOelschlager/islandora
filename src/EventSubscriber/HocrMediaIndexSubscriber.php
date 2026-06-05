<?php

namespace Drupal\islandora\EventSubscriber;

use Drupal\Core\Entity\EntityCrudHookEvents;
use Drupal\Core\Entity\Event\EntityCrudEvent;
use Drupal\media\MediaInterface;
use Drupal\search_api\Entity\Index;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class HocrMediaIndexSubscriber implements EventSubscriberInterface {

  /**
   * URI identifying HOCR Media Use.
   */
  const HOCR_URI = 'https://discoverygarden.ca/use#hocr';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityCrudHookEvents::ENTITY_INSERT => 'onMediaSave',
      EntityCrudHookEvents::ENTITY_UPDATE => 'onMediaSave',
    ];
  }

  /**
   * Handles media creation and updates.
   */
  public function onMediaSave(EntityCrudEvent $event): void {
    $entity = $event->getEntity();

    if (!$entity instanceof MediaInterface) {
      return;
    }

    if (!$this->isHocrMedia($entity)) {
      return;
    }

    if (!$entity->hasField('field_media_of') || $entity->get('field_media_of')->isEmpty()) {
      return;
    }

    $node_ids = [];

    foreach ($entity->get('field_media_of')->referencedEntities() as $node) {
      $node_ids[] = $node->id();
    }

    if ($node_ids) {
      $this->markNodesForReindex($node_ids);
    }
  }

  /**
   * Checks whether media has the HOCR media use term.
   */
  protected function isHocrMedia(MediaInterface $media): bool {
    if (!$media->hasField('field_media_use') || $media->get('field_media_use')->isEmpty()) {
      return FALSE;
    }

    foreach ($media->get('field_media_use')->referencedEntities() as $term) {
      if ($term->hasField('field_external_uri') && $term->get('field_external_uri')->value === self::HOCR_URI) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Marks nodes as updated in Search API.
   */
  protected function markNodesForReindex(array $node_ids): void {
    $indexes = Index::loadMultiple();

    foreach ($indexes as $index) {
      if ($index->getDatasource('entity:node')) {
        $index->trackItemsUpdated('entity:node', $node_ids);
      }
    }
  }

}
