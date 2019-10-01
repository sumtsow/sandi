<?php

static $registry = null;

class ModelExtensionModuleImportExportModule extends Model {

    private $error = array();
    protected $null_array = array();
    protected $use_table_seo_url = false;
    protected $posted_categories = '';

    public function __construct( $registry ) {
        parent::__construct( $registry );
        $this->use_table_seo_url = version_compare(VERSION,'3.0','>=') ? true : false;
    }
        
    public function import( $xml ) {
        
        $this->dom = new DOMDocument();
        $this->dom->loadXML($xml);
        $this->rootNode = $this->dom->documentElement;
        $this->date_modified = $this->rootNode->getAttribute('date');
        $this->dbh = $this->dbConnect();
        
        $this->importCurrencies();
        $this->importCategories();
        
        return $this->dom->saveXML();
    }
    
    private function importCurrencies() {
        
        $currencies = $this->dom->getElementsByTagName('currency');
        $date_modified = $this->date_modified;
            
        foreach($currencies as $currency) {
            $rate = $currency->getAttribute('rate');
            $id = $currency->getAttribute('id');
            $sth = $this->dbh->prepare('UPDATE `oc_currency` SET `value`=?, `date_modified`=? WHERE `code`=?');
            $sth->bindParam(1, $rate, PDO::PARAM_STR, 16);
            $sth->bindParam(2, $date_modified, PDO::PARAM_STR, 16);
            $sth->bindParam(3, $id, PDO::PARAM_STR, 3);
            $sth->execute(); 
        }
        return true;
    }
    
    private function importCategories() {
        
        $sth1 = $this->dbh->prepare('TRUNCATE TABLE `oc_category`');
        $sth2 = $this->dbh->prepare('TRUNCATE TABLE `oc_category_description`');
        $sth3 = $this->dbh->prepare('TRUNCATE TABLE `oc_category_path`');
        $sth4 = $this->dbh->prepare('TRUNCATE TABLE `oc_category_to_layout`');
        $sth5 = $this->dbh->prepare('TRUNCATE TABLE `oc_category_to_store`');
        $sth1->execute();
        $sth2->execute();
        $sth3->execute();
        $sth4->execute();
        $sth5->execute();
        
        $categories = $this->dom->getElementsByTagName('category');
        $date_modified = $this->date_modified;
            
        foreach($categories as $category) {
            
            $id = $category->getAttribute('id');
            $parentId = ($category->getAttribute('parentId')) ? $category->getAttribute('parentId') : 0;
            $description = $category->getAttribute('description');
            $categoryName = $category->nodeValue;
            $top = ($parentId) ? 0 : 1;

            try {  
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->dbh->beginTransaction();
                
                    $sth1 = $this->dbh->prepare('INSERT INTO `oc_category`(`category_id`, `parent_id`, `top`,  `column`, `status`, `date_added`, `date_modified`) VALUES (?, ?, ?, 0, 1, ?, ?)');
                    $sth1->bindParam(1, $id, PDO::PARAM_INT);
                    $sth1->bindParam(2, $parentId, PDO::PARAM_INT);
                    $sth1->bindParam(3, $top, PDO::PARAM_BOOL);
                    $sth1->bindParam(4, $date_modified, PDO::PARAM_STR, 16);
                    $sth1->bindParam(5, $date_modified, PDO::PARAM_STR, 16);
                    $sth1->execute();

                    $sth2 = $this->dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (?, 1, ?, ?, ?, ?, "")');
                    $sth2->bindParam(1, $id, PDO::PARAM_INT);
                    $sth2->bindParam(2, $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(3, $description, PDO::PARAM_STR);
                    $sth2->bindParam(4, $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(5, $description, PDO::PARAM_STR);
                    $sth2->execute();
                    
                    $sth3 = $this->dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (?, 2, ?, ?, ?, ?, "")');
                    $sth3->bindParam(1, $id, PDO::PARAM_INT);
                    $sth3->bindParam(2, $categoryName, PDO::PARAM_STR, 255);
                    $sth3->bindParam(3, $description, PDO::PARAM_STR);
                    $sth3->bindParam(4, $categoryName, PDO::PARAM_STR, 255);
                    $sth3->bindParam(5, $description, PDO::PARAM_STR);
                    $sth3->execute();
                    
                    $sth4 = $this->dbh->prepare('INSERT INTO `oc_category_path` (`category_id`, `path_id`, `level`) VALUES (?,  ?, ?)');
                    $sth4->bindParam(1, $id, PDO::PARAM_INT);
                    $sth4->bindParam(2, $id, PDO::PARAM_INT);
                    $sth4->bindParam(3, $top, PDO::PARAM_BOOL);
                    $sth4->execute();
                    
                    $sth5 = $this->dbh->prepare('INSERT INTO `oc_category_to_layout` (`category_id`, `store_id`, `layout_id`) VALUES (?, 0, 0)');
                    $sth5->bindParam(1, $id, PDO::PARAM_INT);
                    $sth5->execute();
                    
                    $sth6 = $this->dbh->prepare('INSERT INTO `oc_category_to_store` (`category_id`, `store_id`, `layout_id`) VALUES (?, 0)');
                    $sth6->bindParam(1, $id, PDO::PARAM_INT);
                    $sth6->execute();

                $this->dbh->commit();

            }
            catch (Exception $e) {
                $this->dbh->rollBack();
                echo "Ошибка: " . $e->getMessage();
            }
	}
        return true;
    }
    
    /*private function importDeliveryOptions() {
        $deliveryOptions = $dom->getElementsByTagName('delivery-options');
        $offers = $dom->getElementsByTagName('offer');
        $dbh = $this->dbConnect();
            
        foreach($currencies as $currency) {
            $sth = $dbh->prepare('UPDATE `oc_currency` SET `value`=?, `date_modified`=? WHERE `code`=?');
            $sth->bindParam(1, $currency->getAttribute('rate'), PDO::PARAM_STR, 10);
            $sth->bindParam(2, $date_modified, PDO::PARAM_STR, 16);
            $sth->bindParam(3, $currency->getAttribute('id'), PDO::PARAM_STR, 3);
            $sth->execute(); 
        }*/
        
    private function dbConnect() {
        $dbh = new PDO('mysql:dbname=opencart;host=127.0.0.1', 'mysql', 'mysql');
        $dbh->exec('SET NAMES utf8');
        return $dbh;
    }
}




