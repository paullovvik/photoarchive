<?php

class ArchiveDB {
  public function __construct($config) {
    try {
      $createTables = FALSE;
      if (!file_exists($config->originalsDB)) {
	$createTables = TRUE;
      }
      $dbConnect = sprintf('sqlite:%s', $config->originalsDB);
      $this->db = new PDO($dbConnect);
      if ($createTables) {
	$this->createTables();
      }
    }
    catch (Exception $e) {
      die($e->getMessage() . "\n");
    }
  }

  private function createTables() {
    $photoTable = <<<EOT
CREATE TABLE Photo (pid INTEGER PRIMARY KEY, filename TEXT UNIQUE NOT NULL, jpeg_filename TEXT UNIQUE, web_filename TEXT UNIQUE, width INTEGER, height INTEGER, md5 TEXT, jpeg_md5 TEXT, web_md5 TEXT, exposure_time INTEGER, rating INTEGER DEFAULT 0, modified INTEGER DEFAULT 0);
EOT;
    $tagTable = <<<EOT
CREATE TABLE Tag (tid INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL);
EOT;
    $photoTagTable = <<<EOT
CREATE TABLE PhotoTag (ptid INTEGER PRIMARY KEY, photo_id INTEGER, tag_id INTEGER);
EOT;
    $this->db->exec($photoTable);
    $this->db->exec($tagTable);
    $this->db->exec($photoTagTable);
  }

  function getPhotos($args) {
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
    if (count($where) > 0) {
      $whereClause = sprintf("WHERE %s", implode(" AND ", $where));
    }
    else {
      $whereClause = '';
    }

    // Do not sanitize the where clause; the individual elements have
    // already been sanitized.
    $query = sprintf("SELECT * FROM Photo %s", $whereClause);
    $result = $this->db->query($query);
    return $result;
  }

  public function updatePhoto(&$photo) {
    // Is the photo already in the archive?
    $query = $this->db->query(sprintf("SELECT pid FROM Photo WHERE filename = %s", $this->db->quote($photo->filename)));
    if ($query && $data = $query->fetchObject()) {
      // Row exists.
      $photo->pid = $data->pid;
      $update = sprintf("UPDATE Photo SET filename = %s, width = %s, height = %s, md5 = %s, jpeg_filename = %s, jpeg_md5 = %s, web_filename = %s, web_md5 = %s, exposure_time = %s, rating = %s, modified = %s WHERE pid = %s",
$this->db->quote($photo->filename), $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->jpeg_filename), $this->db->quote($photo->jpeg_md5), $this->db->quote($photo->web_filename), $this->db->quote($photo->web_md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating), $this->db->quote($photo->modified ? 1 : 0), $this->db->quote($photo->pid));
      $this->db->exec($update);
    }
    else {
      // Need a new row.
      $insert = sprintf("INSERT INTO Photo (filename, width, height, md5, jpeg_filename, jpeg_md5, web_filename, web_md5, exposure_time, rating, modified) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
        $this->db->quote($photo->filename), $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->jpeg_filename), $this->db->quote($photo->jpeg_md5), $this->db->quote($photo->web_filename), $this->db->quote($photo->web_md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating), $this->db->quote($photo->modified ? 1 : 0));
      $this->db->exec($insert);
    }

    // Set the photo id.
    if (empty($photo->pid)) {
      $queryString = sprintf("SELECT pid FROM Photo WHERE filename = %s", $this->db->quote($photo->filename));
      $query = $this->db->query($queryString);
      if ($query && $data = $query->fetchObject()) {
	$photo->pid = $data->pid;
      }
    }
    $this->savePhotoTags($photo);
  }

  public function createTags($tags) {
    if (empty($tags) || count($tags) == 0) {
      return;
    }
    $query = "SELECT * FROM Tag WHERE name = %s";
    for ($i = 0, $len = count($tags); $i < $len; $i++) {
      $tag = $this->db->query(sprintf($query, $this->db->quote($tags[$i])));
      if (!empty($tag)) {
	$data = $tag->fetchObject();
	if ($data && $data->name == $tags[$i]) {
	  // The tag exists.
	}
	else {
	  // The tag does not yet exist.
	  $insert = sprintf("INSERT INTO Tag (name) VALUES (%s)", $this->db->quote($tags[$i]));
	  $this->db->exec($insert);
	}
      }
    }
  }

  private function savePhotoTags(&$photo) {
    if (empty($photo->tags) || count($photo->tags) == 0) {
      return;
    }
    $this->createTags($photo->tags);
    $tagQuery = "SELECT * FROM Tag WHERE name = %s";
    $photoQuery = "SELECT * FROM PhotoTag WHERE photo_id = %s AND tag_id = %s";
    for ($i = 0, $len = count($photo->tags); $i < $len; $i++) {
      $tag = $this->db->query(sprintf($tagQuery, $this->db->quote($photo->tags[$i])));
      if (!empty($tag)) {
	$data = $tag->fetchObject();
	if ($data && $data->name == $photo->tags[$i]) {
	  // Got the tag id.  Does the relationship already exist?
	  $relationship = $this->db->query(sprintf($photoQuery, $this->db->quote($photo->pid), $this->db->quote($data->tid)));
	  if ($relationship && $r = $relationship->fetchObject()) {
	    // It already exists.
	  }
	  else {
	    $this->db->exec(sprintf("INSERT INTO PhotoTag (photo_id, tag_id) VALUES (%s, %s)", $this->db->quote($photo->pid), $this->db->quote($data->tid)));
	  }
	}
      }
    }
  }

  public function getIdFromFilename($filename) {

  }

  public function loadOriginalPhoto($photoId) {

  }

  public function loadJpegPhoto($photoId) {

  }

  public function loadWebPhoto($photo) {

  }

  public function loadPhoto($config, $photoId) {
    $photoQuery = $this->db->query(sprintf("SELECT * FROM Photo WHERE pid = %s", $this->db->quote($photoId)));
    $photo = $photoQuery->fetchObject();
    $photo->tags = $this->getTags($photoId);
    $this->addPaths($config, $photo);

    return $photo;
  }

  // TODO: You are here.  Should save the photo, then verify that the tags are in order.
  public function savePhoto($config, $photo) {
    $this->createTags($photo->tags);
    if (!empty($photo->pid)) {
      // Make sure the entry doesn't exist...
      $photoResult = $this->db->query(sprintf("SELECT pid FROM Photo WHERE filename = %s", $this->db->quote($photo->filename)));
      if ($photoResult && $photoId = $photoResult->fetchObject()) {
	$photo->pid = $photoId->pid;
      }
    }
    // Now we have the photo id or it is an insert.
    if (!empty($photo->id)) {
      // Update
      $this->db->exec(sprintf("UPDATE Photo set width = %s, height = %s, md5 = %s, exposure_time = %s, rating = %s WHERE pid = %s)",
        $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating), $this->db->quote($photo->pid)));
    }
    else {
      // Insert TODO: You are here
      $this->db->exec(sprintf("INSERT INTO Photo (filename, width, height, md5, exposure_time, rating) VALUES (%s, %s, %s, %s, %s)",
      $this->db->quote($photo->filename), $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating)));
    }
    // Tag associations go here
    $this->savePhotoTags($photo);
  }

  private function addPaths($config, &$photo) {
    $filename = pathinfo($photo->filename, PATHINFO_FILENAME);
    $path = dirname($photo->filename);
    $photo->original_filename = $config->originalsDirectory . $photo->filename;
    $photo->jpeg_filename = $config->jpegsDirectory . "${path}/${filename}.JPG";
    $photo->share_filename = $config->shareDirectory . "${path}/${filename}.JPG";
  }

  public function getTags($photoId) {
    // TODO: Could I use fetchColumn to make this more efficient?
    $tags = array();
    $tagQuery = $this->db->query(sprintf("SELECT photo_id, tag_id, name FROM PhotoTag join Tag on tid = tag_id WHERE photo_id = %s", $this->db->quote($photoId)));
    while ($tag = $tagQuery->fetchObject()) {
      $tags[] = $tag->name;
    }
    return $tags;
  }

  public function close() {
    unset($this->db);
  }
}
