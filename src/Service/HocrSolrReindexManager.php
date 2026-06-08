<?php

namespace Drupal\islandora\Service;

use Drupal\media\MediaInterface;
use Drupal\search_api\Entity\Index;

class HocrSolrReindexManager {

  const HOCR_URI = 'https://discoverygarden.ca/use#hocr';

  /**
   * Process a media entity and mark referenced nodes for reindex.
   */
  public function process($entity): void {
    if (!$entity instanceof MediaInterface) {
      return;
    }

    if (!$this->isHocrMedia($entity)) {
      return;
    }

    if (!$entity->hasField('field_media_of') || $entity->get('field_media_of')->isEmpty()) {
      return;
    }

    $nids = [];

    foreach ($entity->get('field_media_of')->referencedEntities() as $node) {
      $nids[] = $node->id();
    }

    if ($nids) {
      $this->markNodes($nids);
    }
  }

  /**
   * Check Media Use = HOCR.
   */
  protected function isHocrMedia(MediaInterface $media): bool {
    if (!$media->hasField('field_media_use') || $media->get('field_media_use')->isEmpty()) {
      return FALSE;
    }

    foreach ($media->get('field_media_use')->referencedEntities() as $term) {
      if ($term->hasField('field_external_uri')) {
        if ($term->get('field_external_uri')->value === self::HOCR_URI) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Mark nodes for Search API reindex (Solr).
   */
  protected function markNodes(array $nids): void {
    $indexes = Index::loadMultiple();

    foreach ($indexes as $index) {
      if ($index->getDatasource('entity:node')) {
        $index->trackItemsUpdated('entity:node', $nids);
      }
    }
  }

}