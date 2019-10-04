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
        $this->load->model('catalog/attribute');
        $this->load->model('catalog/attribute_group');
        $this->load->model('catalog/manufacturer');        
        $this->load->model('catalog/product');
        $this->load->model('localisation/language');
        $listLang = $this->model_localisation_language->getLanguages();
        sort($listLang);
        $this->listLang = $listLang;
        $this->importCurrencies();
        $this->importCategories();
        $this->importDeliveryOptions();
        $this->manufacturers = $this->importManufacturers();
        $this->attributes = $this->importAttributes();
        $this->importProducts();
        
        
        return $this->dom->saveXML();
    }
    
    private function importCurrencies() {
        
        $currencies = $this->dom->getElementsByTagName('currency');
        $date_modified = $this->date_modified;
            
        foreach($currencies as $currency) {
            $rate = $currency->getAttribute('rate');
            $id = $currency->getAttribute('id');
            $sth = $this->dbh->prepare('UPDATE `' . DB_PREFIX . 'currency` SET `value`=?, `date_modified`=? WHERE `code`=?');
            $sth->bindParam(1, $rate, PDO::PARAM_STR, 16);
            $sth->bindParam(2, $date_modified, PDO::PARAM_STR, 16);
            $sth->bindParam(3, $id, PDO::PARAM_STR, 3);
            $sth->execute(); 
        }
        return true;
    }
    
    private function importCategories() {
        
        $sth1 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'category`');
        $sth2 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'category_description`');
        $sth3 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'category_path`');
        $sth4 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'category_to_layout`');
        $sth5 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'category_to_store`');
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
  
        foreach($this->categories as $category) {
            
            $id = intval($category->getAttribute('id'));
            $parentId = ($category->getAttribute('parentId')) ? intval($category->getAttribute('parentId')) : 0;
            $description = $category->getAttribute('description');
            $categoryName = $category->nodeValue;
            $top = ($parentId) ? 0 : 1;
            
            try {  
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->dbh->beginTransaction();
                
                    $sth = $this->dbh->prepare('INSERT INTO `' . DB_PREFIX . 'category`(`category_id`, `image`, `parent_id`, `top`,  `column`, `sort_order`, `status`, `date_added`, `date_modified`) VALUES (:id, NULL, :parentId, :top, 1, 0, 1, :date_modified, :date_modified)');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                    $sth->bindParam(':parentId', $parentId, PDO::PARAM_INT, 11);
                    $sth->bindParam(':top', $top, PDO::PARAM_INT, 1);
                    $sth->bindParam(':date_modified', $date_modified, PDO::PARAM_STR, 16);
                    $sth->execute();

                    foreach($this->listLang as $lang) {
                        $sth = $this->dbh->prepare('INSERT INTO `' . DB_PREFIX . 'category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (:id, :language_id, :name, :description, :name, :description, "")');
                        $sth->bindParam(':id', $id, PDO::PARAM_INT);
                        $sth->bindParam(':language_id', $lang['language_id'], PDO::PARAM_INT, 11);
                        $sth->bindParam(':name', $categoryName, PDO::PARAM_STR, 255);
                        $sth->bindParam(':description', $description, PDO::PARAM_STR, 65535);
                        $sth->execute();
                    }

                    
                    $level = $categoryLevels->$id;
                    $path_id = $id;
                    for($i = $level; $i >= 0; $i--) {
                        $sth = $this->dbh->prepare('INSERT INTO `' . DB_PREFIX . 'category_path` (`category_id`, `path_id`, `level`) VALUES (:id,  :path_id, :level)');
                        $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                        $sth->bindParam(':path_id', $path_id, PDO::PARAM_INT, 11);
                        $sth->bindParam(':level', $i, PDO::PARAM_INT, 11);
                        $sth->execute();
                        $path_id = $this->categoriesArray[$path_id];
                    }
                    
                    $sth = $this->dbh->prepare('INSERT INTO `' . DB_PREFIX . 'category_to_layout` (`category_id`, `store_id`, `layout_id`) VALUES (:id, 0, 0)');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT, 11);
                    $sth->execute();
                    
                    $sth = $this->dbh->prepare('INSERT INTO `' . DB_PREFIX . 'category_to_store` (`category_id`, `store_id`) VALUES (:id, 0)');
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
        
        $deliveryOptions = $this->dom->getElementsByTagName('delivery-options');
 
        foreach($deliveryOptions as $option) {
            
            $days = $option->getElementsByTagName('option')->item(0)->getAttribute('days') .' Days';
            
            foreach($this->listLang as $lang) {
                $sth = $this->dbh->prepare('UPDATE `' . DB_PREFIX . 'stock_status` SET `name`=:days WHERE `stock_status_id` = 6 AND `language_id`=:lang');
                $sth->bindParam(':lang', $lang['language_id'], PDO::PARAM_INT, 11);
                $sth->bindParam(':days', $days, PDO::PARAM_STR, 32);
                $sth->execute(); 
            }
        }
    }
    
        
    private function importManufacturers() {
        $sth1 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'manufacturer`');
        $sth2 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'manufacturer_to_store`');
        $sth1->execute();
        $sth2->execute();
        
        $products = $this->dom->getElementsByTagName('offer');
        foreach($products as $product) {
            $manufacturers[] = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
        }
        $manufacturers = array_unique($manufacturers);        
        sort($manufacturers);
        foreach($manufacturers as $manufacturer) {
            $data['name'] = $manufacturer;
            $data['manufacturer_store'] = [0];
            $data['sort_order'] = 0;
            $this->model_catalog_manufacturer->addManufacturer($data);
        }
        unset($data['name'], $data['image'], $data['manufacturer_store']);
        
        return true;
    }
    
    private function importAttributes() {

        $sth1 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'attribute_group_description`');
        $sth2 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'attribute_group`');
        $sth3 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'attribute_description`'); 
        $sth4 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'attribute`');

        $sth1->execute();
        $sth2->execute();
        $sth3->execute();
        $sth4->execute();
        
        $data['sort_order'] = 0;
        foreach($this->listLang as $lang) {
            $data['attribute_group_description'][$lang['language_id']] = ['name' => STORE_NAME];
        }
        
        $this->model_catalog_attribute_group->addAttributeGroup($data);
        
        $params = $this->dom->getElementsByTagName('param');
        foreach($params as $param) {
            $attributes[] = $param->getAttribute('name');
        }
        $attributes = array_unique($attributes);        
        sort($attributes);

        foreach($attributes as $attribute) {
            $data['name'] = $manufacturer;
            $data['manufacturer_store'] = [0];
            $data['sort_order'] = 0;
            
            $this->model_catalog_attribute->addAttribute($data);
        }
        unset($data['name'], $data['image'], $data['manufacturer_store']);
        
        return true;
    }    
    
    private function importProducts() {
        
        $sth1   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product`');
        $sth2   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_attribute`');
        $sth3   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_description`');
        $sth4   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_discount`');
        $sth5   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_filter`');
        $sth6   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_image`');
        $sth7   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_option`');
        $sth8   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_option_value`');
        $sth9   = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_related`');
        $sth10 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_reward`');
        $sth11 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_special`');
        $sth12 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_category`');
        $sth13 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_layout`');
        $sth14 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_store`');
        $sth15 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_recurring`');
        $sth16 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'review`');
        $sth17 = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'coupon_product`');
        
        $sth2->execute();
        $sth3->execute();
        $sth4->execute();
        $sth5->execute();
        $sth6->execute();
        $sth7->execute();
        $sth8->execute();
        $sth9->execute();
        $sth10->execute();
        $sth11->execute();
        $sth12->execute();
        $sth13->execute();
        $sth14->execute();
        $sth15->execute();
        $sth16->execute();
        $sth17->execute();
        $sth1->execute();
        
        $offers = $this->dom->getElementsByTagName('offer');
        
        $data['product_store'] = [
            'store_id' => 0
        ];

        foreach($offers as $product) {
            
            $data['model'] = $product->getElementsByTagName('model')->item(0)->nodeValue;
            $data['sku'] = '';
            $data['upc'] = '';
            $data['ean'] = '';
            $data['jan'] = '';
            $data['isbn'] = '';
            $data['mpn'] = '';
            $data['location'] = '';        
            $data['quantity'] = $product->getAttribute('instock');
            $data['minimum'] = 1;
            $data['subtract'] = 1;
            $data['stock_status_id'] = 6;
            $data['date_available'] = date_format(date_create($this->date_modified), 'Y-m-d');
            $data['manufacturer'] = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
            $data['shipping'] = 1;
            $data['price'] = $product->getElementsByTagName('price')->item(0)->nodeValue;
            $data['points'] = 0;
            $data['weight'] = '0.00000000';
            $data['weight_class_id'] = 0;
            $data['length'] = '0.00000000';
            $data['width'] = '0.00000000';
            $data['height'] = '0.00000000';
            $data['length_class_id'] = 0;
            $data['status'] = 1;
            $data['tax_class_id'] = 10;
            $data['sort_order'] = 0;

            $images = $product->getElementsByTagName('picture');
            $data['image'] = $images->item(0)->nodeValue;
            unset($data['product_image']);
            foreach($images as $image) {
                $data['product_image'][] = [
                    'image' => $image->nodeValue,
                    'sort_order' => 0
                ];
            }
            $product_description = $product->getElementsByTagName('description')->item(0)->nodeValue;
            $name = $product->getElementsByTagName('name')->item(0)->nodeValue;
            foreach($this->listLang as $lang) {
                $data['product_description'][$lang['language_id']] = [
                    'name' => $name,
                    'description' => $product_description,
                    'tag' => '',
                    'meta_title' => $name,
                    'meta_description' => $product_description,
                    'meta_keyword' => '',                    
                ];
            }
            unset($data['product_category']);
            $data['product_category'][0] = intval($product->getElementsByTagName('categoryId')->item(0)->nodeValue);
            $categoryHierarchy = $this->getCategoryHierarchy();
            $categoryLevels = $this->parseJSON($categoryHierarchy['levelstring']);
            $path_id = $data['product_category'][0];            
            $level = $categoryLevels->$path_id;
            for($i = $level; $i > 0; $i--) {
                $path_id = $this->categoriesArray[$path_id];                
                $data['product_category'][$i] = $path_id;
            }
            
            $data['id'] = $product->getAttribute('id');
            $data['available'] = $product->getAttribute('available');
            $data['date_modified'] = $this->date_modified;
            $data['currencyId'] = $product->getElementsByTagName('currencyId')->item(0)->nodeValue;
            $data['delivery'] = $product->getElementsByTagName('delivery')->item(0)->nodeValue;
            $data['filter_name'] = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
            $manufacturer = $this->model_catalog_manufacturer->getManufacturers($data);
            unset($data['filter_name']);
            $data['manufacturer_id'] = $manufacturer[0]['manufacturer_id'];
            
            $params = $product->getElementsByTagName('param');
            
            $this->model_catalog_product->addProduct($data);
            

            /*
                INSERT INTO `' . DB_PREFIX . 'product_attribute` (`product_id`, `attribute_id`, `language_id`, `text`) VALUES (43, 2, 1, '1'),                
            */
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




