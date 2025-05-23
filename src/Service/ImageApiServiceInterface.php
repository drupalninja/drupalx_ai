<?php

namespace Drupal\drupalx_ai\Service;

/**
 * Interface for image API services.
 */
interface ImageApiServiceInterface {

  /**
   * Fetches an image from the API based on a search term.
   *
   * @param string $searchTerm
   *   The search term to query the API with.
   *
   * @return array|null
   *   An array containing 'url' and 'alt' for the image if found, otherwise NULL.
   */
  public function fetchImageFromApi(string $searchTerm): ?array;

}
