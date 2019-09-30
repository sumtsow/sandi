<?php

static $registry = null;

class ModelExtensionImportExportModule extends Model {

	private $error = array();
	protected $null_array = array();
	protected $use_table_seo_url = false;
	protected $posted_categories = '';

	public function __construct( $registry ) {
		parent::__construct( $registry );
		$this->use_table_seo_url = version_compare(VERSION,'3.0','>=') ? true : false;
	}
}

function dbConnect() {
    $dbh = new PDO('mysql:dbname=opencart;host=127.0.0.1', 'mysql', 'mysql');
    $dbh->exec('SET NAMES utf8');
}


