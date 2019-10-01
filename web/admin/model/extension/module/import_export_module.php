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
        
        $this->categories = $this->dom->getElementsByTagName('category');
        $this->categoriesArray = $this->categoriesToArray(); 
        $categoryHierarchy = $this->getCategoryHierarchy();
        var_dump($categoryHierarchy['categoryLevels']);
        var_dump($categoryHierarchy['hierarchy']);
        $date_modified = $this->date_modified;
        $this->load->model('localisation/language');
        $list_lang = $this->model_localisation_language->getLanguages();        
        
        foreach($this->categories as $category) {
            
            $id = intval($category->getAttribute('id'));
            $parentId = ($category->getAttribute('parentId')) ? intval($category->getAttribute('parentId')) : 0;
            $description = $category->getAttribute('description');
            $categoryName = $category->nodeValue;
            $top = ($parentId) ? 0 : 1;
            
            try {  
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->dbh->beginTransaction();
                
                    $sth1 = $this->dbh->prepare('INSERT INTO `oc_category`(`category_id`, `image`, `parent_id`, `top`,  `column`, `sort_order`, `status`, `date_added`, `date_modified`) VALUES (:id, NULL, :parentId, :top, 0, 0, 1, :date_added, :date_modified)');
                    $sth1->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth1->bindParam(':parentId', $parentId, PDO::PARAM_INT);
                    $sth1->bindParam(':top', $top, PDO::PARAM_INT, 1);
                    $sth1->bindParam(':date_added', $date_modified, PDO::PARAM_STR, 16);
                    $sth1->bindParam(':date_modified', $date_modified, PDO::PARAM_STR, 16);
                    $sth1->execute();

                    $sth2 = $this->dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (:id, :language_id, :name, :description, :meta_title, :meta_description, "")');
                    $sth2->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth2->bindParam(':language_id', $list_lang['en-gb']['language_id'], PDO::PARAM_INT);
                    $sth2->bindParam(':name', $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(':description', $description, PDO::PARAM_STR);
                    $sth2->bindParam(':meta_title', $categoryName, PDO::PARAM_STR, 255);
                    $sth2->bindParam(':meta_description', $description, PDO::PARAM_STR);
                    $sth2->execute();
                    
                    $sth3 = $this->dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (:id, :language_id, :name, :description, :meta_title, :meta_description, "")');
                    $sth3->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth3->bindParam(':language_id', $list_lang['ru-ru']['language_id'], PDO::PARAM_INT);
                    $sth3->bindParam(':name', $categoryName, PDO::PARAM_STR, 255);
                    $sth3->bindParam(':description', $description, PDO::PARAM_STR);
                    $sth3->bindParam(':meta_title', $categoryName, PDO::PARAM_STR, 255);
                    $sth3->bindParam(':meta_description', $description, PDO::PARAM_STR);
                    $sth3->execute();
                    
                    $sth4 = $this->dbh->prepare('INSERT INTO `oc_category_path` (`category_id`, `path_id`, `level`) VALUES (:id,  :path_id, :level)');
                    $sth4->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth4->bindParam(':path_id', $id, PDO::PARAM_INT);
                    $sth4->bindParam(':level', $top, PDO::PARAM_INT, 1);
                    $sth4->execute();
                    
                    $sth5 = $this->dbh->prepare('INSERT INTO `oc_category_to_layout` (`category_id`, `store_id`, `layout_id`) VALUES (:id, 0, 0)');
                    $sth5->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth5->execute();
                    
                    $sth6 = $this->dbh->prepare('INSERT INTO `oc_category_to_store` (`category_id`, `store_id`) VALUES (:id, 0)');
                    $sth6->bindParam(':id', $id, PDO::PARAM_INT);
                    $sth6->execute();

                $this->dbh->commit();
            }
            
            catch (Exception $e) {
                $this->dbh->rollBack();                
                echo "Ошибка: " . $e->getMessage() .'<br />';
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
        $dbh = new PDO('mysql:dbname=' . DB_DATABASE . ';host='. DB_HOSTNAME, DB_USERNAME, DB_PASSWORD);
        $dbh->exec('SET NAMES utf8');
        return $dbh;
    }
    
    private function categoriesToArray() {
        $categoriesArray = [];
        foreach($this->categories as $category) {
            $id = intval($category->getAttribute('id'));
            $parentId = ($category->getAttribute('parentId')) ? intval($category->getAttribute('parentId')) : 0;
            $categoriesArray[$id] = $parentId;
        }
        return $categoriesArray;
    }
    
    private function getChildren($categoryId = 0) {
        return array_keys($this->categoriesArray, $categoryId);
    }
    
    private function hasChildren($categoryId = 0) {
        return in_array($categoryId, $this->categoriesArray);
    }
    
    private function getCategoryHierarchy($categoryId = 0, $currentLevel = -1, $categoryLevels = []) {
        $categories = $this->getChildren($categoryId);
        $currentLevel++;
        $categoryLevels[$categoryId] = $currentLevel;
        foreach($categories as $category) {
            if(self::hasChildren($category)) {
                $children = self::getCategoryHierarchy($category, $currentLevel, $categoryLevels);
            }
            else {
                $children = [
                    'hierarchy' => $currentLevel,
                    'categoryLevels' => [$category => $currentLevel]
                ];
            }
            $key = key($children['categoryLevels']);
            $categoryLevels[$key] = $children['categoryLevels'][$key];
            $categoryHierarchy[$category] = $children['hierarchy'];
        }
        return [
            'hierarchy' => $categoryHierarchy,
            'categoryLevels' => $categoryLevels
        ];
    }    
}




