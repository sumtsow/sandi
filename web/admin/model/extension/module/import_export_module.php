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
        
    public function import( $xml ) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $rootNode = $dom->documentElement;
        $date_modified = $rootNode->getAttribute('date');
        $currencies = $dom->getElementsByTagName('currency');
        $categories = $dom->getElementsByTagName('category');
        $deliveryOptions = $dom->getElementsByTagName('delivery-options');
        $offers = $dom->getElementsByTagName('offer');
        $dbh = dbConnect();
            
        foreach($currencies as $currency) {
            $sth = $dbh->prepare('UPDATE `oc_currency` SET `value`=?, `date_modified`=? WHERE `code`=?');
            $sth->bindParam(1, $currency->getAttribute('rate'), PDO::PARAM_STR, 10);
            $sth->bindParam(2, $date_modified, PDO::PARAM_STR, 16);
            $sth->bindParam(3, $currency->getAttribute('id'), PDO::PARAM_STR, 3);
            $sth->execute(); 
        }
            
        $sth1 = $dbh->prepare('TRUNCATE TABLE `oc_category`');
        $sth2 = $dbh->prepare('TRUNCATE TABLE `oc_category_description`');
        $sth3 = $dbh->prepare('TRUNCATE TABLE `oc_category_path`');
        $sth4 = $dbh->prepare('TRUNCATE TABLE `oc_category_to_store`');
        $sth1->execute();
        $sth2->execute();
        $sth3->execute();
        $sth4->execute();
            
        foreach($categories as $category) {

            $parentId = ($category->getAttribute('parentId')) ? $category->getAttribute('parentId') : 0;
            $categoryName = $category->nodeValue;
            $top = ($parentId) ? 0 : 1;

            try {  
                $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $dbh->beginTransaction();
                
                    $sth1 = $dbh->prepare('INSERT INTO `oc_category`(`category_id`, `parent_id`, `top`,  `column`, `status`, `date_added`, `date_modified`) VALUES (?, ?, ?, 0, 1, ?, ?)');
                    $sth1->bindParam(1, $category->getAttribute('id'), PDO::PARAM_INT);
                    $sth1->bindParam(2, $parentId, PDO::PARAM_INT);
                    $sth1->bindParam(3, $top, PDO::PARAM_BOOL);
                    $sth1->bindParam(4, $date_modified, PDO::PARAM_STR, 16);
                    $sth1->bindParam(5, $date_modified, PDO::PARAM_STR, 16);
                    $sth1->execute();

                    $sth2 = $dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (?, 1, ?, ?, ?, ?, "")');
                    $sth2->bindParam(1, $category->getAttribute('id'), PDO::PARAM_INT);
                    $sth2->bindParam(2, $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(3, $category->getAttribute('description'), PDO::PARAM_STR);
                    $sth2->bindParam(4, $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(5, $category->getAttribute('description'), PDO::PARAM_STR);
                    $sth2->execute();                    

                $dbh->commit();

            }
            catch (Exception $e) {
                $dbh->rollBack();
                //echo "Ошибка: " . $e->getMessage();
            }
	}
        return $dom->saveXML();
    }
    
    function dbConnect() {
        $dbh = new PDO('mysql:dbname=opencart;host=127.0.0.1', 'mysql', 'mysql');
        $dbh->exec('SET NAMES utf8');
        return $dbh;
    }
    
}




