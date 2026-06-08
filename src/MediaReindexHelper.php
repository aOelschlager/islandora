<?php

declare(strict_types=1);

namespace Drupal\islandora;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Task\IndexTaskManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Helper service for media-triggered node reindexing and cache invalidation.
 *
 * Two things happen when a media entity belonging to an Islandora object
 * changes:
 *
 * 1. Cache-tag invalidation (ALL media changes, insert + update)
 *    The parent node's render-cache entry is invalidated so that any cached
 *    page that displays the node (e.g. a view showing the thumbnail) is
 *    rebuilt on the next request.  This covers thumbnail replacements, service
 *    file swaps, etc.
 *
 * 2. Search API reindex (only for hOCR media, insert + update + delete)
 *    The hOCR use-term indicates that the media contains OCR text that is
 *    indexed against the parent node in Solr.  When the media changes the
 *    node's Solr document is stale, so all active Search API indexes that
 *    track nodes are asked to re-index that specific node.
 */
final class MediaReindexHelper {

  /**
   * The URI of the hOCR media-use term.
   *
   * @see https://discoverygarden.ca/use#hocr
   */
  const HOCR_TERM_URI = 'https://discoverygarden.ca/use#hocr';

  /**
   * The Islandora field that links a media entity to its parent node.
   */
  const MEDIA_OF_FIELD = 'field_media_of';

  /**
   * The Islandora field carrying the media-use taxonomy term reference(s).
   */
  const MEDIA_USE_FIELD = 'field_media_use';

  /**
   * The external URI field on taxonomy terms (mapped from schema:sameAs etc.).
   *
   * Islandora stores term external URIs in the `field_external_uri` field
   * provided by the controlled_access_terms module.
   */
  const TERM_EXTERNAL_URI_FIELD = 'field_external_uri';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
    private readonly ?IndexTaskManagerInterface $indexTaskManager = NULL,
  ) {}

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Central handler called by all three hook implementations.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity that was inserted, updated, or deleted.
   * @param string $op
   *   One of 'insert', 'update', or 'delete'.
   */
  public function handleMediaChange(MediaInterface $media, string $op): void {
    $node = $this->getParentNode($media);
    if ($node === NULL) {
      // Media has no field_media_of value; nothing to do.
      return;
    }

    // 1. Always invalidate the node's render-cache tags on insert or update so
    //    thumbnail / derivative changes appear immediately on cached pages.
    if ($op === 'insert' || $op === 'update') {
      $this->invalidateNodeCacheTags($node);
    }

    // 2. Re-index in Search API only when this is an hOCR media entity.
    if ($this->isHocrMedia($media)) {
      $this->reindexNode($node, $media, $op);
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the node referenced by field_media_of, or NULL if not set.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The parent node, or NULL.
   */
  private function getParentNode(MediaInterface $media): ?NodeInterface {
    if (!$media->hasField(self::MEDIA_OF_FIELD)) {
      return NULL;
    }

    $field = $media->get(self::MEDIA_OF_FIELD);
    if ($field->isEmpty()) {
      return NULL;
    }

    $node = $field->entity;
    if (!($node instanceof NodeInterface)) {
      return NULL;
    }

    return $node;
  }

  /**
   * Returns TRUE when the media carries the hOCR media-use term.
   *
   * The check walks the field_media_use term references and compares each
   * term's field_external_uri value (the canonical approach used throughout
   * Islandora) against the hOCR URI constant.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to inspect.
   *
   * @return bool
   *   TRUE if the hOCR term is present.
   */
  private function isHocrMedia(MediaInterface $media): bool {
    if (!$media->hasField(self::MEDIA_USE_FIELD)) {
      return FALSE;
    }

    foreach ($media->get(self::MEDIA_USE_FIELD) as $item) {
      /** @var \Drupal\taxonomy\TermInterface|null $term */
      $term = $item->entity;
      if ($term === NULL) {
        continue;
      }

      // Prefer field_external_uri (controlled_access_terms standard).
      if ($term->hasField(self::TERM_EXTERNAL_URI_FIELD)) {
        foreach ($term->get(self::TERM_EXTERNAL_URI_FIELD) as $uri_item) {
          if ($uri_item->uri === self::HOCR_TERM_URI) {
            return TRUE;
          }
        }
      }

      // Fallback: some deployments store the URI directly on the term name or
      // as the term's own URI property (e.g. the "external_url" computed
      // field).  Accept a match on term name as a last resort.
      if ($term->getName() === self::HOCR_TERM_URI) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Invalidates the node's render-cache tags.
   *
   * This is intentionally cheap: Drupal's cache-tag system handles the
   * propagation to all cache bins that hold entries tagged with node:<id>.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The parent node.
   */
  private function invalidateNodeCacheTags(NodeInterface $node): void {
    $tags = $node->getCacheTagsToInvalidate();
    $this->cacheTagsInvalidator->invalidateTags($tags);

    $this->logger->debug(
      'Invalidated cache tags for node @nid (@label) due to media change.',
      ['@nid' => $node->id(), '@label' => $node->label()],
    );
  }

  /**
   * Queues the parent node for re-indexing across all relevant Search API
   * indexes.
   *
   * Uses the Search API index task manager (available since Search API 1.14)
   * to schedule a background re-index rather than performing a synchronous
   * one.  If the index task manager is not available (very old Search API),
   * falls back to a direct tracker update.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to re-index.
   * @param \Drupal\media\MediaInterface $media
   *   The media that triggered the change (used only for log messages).
   * @param string $op
   *   The triggering operation ('insert', 'update', or 'delete').
   */
  private function reindexNode(
    NodeInterface $node,
    MediaInterface $media,
    string $op,
  ): void {
    $indexes = $this->getNodeIndexes();
    if (empty($indexes)) {
      $this->logger->warning(
        'islandora: No active Search API indexes found that track nodes. Cannot reindex node @nid.',
        ['@nid' => $node->id()],
      );
      return;
    }

    foreach ($indexes as $index) {
      $this->markNodeForReindex($index, $node);

      $this->logger->info(
        'islandora: Queued node @nid for reindex on index "@index" — media @mid (@op, hOCR).',
        [
          '@nid'   => $node->id(),
          '@index' => $index->id(),
          '@mid'   => $media->id(),
          '@op'    => $op,
        ],
      );
    }
  }

  /**
   * Returns all enabled Search API indexes that datasource-track nodes.
   *
   * @return \Drupal\search_api\IndexInterface[]
   *   Keyed by index machine name.
   */
  private function getNodeIndexes(): array {
    /** @var \Drupal\search_api\IndexInterface[] $all */
    $all = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadByProperties(['status' => TRUE]);

    $node_indexes = [];
    foreach ($all as $index) {
      foreach ($index->getDatasources() as $datasource) {
        // The entity datasource plugin ID for nodes is
        // "entity:node".
        if ($datasource->getPluginId() === 'entity:node') {
          $node_indexes[$index->id()] = $index;
          break;
        }
      }
    }

    return $node_indexes;
  }

  /**
   * Marks a single node as needing re-indexing on the given index.
   *
   * Prefers the IndexTaskManager (Search API ≥ 1.14) to batch the work.
   * Falls back to calling the tracker directly for older versions.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The target Search API index.
   * @param \Drupal\node\NodeInterface $node
   *   The node to mark.
   */
  private function markNodeForReindex(IndexInterface $index, NodeInterface $node): void {
    // Build the datasource item ID: "entity:node/<nid>:<langcode>".
    // Search API tracks items per-language.
    $item_ids = [];
    $raw_ids = [];
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $item_ids[] = 'entity:node/' . $node->id() . ':' . $langcode;
      $raw_ids[] = $node->id() . ':' . $langcode;
      $id_array = $node->id() . ':' . $langcode;

      if (empty($item_ids)) {
        return;
      }

      try {
        if ($this->indexTaskManager !== NULL) {
          // IndexTaskManager::addItemsToIndex() accepts raw item IDs per
          // datasource.  The item IDs here are already prefixed with the
          // datasource plugin ID so we strip that prefix to get the raw IDs
          // the tracker expects.
          //$raw_ids = array_map(
          //  static fn(string $id) => substr($id, strlen('entity:node/')),
          //  $item_ids,
          //);

          /** @var \Drupal\search_api\Tracker\TrackerInterface $tracker */
          $tracker = $index->getTrackerInstance();
          $tracker->trackItemsUpdated('entity:node', array($id_array));
       }
        else {
          // Older Search API: call the tracker directly.
          /** @var \Drupal\search_api\Tracker\TrackerInterface $tracker */
          $tracker = $index->getTrackerInstance();
          //$raw_ids = array_map(
          //  static fn(string $id) => substr($id, strlen('entity:node/')),
          //  $item_ids,
          //);
          $tracker->trackItemsUpdated('entity:node', array($id_array));
        }
      }
      catch (\Exception $e) {
        $this->logger->error(
          'islandora: Failed to mark node @nid for reindex on "@index": @message',
          [
            '@nid'     => $node->id(),
            '@index'   => $index->id(),
            '@message' => $e->getMessage(),
          ],
        );
      }
    }
  }

}