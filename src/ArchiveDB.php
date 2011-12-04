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
    $movieTable = <<<EOT
      CREATE TABLE Movie (pid INTEGER PRIMARY KEY, filename TEXT UNIQUE NOT NULL, width INTEGER, height INTEGER, duration REAL, filesize INTEGER, md5 TEXT, exposure_time INTEGER, rating INTEGER DEFAULT 0);
EOT;
    $movieTagTable = <<<EOT
CREATE TABLE MovieTag (ptid INTEGER PRIMARY KEY, movie_id INTEGER, tag_id INTEGER);
EOT;

    $this->db->exec($photoTable);
    $this->db->exec($tagTable);
    $this->db->exec($photoTagTable);
    $this->db->exec($movieTable);
    $this->db->exec($movieTagTable);
  }

  function getPhotos($config, $args) {
    return $this->getItems($config, $args, PHOTO);
  }

  function getMovies($config, $args) {
    return $this->getItems($config, $args, MOVIE);
  }

  function getItems($config, $args, $type) {
    switch($type) {
    case PHOTO:
      $table = 'Photo';
      $tag_table = 'PhotoTag';
      $tag_field = 'photo_id';
      break;

    case MOVIE:
      $table = 'Movie';
      $tag_table = 'MovieTag';
      $tag_field = 'movie_id';
      break;

    default:
      throw new Exception(sprintf('Unknown type: "%s"', $type));
    }
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
      $where[] = sprintf("exposure_time <= %s", $this->db->quote($date->format('U') + (24*60*60)));
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
    if (isset($args->tag)) {
      //select * from photo where pid in (select photo_id from PhotoTag join Tag where PhotoTag.tag_id = Tag.tid and name = 'Paul') AND pid in (select photo_id from PhotoTag join Tag where PhotoTag.tag_id = Tag.tid and name = 'Holly');
      // Pull out the tags...
      $tags = explode(',', $args->tag);
      for ($tagIndex = 0, $tagLen = count($tags); $tagIndex < $tagLen; $tagIndex++) {
	$where[] = sprintf("$table.pid IN (SELECT $tag_field FROM $tag_table JOIN Tag WHERE $tag_table.tag_id = Tag.tid AND Tag.name = %s)", $this->db->quote(trim($tags[$tagIndex])));
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
    $query = sprintf("SELECT * FROM $table %s", $whereClause);
    if ($config->verbose) {
      print("Query: ${query}\n");
    }
    $result = $this->db->query($query);
    return $result;
  }

  /**
   * Retrieves a photo corresponding to the specified md5 sum.
   *
   * @param {Config} $config
   *   The configuration object that identifies where the database and photo
   *   directories are.
   * @param {StdClass} $args
   *   The processed command line arguments that identify which of the photo
   *   variants the user is interested in.
   * @param {String} $md5
   *   The md5sum of the desired photo.  This can match any of the 3
   *   variants (original, full size jpeg, sharable jpeg).
   * @return {Photo}
   *   The matching photo.
   */
  function getPhotoByMD5($config, $args, $md5) {
    // Construct the where clause:
    $where = array();

    $where[] = sprintf("md5 = %s", $this->db->quote($md5));
    $where[] = sprintf("jpeg_md5 = %s", $this->db->quote($md5));
    $where[] = sprintf("web_md5 = %s", $this->db->quote($md5));

    $whereClause = sprintf("WHERE %s", implode(" OR ", $where));

    // Do not sanitize the where clause; the individual elements have
    // already been sanitized.
    $query = sprintf("SELECT * FROM Photo %s", $whereClause);
    if ($config->verbose) {
      print("Query: ${query}\n");
    }
    $result = $this->db->query($query);
    return $result->fetchObject();
  }

  /**
   * Retrieves a photo corresponding to the specified file timestamp.
   *
   * @param {Config} $config
   *   The configuration object that identifies where the database and photo
   *   directories are.
   * @param {StdClass} $args
   *   The processed command line arguments that identify which of the photo
   *   variants the user is interested in.
   * @param {String} $timestamp
   *   The timestamp of the desired photo.
   * @return {Photo}
   *   The matching photo.
   */
  function getPhotoByTimestamp($config, $args, $timestamp) {
    // Construct the where clause:
    $where = array();

    $where[] = sprintf("exposure_time = %s", $this->db->quote($timestamp));
    $whereClause = sprintf("WHERE %s", implode(" OR ", $where));

    // Do not sanitize the where clause; the individual elements have
    // already been sanitized.
    $query = sprintf("SELECT * FROM Photo %s", $whereClause);
    if ($config->verbose) {
      print("Query: ${query}\n");
    }
    $result = $this->db->query($query);
    return $result->fetchObject();
  }

  public function updatePhoto(&$photo) {
    // Is the photo already in the archive?
    $this->getPhotoId($photo);
    if (isset($photo->pid)) {
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
    $this->getPhotoId($photo);
    $this->savePhotoTags($photo);
  }

  public function updateMovie(&$movie) {
    // Is the movie already in the archive?
    $this->getMovieId($movie);
    if (isset($movie->pid)) {
      $update = sprintf("UPDATE Movie SET filename = %s, width = %s, height = %s, md5 = %s, exposure_time = %s, rating = %s WHERE pid = %s",
$this->db->quote($movie->filename), $this->db->quote($movie->width), $this->db->quote($movie->height), $this->db->quote($movie->md5), $this->db->quote($movie->exposure_time), $this->db->quote($movie->rating), $this->db->quote($movie->pid));
      $this->db->exec($update);
    }
    else {
      // Need a new row.
      $insert = sprintf("INSERT INTO Movie (filename, width, height, md5, exposure_time, rating) VALUES (%s, %s, %s, %s, %s, %s)",
        $this->db->quote($movie->filename), $this->db->quote($movie->width), $this->db->quote($movie->height), $this->db->quote($movie->md5), $this->db->quote($movie->exposure_time), $this->db->quote($movie->rating));
      $this->db->exec($insert);
    }

    // Set the movie id.
    $this->getMovieId($movie);
    $this->saveMovieTags($movie);
  }

  public function createTags($tags) {
    if (empty($tags) || count($tags) == 0) {
      return;
    }
    $query_template = "SELECT * FROM Tag WHERE name = %s";
    for ($i = 0, $len = count($tags); $i < $len; $i++) {
      $query = sprintf($query_template, $this->db->quote($tags[$i]));
      $tag = $this->db->query($query);
      if (!empty($tag)) {
	$data = $tag->fetchObject();
	if ($data && $data->name == $tags[$i]) {
	  // The tag exists.
	  continue;
	}
      }
      // The tag does not yet exist.
      $insert = sprintf("INSERT INTO Tag (name) VALUES (%s)", $this->db->quote($tags[$i]));
      $this->db->exec($insert);
    }
  }

  private function savePhotoTags(&$photo) {
    return $this->saveTags($photo, PHOTO);
  }

  private function saveMovieTags(&$movie) {
    return $this->saveTags($movie, MOVIE);
  }

  private function saveTags(&$item, $type = PHOTO) {
    if (empty($item->tags) || count($item->tags) == 0) {
      return;
    }
    $this->createTags($item->tags);
    $tagQuery = "SELECT * FROM Tag WHERE name = %s";
    switch ($type) {
    case PHOTO:
      $table = 'PhotoTag';
      $id_field = 'photo_id';
      break;
    case MOVIE:
      $table = 'MovieTag';
      $id_field = 'movie_id';
      break;
    default:
      throw new Exception(sprintf('Unknown type passed to saveTags: %s', $type));
    }
    $query = "SELECT * FROM $table WHERE $id_field = %s AND tag_id = %s";
    for ($i = 0, $len = count($item->tags); $i < $len; $i++) {
      $tag = $this->db->query(sprintf($tagQuery, $this->db->quote($item->tags[$i])));
      $id = $item->pid;
      if (!empty($tag)) {
	$data = $tag->fetchObject();
	if ($data && $data->name == $item->tags[$i]) {
	  // Got the tag id.  Does the relationship already exist?
	  $relationship = $this->db->query(sprintf($query, $this->db->quote($id), $this->db->quote($data->tid)));
	  if ($relationship && $r = $relationship->fetchObject()) {
	    // It already exists.
	  }
	  else {
	    $insert = sprintf("INSERT INTO $table ($id_field, tag_id) VALUES (%s, %s)", $this->db->quote($id), $this->db->quote($data->tid));
	    $this->db->exec($insert);
	  }
	}
      }
    }
  }

  public function getIdFromFilename($filename) {

  }

  public function loadOriginalPhoto($photoId) {

  }

  /**
   * Finds the photo in the archive that most closely matches the specified photo.
   * @param {Config} $config
   *   The configuration object that identifies where the database and photo
   *   directories are.
   * @param {StdClass} $args
   *   The processed command line arguments that identify which of the photo
   *   variants the user is interested in.
   * @param {String} $filename
   *   The name of the file containing the photo to match.
   *
   * @return {StdObj}
   *   The matching photo, if found.
   */
  public function findClosestPhotoMatch($config, $args, $filename) {
    $md5 = md5_file($filename);
    $photo = $this->getPhotoByMD5($config, $args, $md5);
    if (!empty($photo)) {
      // Good match.
      return $photo;
    }

    // Look at the timestamp and name.
    $exif = @exif_read_data($filename, 0, true);
    $timestamp = strtotime($exif['IFD0']['DateTimeOriginal']);
    $pathinfo = pathinfo($filename);
    $name = $pathinfo['filename'];
    $photo = $this->getPhotoByTimestamp($config, $args, $timestamp);
    if (!empty($photo)) {
      // The timestamp is not unique because it is possible to shoot
      // several pictures per second.  Verify by filename as well.
      $photo_pathinfo = pathinfo($photo->filename);
      $photo_name = $photo_pathinfo['filename'];
      if ($photo_name == $name) {
	// The name and timestamp match.  This is a good match.
	return $photo;
      }
    }
    return null;
  }

  public function loadJpegPhoto($photoId) {

  }

  public function loadWebPhoto($photo) {

  }

  public function loadPhoto($config, $photoId) {
    $photoQuery = $this->db->query(sprintf("SELECT * FROM Photo WHERE pid = %s", $this->db->quote($photoId)));
    $photo = $photoQuery->fetchObject();
    if (empty($photo)) {
      throw new Exception (sprintf('Photo with photo id "%s" not found.', $photoId));
    }
    $photo->tags = $this->getTags($photoId, PHOTO);
    $this->addPaths($config, $photo);

    return $photo;
  }

  // TODO: You are here.  Should save the photo, then verify that the tags are in order.
  public function savePhoto($config, $photo) {
    $this->createTags($photo->tags);
    $this->getPhotoId($photo);

    // Now we have the photo id or it is an insert.
    if (isset($photo->id)) {
      // Update
      $this->db->exec(sprintf("UPDATE Photo set width = %s, height = %s, md5 = %s, exposure_time = %s, rating = %s WHERE pid = %s)",
        $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating), $this->db->quote($photo->pid)));
    }
    else {
      $this->db->exec(sprintf("INSERT INTO Photo (filename, width, height, md5, exposure_time, rating) VALUES (%s, %s, %s, %s, %s, %s)",
      $this->db->quote($photo->filename), $this->db->quote($photo->width), $this->db->quote($photo->height), $this->db->quote($photo->md5), $this->db->quote($photo->exposure_time), $this->db->quote($photo->rating)));
    }
    // Tag associations go here
    $this->getPhotoId($photo);
    $this->savePhotoTags($photo);
  }

  private function getPhotoId(&$photo) {
    if (!isset($photo->pid)) {
      $query = $this->db->query(sprintf("SELECT pid FROM Photo WHERE filename = %s", $this->db->quote($photo->filename)));
      if ($query && $data = $query->fetchObject()) {
	if (isset($data->pid)) {
	  $photo->pid = $data->pid;
	}
      }
    }
  }

  private function getMovieId(&$movie) {
    if (!isset($movie->pid)) {
      $query = $this->db->query(sprintf("SELECT pid FROM Movie WHERE filename = %s", $this->db->quote($movie->filename)));
      if ($query && $data = $query->fetchObject()) {
	if (isset($data->pid)) {
	  $movie->pid = $data->pid;
	}
      }
    }
  }

  private function addPaths($config, &$photo) {
    $filename = pathinfo($photo->filename, PATHINFO_FILENAME);
    $path = dirname($photo->filename);
    $photo->original_filename = $config->originalsDirectory . $photo->filename;
    $photo->jpeg_filename = $config->jpegsDirectory . "${path}/${filename}.JPG";
    $photo->share_filename = $config->shareDirectory . "${path}/${filename}.JPG";
  }

  public function getTags($photoId, $type = PHOTO) {
    // TODO: Could I use fetchColumn to make this more efficient?
    $tags = array();
    $tagQuery = $this->db->query(sprintf("SELECT photo_id, tag_id, name FROM PhotoTag join Tag on tid = tag_id WHERE photo_id = %s AND type = %s", $this->db->quote($photoId), $this->db->quote($type)));
    while ($tag = $tagQuery->fetchObject()) {
      $tags[] = $tag->name;
    }
    return $tags;
  }

  private function removeTags($photoId) {
    $this->db->exec(sprintf("DELETE FROM PhotoTag WHERE photo_id = %s", $this->db->quote($photoId)));
  }

  public function remove($config, &$photo) {
    $this->removeTags($photo->pid);
    $this->db->exec(sprintf("DELETE FROM Photo WHERE pid = %s", $this->db->quote($photo->pid)));
  }

  public function close() {
    unset($this->db);
  }
}
