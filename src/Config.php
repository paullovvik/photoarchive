<?php

// TODO: Need code that automatically creates the config file.

class Config {
  private $configPath;
  private $verbose = FALSE;
  private $config = NULL;

  public function __construct($configPath = NULL) {
    global $picture_directory, $originals_directory, $jpegs_directory, $share_directory, $app_database, $originals_database;
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
      global $picture_directory, $originals_directory, $jpegs_directory, $share_directory, $app_database, $originals_database;
      $obj = new StdClass();
      $obj->pictureDirectory = $picture_directory;
      $obj->originalsDirectory = $originals_directory;
      $obj->jpegsDirectory = $jpegs_directory;
      $obj->shareDirectory = $share_directory;
      $obj->appDB = $app_database;
      $obj->originalsDB = $originals_database;
      $obj->verbose = $this->verbose;
      $this->config = $obj;
    }
    return $this->config;
  }
}
