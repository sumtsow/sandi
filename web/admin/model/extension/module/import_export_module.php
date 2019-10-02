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
        $this->load->model('localisation/language');
        
        $this->importCurrencies();
        $this->importCategories();
        $this->importDeliveryOptions();
        $this->importProducts();
        
        
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
        $categoryLevels = $this->parseJSON($categoryHierarchy['levelstring']);
        $date_modified = $this->date_modified;
        $listLang = $this->model_localisation_language->getLanguages();
        sort($listLang);
  
        foreach($this->categories as $category) {
            
            $id = intval($category->getAttribute('id'));
            $parentId = ($category->getAttribute('parentId')) ? intval($category->getAttribute('parentId')) : 0;
            $description = $category->getAttribute('description');
            $categoryName = $category->nodeValue;
            $top = ($parentId) ? 0 : 1;
            
            try {  
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->dbh->beginTransaction();
                
                    $sth = $this->dbh->prepare('INSERT INTO `oc_category`(`category_id`, `image`, `parent_id`, `top`,  `column`, `sort_order`, `status`, `date_added`, `date_modified`) VALUES (:id, NULL, :parentId, :top, 1, 0, 1, :date_modified, :date_modified)');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                    $sth->bindParam(':parentId', $parentId, PDO::PARAM_INT, 11);
                    $sth->bindParam(':top', $top, PDO::PARAM_INT, 1);
                    $sth->bindParam(':date_modified', $date_modified, PDO::PARAM_STR, 16);
                    $sth->execute();

                    foreach($listLang as $lang) {
                        $sth = $this->dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (:id, :language_id, :name, :description, :name, :description, "")');
                        $sth->bindParam(':id', $id, PDO::PARAM_INT);
                        $sth->bindParam(':language_id', $lang['language_id'], PDO::PARAM_INT, 11);
                        $sth->bindParam(':name', $categoryName, PDO::PARAM_STR, 255);
                        $sth->bindParam(':description', $description, PDO::PARAM_STR, 65535);
                        $sth->execute();
                    }

                    
                    $level = $categoryLevels->$id;
                    $path_id = $id;
                    for($i = $level; $i >= 0; $i--) {
                        $sth = $this->dbh->prepare('INSERT INTO `oc_category_path` (`category_id`, `path_id`, `level`) VALUES (:id,  :path_id, :level)');
                        $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                        $sth->bindParam(':path_id', $path_id, PDO::PARAM_INT, 11);
                        $sth->bindParam(':level', $i, PDO::PARAM_INT, 11);
                        $sth->execute();
                        $path_id = $this->categoriesArray[$path_id];
                    }
                    
                    $sth = $this->dbh->prepare('INSERT INTO `oc_category_to_layout` (`category_id`, `store_id`, `layout_id`) VALUES (:id, 0, 0)');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                    $sth->execute();
                    
                    $sth = $this->dbh->prepare('INSERT INTO `oc_category_to_store` (`category_id`, `store_id`) VALUES (:id, 0)');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                    $sth->execute();

                $this->dbh->commit();
            }
            
            catch (Exception $e) {
                $this->dbh->rollBack();                
                echo "Ошибка: " . $e->getMessage() .'<br />';
            }
            
	}
        return true;
    }
    
        
    
    private function importDeliveryOptions() {
        
        $listLang = $this->model_localisation_language->getLanguages();
        sort($listLang);
        $deliveryOptions = $this->dom->getElementsByTagName('delivery-options');
 
        foreach($deliveryOptions as $option) {
            
            $days = $option->getElementsByTagName('option')->item(0)->getAttribute('days') .' Days';
            
            foreach($listLang as $lang) {
                $sth = $this->dbh->prepare('UPDATE `oc_stock_status` SET `name`=:days WHERE `stock_status_id` = 6 AND `language_id`=:lang');
                $sth->bindParam(':lang', $lang['language_id'], PDO::PARAM_INT, 11);
                $sth->bindParam(':days', $days, PDO::PARAM_STR, 32);
                $sth->execute(); 
            }
        }
    }
    
    
    private function importProducts() {
        
        /*$st1  = $this->dbh->prepare('TRUNCATE TABLE `oc_product`');
        $sth2  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_attribute`');
        $sth3  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_description`');
        $sth4  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_discount`');
        $sth5  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_image`');
        $sth6  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_option`');
        $sth7  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_option_value`');
        $sth8  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_related`');
        $sth9  = $this->dbh->prepare('TRUNCATE TABLE `oc_product_reward`');
        $sth0 = $this->dbh->prepare('TRUNCATE TABLE `oc_product_special`');
        $sth1 = $this->dbh->prepare('TRUNCATE TABLE `oc_product_to_category`');
        $sth = $this->dbh->prepare('TRUNCATE TABLE `oc_product_to_store`');        
        $sth1->execute();
        $sth2->execute();
        $sth3->execute();
        $sth4->execute();
        $sth5->execute();
        $sth6->execute();
        $sth7->execute();
        $sth8->execute();
        $sth9->execute();
        $sth0->execute();
        $sth1->execute();
        $sth->execute(); */       
        
        $offers = $this->dom->getElementsByTagName('offer');
        
        foreach($offers as $product) {
            
            $listLang = $this->model_localisation_language->getLanguages();
            sort($listLang);
            $id = $product->getAttribute('id');
            $available = $product->getAttribute('available');
            $quantity = $product->getAttribute('instock');
            $price = $product->getElementsByTagName('price')->item(0)->nodeValue;
            $currencyId = $product->getElementsByTagName('currencyId')->item(0)->nodeValue;
            $pictures = $product->getElementsByTagName('picture');
            $delivery = $product->getElementsByTagName('delivery')->item(0)->nodeValue;
            $name = $product->getElementsByTagName('name')->item(0)->nodeValue;
            $vendor = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
            $manufacturer_id = $product->getElementsByTagName('vendorCode')->item(0)->nodeValue;
            $model = $product->getElementsByTagName('model')->item(0)->nodeValue;
            $description = $product->getElementsByTagName('description')->item(0)->nodeValue;
            $params = $product->getElementsByTagName('param');
            $date_modified = $this->date_modified;
            $dateTime = date_create($date_modified);
            $date_available = date_format($dateTime, 'Y-m-d');            
            /**
             * ??????????????????
             */
            $tax_class_id = 9;
            

            try {  
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->dbh->beginTransaction();

                $sth = $this->dbh->prepare('INSERT INTO `oc_product` (`product_id`, `model`, `sku`, `upc`, `ean`, `jan`, `isbn`, `mpn`, `location`, `quantity`, `stock_status_id`, `image`, `manufacturer_id`, `shipping`, `price`, `points`, `tax_class_id`, `date_available`, `weight`, `weight_class_id`, `length`, `width`, `height`, `length_class_id`, `subtract`, `minimum`, `sort_order`, `status`, `viewed`, `date_added`, `date_modified`) VALUES (:id, :model, "", "", "", "", "", "", "", :quantity, 6, NULL, :manufacturer_id, 1, :price, 0, :tax_class_id, :date_available, "0.00000000", 1, "0.00000000", "0.00000000", "0.00000000", 1, 1, 1, 0, 1, 0, :date_modified, :date_modified');
                $sth->bindParam(':id', $id, PDO::PARAM_STR, 11);
                $sth->bindParam(':model', $model, PDO::PARAM_STR, 64);
                $sth->bindParam(':quantity', $quantity, PDO::PARAM_INT, 4);
                $sth->bindParam(':manufacturer_id', $manufacturer_id, PDO::PARAM_INT, 11);
                $sth->bindParam(':price', $price, PDO::PARAM_STR, 20);
                $sth->bindParam(':tax_class_id', $tax_class_id, PDO::PARAM_INT, 11);
                $sth->bindParam(':date_available', $date_available, PDO::PARAM_STR, 10);
                $sth->bindParam(':date_modified', $date_modified, PDO::PARAM_STR, 16);
                
                //$sth->execute();
                
                foreach($listLang as $lang) {
                    
                    foreach($params as $param) {
                        
                        $attrName = $param->getAttribute('name');
                        $attrText = $param->nodeValue;
                        $sth = $this->dbh->prepare('INSERT INTO `oc_product_attribute` (`product_id`, `attribute_id`, `language_id`, `text`) VALUES (:id, 1, :lang, :text)');
                        $sth->bindParam(':id', $id, PDO::PARAM_STR, 11);
                        //$sth->bindParam(':attribute_id', $attrId, PDO::PARAM_INT, 11);
                        $sth->bindParam(':lang', $lang['language_id'], PDO::PARAM_INT, 11);
                        $sth->bindParam(':text', $attrText, PDO::PARAM_STR, 11);                    
                        //$sth->execute();
                    }

                    
                    $sth = $this->dbh->prepare('INSERT INTO `oc_product_description` (`product_id`, `language_id`, `name`, `description`, `tag`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (:id, :lang, :name, :name, "", :name, "", "")');
                    $sth->bindParam(':id', $id, PDO::PARAM_STR, 11);
                    $sth->bindParam(':lang', $lang['language_id'], PDO::PARAM_INT, 11);
                    $sth->bindParam(':name', $name, PDO::PARAM_STR, 255);
                    $sth->bindParam(':description', $description, PDO::PARAM_STR, 65535);

                    //$sth->execute();
                }
                
                
                $sth = $this->dbh->prepare('INSERT INTO `oc_product_to_category` (`product_id`, `category_id`) VALUES (:id, :category_id)');
                $sth->bindParam(':id', $id, PDO::PARAM_STR, 11);
                $sth->bindParam(':category_id', $currencyId, PDO::PARAM_INT, 11);                    
                $sth->execute();
                
                $sth = $this->dbh->prepare('INSERT INTO `oc_product_to_store` (`product_id`, `store_id`) VALUES (:id, 0)');
                $sth->bindParam(':id', $id, PDO::PARAM_STR, 11);
                //$sth->execute();
                
                /*

                INSERT INTO `oc_product_attribute` (`product_id`, `attribute_id`, `language_id`, `text`) VALUES (43, 2, 1, '1'),


                INSERT INTO `oc_product_discount` (`product_discount_id`, `product_id`, `customer_group_id`, `quantity`, `priority`, `price`, `date_start`, `date_end`) VALUES (440, 42, 1, 30, 1, '66.0000', '0000-00-00', '0000-00-00');

                INSERT INTO `oc_product_image` (`product_image_id`, `product_id`, `image`, `sort_order`) VALUES (2345, 30, 'catalog/demo/canon_eos_5d_2.jpg', 0);

                INSERT INTO `oc_product_option` (`product_option_id`, `product_id`, `option_id`, `value`, `required`) VALUES (226, 30, 5, '', 1);

                INSERT INTO `oc_product_option_value` (`product_option_value_id`, `product_option_id`, `product_id`, `option_id`, `option_value_id`, `quantity`, `subtract`, `price`, `price_prefix`, `points`, `points_prefix`, `weight`, `weight_prefix`) VALUES (15, 226, 30, 5, 39, 2, 1, '0.0000', '+', 0, '+', '0.00000000', '+');

                INSERT INTO `oc_product_related` (`product_id`, `related_id`) VALUES (40, 42);

                INSERT INTO `oc_product_reward` (`product_reward_id`, `product_id`, `customer_group_id`, `points`) VALUES (515, 42, 1, 100);

                INSERT INTO `oc_product_special` (`product_special_id`, `product_id`, `customer_group_id`, `priority`, `price`, `date_start`, `date_end`) VALUES (438, 30, 1, 1, '80.0000', '0000-00-00', '0000-00-00');
                 */
                
                $this->dbh->commit();
            }
            
            catch (Exception $e) {
                $this->dbh->rollBack();                
                echo "Ошибка: " . $e->getMessage() .'<br />';
            }
        }
    }
        
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
    
    private function getCategoryHierarchy($categoryId = 0, $currentLevel = -1, $levelstring = '') {
        $categories = $this->getChildren($categoryId);
        $currentLevel++;
        foreach($categories as $category) {
            $levelstring .= '"'.$category.'": '.$currentLevel.', ';
            if(self::hasChildren($category)) {
                $children = self::getCategoryHierarchy($category, $currentLevel, $levelstring);
            }
            else {
                $children = [
                    'hierarchy' => $currentLevel,
                    'levelstring' => $levelstring,
                ];
            }
            $categoryHierarchy[$category] = $children['hierarchy'];
            $levelstring = $children['levelstring'];
        }
        return [
            'hierarchy' => $categoryHierarchy,
            'levelstring' => $levelstring,
        ];
    }
    
    private function parseJSON($mystring) {
        return json_decode('{' . rtrim($mystring, ', ') . '}');
    }
    
}




