<?php

// TODO: Need code that automatically creates the config file.

class Config {
  private $configPath;
  private $verbose = FALSE;
  private $config = NULL;

  public function __construct($configPath = NULL) {
    global $picture_directory, $originals_directory, $jpegs_directory, $share_directory, $movie_directory, $app_database, $originals_database, $database_host, $database_user, $database_pass;
    if (!empty($configPath)) {
      $this->configPath = $configPath;
    }
    else {
      $this->configPath = $_SERVER['HOME'] . '/.PhotoArchive';
    }
    include_once($this->configPath);
  }

  public function setVerbose() {
    $this->verbose = TRUE;
    if ($this->config) {
      $this->config->verbose = TRUE;
    }
  }

  /**
   * Returns the configuration in the form of an object.
   *
   * @return {Object}
   *   The configuration.
   */
  public function getConfiguration() {
    if ($this->config === NULL) {
      global $picture_directory, $originals_directory, $jpegs_directory, $share_directory, $movie_directory, $app_database, $originals_database, $share_resolution, $quality, $database_host, $database_user, $database_pass;
      if (empty($share_resolution)) {
	$share_resolution = '1920x1080';
      }
      if (empty($quality)) {
	$quality = '85';
      }
      $obj = new StdClass();
      $obj->pictureDirectory = $picture_directory;
      $obj->originalsDirectory = $originals_directory;
      $obj->jpegsDirectory = $jpegs_directory;
      $obj->shareDirectory = $share_directory;
      $obj->movieDirectory = $movie_directory;
      $obj->appDB = $app_database;
      $obj->originalsDB = $originals_database;
      $obj->shareResolution = $share_resolution;
      $obj->quality = $quality;
      $obj->verbose = $this->verbose;
      $obj->dbhost = $database_host;
      $obj->dbuser = $database_user;
      $obj->dbpass = $database_pass;
      $this->config = $obj;
    }
    return $this->config;
  }
}
