<?php

class PhotoDB {
  public function __construct($config) {
    try {
      $dbConnect = sprintf("sqlite:%s", $config->appDB);
      $this->db = new PDO($dbConnect);
    }
    catch (Exception $e) {
      die($e->getMessage() . "\n");
    }
  }

  /**
   * Shotwell uses a form of the photo id that looks like 'thumb' + 16
   * hexadecimal digits of the photo id for linking a photo with the
   * associated tags.  This converts the integer id to the thumb variant.
   */
  public function getPhotoId($id) {
    $photoId = sprintf('thumb%16x', $id);
    $photoId = str_replace(' ', '0', $photoId);
    return $photoId;
  }

  public function getMovieId($id) {
    $movieId = sprintf('video-%16x', $id);
    $movieId = str_replace(' ', '0', $movieId);
    return $movieId;
  }

  function getPhotos($config, $args) {
    // Construct the where clause:
    $where = array();
    if (isset($args->from)) {
      // Convert to Unix timestamp.
      $date = new DateTime($args->from);
      $where[] = sprintf("exposure_time >= %s", $this->db->quote($date->format('U')));
    }
    if (isset($args->to)) {
      // Convert to Unix timestamp.
      $date = new DateTime($args->to);
      $where[] = sprintf("exposure_time <= %s", $this->db->quote($date->format('U')));
    }
    if (isset($args->rating)) {
      $matches = array();
      $count = preg_match('/^(\<=|\>=|\<|\>|\=)(.*)$/', $args->rating, $matches);
      if (count($matches) === 3) {
	$operation = $matches[1];
	$rating = $matches[2];
	$where[] = sprintf("rating %s %s", $operation, $this->db->quote($rating));
      }
      else {
	die("-r requires an operation and a rating, like '>=3'.  Instead " . $args->rating . " was passed. (count of matches is " . count($matches) . ".\n");
      }
    }

    // Ignore all images that are deleted.
    $where[] = '(flags & 4) != 4';
    if (count($where) > 0) {
      $whereClause = sprintf("WHERE %s", implode(" AND ", $where));
    }
    else {
      $whereClause = '';
    }

    // Do not sanitize the where clause; the individual elements have
    // already been sanitized.
    $query = sprintf("SELECT * FROM PhotoTable %s", $whereClause);
    $result = $this->db->query($query);
    return $result;
  }

  function getMovies($config, $args) {
    // Construct the where clause:
    $where = array();
    if (isset($args->from)) {
      // Convert to Unix timestamp.
      $date = new DateTime($args->from);
      $where[] = sprintf("exposure_time >= %s", $this->db->quote($date->format('U')));
    }
    if (isset($args->to)) {
      // Convert to Unix timestamp.
      $date = new DateTime($args->to);
      $where[] = sprintf("exposure_time <= %s", $this->db->quote($date->format('U')));
    }
    if (isset($args->rating)) {
      $matches = array();
      $count = preg_match('/^(\<=|\>=|\<|\>|\=)(.*)$/', $args->rating, $matches);
      if (count($matches) === 3) {
	$operation = $matches[1];
	$rating = $matches[2];
	$where[] = sprintf("rating %s %s", $operation, $this->db->quote($rating));
      }
      else {
	die("-r requires an operation and a rating, like '>=3'.  Instead " . $args->rating . " was passed. (count of matches is " . count($matches) . ".\n");
      }
    }

    // Ignore all movies that are deleted.
    $where[] = '(flags & 4) != 4';
    if (count($where) > 0) {
      $whereClause = sprintf("WHERE %s", implode(" AND ", $where));
    }
    else {
      $whereClause = '';
    }

    // Do not sanitize the where clause; the individual elements have
    // already been sanitized.
    $query = sprintf("SELECT * FROM VideoTable %s", $whereClause);
    $result = $this->db->query($query);
    return $result;
  }

  function find($rating = ">= '0'", $tag = NULL) {
    $fields = 'id, filename, rating';
    $query = sprintf("SELECT %s FROM PhotoTable WHERE rating %s", $this->db->quote($fields), $this->db->quote($rating));
    $result = $this->db->query($query);
    return $result;
  }

  function getTags($id, $type = PHOTO) {
    switch ($type) {
    case PHOTO:
      $itemId = $this->getPhotoId($id);
      break;

    case MOVIE:
      $itemId = $this->getMovieId($id);
      break;

    default:
      throw new Exception(sprintf('Unknown type passed to getTags method: "%s".', $type));
    }

    $tagQuery = sprintf("SELECT * FROM TagTable WHERE photo_id_list LIKE %s", $this->db->quote("%${itemId}%"));
    $result = $this->db->query($tagQuery);
    $tags = array();
    while ($tag = $result->fetchObject()) {
      $tags[] = $tag->name;
    }
    return $tags;
  }

  function getAllTags() {
    $query = "SELECT name FROM TagTable";
    $result = $this->db->query($query);
    $tags = array();
    while ($tag = $result->fetchObject()) {
      $tags[] = $tag->name;
    }
    return $tags;
  }

  function close() {
    unset($this->db);
  }
}
