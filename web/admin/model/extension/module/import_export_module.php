<?php

define('DIR_PRODUCT_IMAGE_RELATIVE', 'catalog/products/');
define('DIR_PRODUCT_IMAGE_ABSOLUTE', DIR_IMAGE . DIR_PRODUCT_IMAGE_RELATIVE);
define('DIR_EXPORT_XML', DIR_CATALOG . 'export_xml/');

static $registry = null;

class ModelExtensionModuleImportExportModule extends Model {

    public function __construct( $registry ) {
        parent::__construct( $registry );
    }
    
        public function export() {
        
        $this->load->model('catalog/attribute');
        $this->load->model('catalog/category');
        $this->load->model('catalog/product');   
        $this->load->model('catalog/manufacturer');        
        $this->load->model('localisation/currency');
        $this->load->model('localisation/language');
        $this->load->model('localisation/stock_status');
 
        $this->createYmlRoot();
        $this->exportCurrencies();
        $this->exportCategories();
        $this->exportDeliveryOptions();
        $this->exportProducts();
        $this->saveFile($this->dom->saveXML());
        return true;
    }
    
    public function import( $xml ) {
        
        $this->dom = new DOMDocument();
        $this->dom->loadXML($xml);
        $this->rootNode = $this->dom->documentElement;
        $this->dbh = $this->dbConnect();
        $this->load->model('catalog/attribute');
        $this->load->model('catalog/attribute_group');
        $this->load->model('catalog/manufacturer');        
        $this->load->model('catalog/product');
        $this->load->model('localisation/currency');
        $this->load->model('localisation/language');
        $this->load->model('localisation/stock_status');
        $this->load->model('setting/setting');
        $listLang = $this->model_localisation_language->getLanguages();
        sort($listLang);
        $this->listLang = $listLang;
        $this->setStoreName('Sandi Plus');
        $this->importCurrencies();
        $this->importCategories();
        $this->importDeliveryOptions();
        $this->manufacturers = $this->importManufacturers();
        $this->attributes = $this->importAttributes();
        $this->importProducts();
        $this->loadImages();

        return true;
    }
    
    private function setStoreName($name) {
        $this->model_setting_setting->editSettingValue('config', 'config_name', $name);
        $this->model_setting_setting->editSettingValue('config', 'config_meta_title', $name);
        return true;
    }
    
    private function createYmlRoot() {
        $this->dom = new DOMDocument("1.0", "UTF-8");
        $this->dom->xmlStandalone = true;
        $root = $this->dom->createElement('yml_catalog');
        $dateTime = $this->dom->createAttribute('date');
        $dateTime->value = date('Y-m-d H:i');
        $root->appendChild($dateTime);
        $this->dom->appendChild($root);
        $this->shop = $this->dom->createElement('shop');
        $root->appendChild($this->shop);
        return true;
    }
    
    private function importCurrencies() {
        $sth = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'currency`');
        $sth->execute();
        
        $currencies = $this->dom->getElementsByTagName('currency');
        $currencyParams = [
            'USD' => [
                'title' => 'US Dollar',
                'symbol_left' => '$',
                'symbol_right' => '',                    
            ],
            'EUR' => [
                'title' => 'Euro',
                'symbol_left' => '',
                'symbol_right' => '€',                    
            ],
            'UAH' => [
                'title' => 'Гривня',
                'symbol_left' => '',
                'symbol_right' => '₴',                    
            ],
        ];
                    
        foreach($currencies as $currency) {
            
            $curData = [
                'title' => $currencyParams[$currency->getAttribute('id')]['title'],
                'code' => $currency->getAttribute('id'),
                'symbol_left' => $currencyParams[$currency->getAttribute('id')]['symbol_left'],
                'symbol_right' => $currencyParams[$currency->getAttribute('id')]['symbol_right'],
                'decimal_place' => '2',
                'value' => 1/$currency->getAttribute('rate'),
                'status' => 1
            ];
            $this->model_localisation_currency->addCurrency($curData);
        }
        unset($curData);
        return true;
    }
    
        
    private function exportCurrencies() {

        $currenciesNode = $this->dom->createElement('currencies');
        $this->shop->appendChild($currenciesNode);
        $curData = [['sort' => 'title']];
        $currencies = $this->model_localisation_currency->getCurrencies($curData);
       
        foreach($currencies as $currency) {
            $currencyNode = $this->dom->createElement('currency');
            $currenciesNode->appendChild($currencyNode);
            $id = $this->dom->createAttribute('id');
            $id->value = $currency['code'];
            $currencyNode->appendChild($id);
            $rate = $this->dom->createAttribute('rate');
            $rate->value = round(1/$currency['value'], 2);
            $currencyNode->appendChild($rate);            
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
        $date_modified = $this->rootNode->getAttribute('date');
  
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
    
    private function exportCategories() {
        
        $categoriesNode = $this->dom->createElement('categories');
        $this->shop->appendChild($categoriesNode);
        $categories = $this->model_catalog_category->getCategories();
       
        foreach($categories as $category) {
            $categoryNode = $this->dom->createElement('category');
            $categoriesNode->appendChild($categoryNode);
            
            $id = $this->dom->createAttribute('id');
            $id->value = $category['category_id'];
            $categoryNode->appendChild($id);
            
            if($category['parent_id']) {
                $parent_id = $this->dom->createAttribute('parentId');
                $parent_id->value = $category['parent_id'];
                $categoryNode->appendChild($parent_id);
            }
            
            $descriptions = $this->model_catalog_category->getCategoryDescriptions($category['category_id']);
            $description = $this->dom->createAttribute('description');
            $description->value = $descriptions[$this->config->get('config_language_id')]['name'];
            $categoryNode->appendChild($description);
            $categoryNode->nodeValue = $descriptions[$this->config->get('config_language_id')]['name'];
        }
        
        return true;
    }
    
    private function importDeliveryOptions() {
        
        $deliveryOptions = $this->dom->getElementsByTagName('delivery-options');
        $stock_status_id = 6;
        
        foreach($deliveryOptions as $option) {
        $name = $option->getElementsByTagName('option')->item(0)->getAttribute('days') .' Days';            
            foreach($this->listLang as $lang) {
                $doData['stock_status'][$lang['language_id']] = [
                    'stock_status_id' => $stock_status_id,
                    'language_id' => $lang['language_id'],
                    'name' => $name
                ];
            }
            
            $this->model_localisation_stock_status->editStockStatus($stock_status_id, $doData);                 }
        unset($doData);
       
        return true;
    }
    
        
    private function exportDeliveryOptions() {
        
        $deliveryOptionsNode = $this->dom->createElement('delivery-options');
        $this->shop->appendChild($deliveryOptionsNode);
        $stock_status_id = 6;
        $deliveryOptions = $this->model_localisation_stock_status->getStockStatusDescriptions($stock_status_id);
        $optionNode = $this->dom->createElement('option');
        $deliveryOptionsNode->appendChild($optionNode);        
        $cost = $this->dom->createAttribute('cost');
        $cost->value = 0;
        $optionNode->appendChild($cost);
        $days = $this->dom->createAttribute('days');
        $days->value = substr($deliveryOptions[$this->config->get('config_language_id')]['name'], 0, -5);
        $optionNode->appendChild($days);        
       
        return true;
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
            $mData['name'] = $manufacturer;
            $mData['manufacturer_store'] = [0];
            $mData['sort_order'] = 0;
            $this->model_catalog_manufacturer->addManufacturer($mData);
        }
        unset($mData);
        
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
        
        $aDdata['sort_order'] = 0;
        foreach($this->listLang as $lang) {
            $aDdata['attribute_group_description'][$lang['language_id']] = ['name' => 'Catalog'];
        }
        
        $this->model_catalog_attribute_group->addAttributeGroup($aDdata);
        
        $params = $this->dom->getElementsByTagName('param');
        foreach($params as $param) {
            $attributes[] = $param->getAttribute('name');
        }
        $attributes = array_unique($attributes);        
        sort($attributes);

        foreach($attributes as $attribute) {
            
            $aDdata['attribute_group_id'] = 1;
            $aDdata['sort_order'] = 0;
            
            foreach($this->listLang as $lang) {
                $aDdata['attribute_description'][$lang['language_id']] = ['name' => $attribute];
            }
            $this->model_catalog_attribute->addAttribute($aDdata);
        }
        unset($aDdata);
        
        return true;
    }    
    
    private function importProducts() {
        
        $sth1  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product`');
        $sth2  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_attribute`');
        $sth3  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_description`');
        $sth4  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_discount`');
        $sth5  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_filter`');
        $sth6  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_image`');
        $sth7  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_option`');
        $sth8  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_option_value`');
        $sth9  = $this->dbh->prepare('TRUNCATE TABLE `' . DB_PREFIX . 'product_related`');
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
        
        $pData['product_store'] = [
            'store_id' => 0
        ];

        foreach($offers as $product) {
            
            $pData['model'] = $product->getElementsByTagName('model')->item(0)->nodeValue;
            $pData['sku'] = '';
            $pData['upc'] = '';
            $pData['ean'] = '';
            $pData['jan'] = '';
            $pData['isbn'] = '';
            $pData['mpn'] = '';
            $pData['location'] = '';        
            $pData['quantity'] = $product->getAttribute('instock');
            $pData['minimum'] = 1;
            $pData['subtract'] = 1;
            $pData['stock_status_id'] = 6;
            $pData['date_available'] = date_format(date_create($this->date_modified), 'Y-m-d');
            $pData['manufacturer'] = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
            $pData['shipping'] = ($product->getElementsByTagName('delivery')->item(0)->nodeValue) ? 1: 0;
            $pData['price'] = $product->getElementsByTagName('price')->item(0)->nodeValue;
            $pData['points'] = 0;
            $pData['weight'] = '0.00000000';
            $pData['weight_class_id'] = 0;
            $pData['length'] = '0.00000000';
            $pData['width'] = '0.00000000';
            $pData['height'] = '0.00000000';
            $pData['length_class_id'] = 0;
            $pData['status'] = 1;
            $pData['tax_class_id'] = 10;
            $pData['sort_order'] = 0;
            $images = $product->getElementsByTagName('picture');
            $imageFile = DIR_PRODUCT_IMAGE_RELATIVE . basename($images->item(0)->nodeValue);
            $pData['image'] = ($imageFile !== 'no_img.jpg') ? $imageFile : null;
            unset($pData['product_image']);
            foreach($images as $image) {
                $imageFile = DIR_PRODUCT_IMAGE_RELATIVE . basename($image->nodeValue);
                $pData['product_image'][] = [
                    'image' => ($imageFile !== 'no_img.jpg') ? $imageFile : null,
                    'sort_order' => 0
                ];
            }
            $product_description = $product->getElementsByTagName('description')->item(0)->nodeValue;
            $name = $product->getElementsByTagName('name')->item(0)->nodeValue;
            foreach($this->listLang as $lang) {
                $pData['product_description'][$lang['language_id']] = [
                    'name' => $name,
                    'description' => $product_description,
                    'tag' => $product->getAttribute('id'),
                    'meta_title' => $name,
                    'meta_description' => $product_description,
                    'meta_keyword' => '',                    
                ];
            }
            unset($pData['product_category']);
            $pData['product_category'][0] = intval($product->getElementsByTagName('categoryId')->item(0)->nodeValue);
            $categoryHierarchy = $this->getCategoryHierarchy();
            $categoryLevels = $this->parseJSON($categoryHierarchy['levelstring']);
            $path_id = $pData['product_category'][0];            
            $level = $categoryLevels->$path_id;
            for($i = $level; $i > 0; $i--) {
                $path_id = $this->categoriesArray[$path_id];                
                $pData['product_category'][$i] = $path_id;
            }
            
            $pData['id'] = $product->getAttribute('id');
            $pData['available'] = $product->getAttribute('available');
            $pData['date_modified'] = $this->date_modified;
            $pData['currencyId'] = $product->getElementsByTagName('currencyId')->item(0)->nodeValue;
            $pData['filter_name'] = $product->getElementsByTagName('vendor')->item(0)->nodeValue;
            $manufacturer = $this->model_catalog_manufacturer->getManufacturers($pData);
            unset($pData['filter_name']);
            $pData['manufacturer_id'] = $manufacturer[0]['manufacturer_id'];
                        
            $product_id = $this->model_catalog_product->addProduct($pData);
            
            $params = $product->getElementsByTagName('param');
            foreach($params as $key => $param) {
                $pData['filter_name'] = $param->getAttribute('name');
                $attributes = $this->model_catalog_attribute->getAttributes($pData);
                unset($pData['filter_name']);
                $pData['product_attribute'][$key] = [
                    'product_id' => $product_id,
                    'attribute_id' => $attributes[0]['attribute_id']
                ];
                foreach($this->listLang as $lang) {
                    $pData['product_attribute'][$key]['product_attribute_description'][$lang['language_id']] = ['text' => $param->nodeValue];
                }
            }
            $this->model_catalog_product->editProduct($product_id, $pData);
        }
        unset($pData);
        
        return true;
    }
    
        
    private function exportProducts() {
        
        $offersNode = $this->dom->createElement('offers');
        $this->shop->appendChild($offersNode);
        $products = $this->model_catalog_product->getProducts();
       
        foreach($products as $product) {
            $offer = $this->dom->createElement('offer');
            $offersNode->appendChild($offer);
            
            $id = $this->dom->createAttribute('id');
            $id->value = $product['tag'];
            $offer->appendChild($id);
            
            $available = $this->dom->createAttribute('available');
            $available->value = ($product['status']) ? 'true' : 'false';
            $offer->appendChild($available);            
            
            $instock = $this->dom->createAttribute('instock');
            $instock->value = $product['quantity'];
            $offer->appendChild($instock);
            
            $offer->appendChild($this->dom->createElement('price', round($product['price'])));
            $offer->appendChild($this->dom->createElement('currencyId', $this->config->get('config_currency')));
            $categories = $this->model_catalog_product->getProductCategories($product['product_id']);
            $offer->appendChild($this->dom->createElement('categoryId', $categories[0]));
            $offer->appendChild($this->dom->createElement('picture', HTTPS_SERVER . 'web/image/' . $product['image']));
            $images = $this->model_catalog_product->getProductImages($product['product_id']);
            foreach($images as $image) {
                if($product['image'] !== $image['image']) {
                    $offer->appendChild($this->dom->createElement('picture', HTTPS_SERVER . 'web/image/' . $image['image']));
                }
            }
            $offer->appendChild($this->dom->createElement('delivery', ($product['shipping']) ? 'true' : 'false' ));
            $descrptions = $this->model_catalog_product->getProductDescriptions($product['product_id']);
            $offer->appendChild($this->dom->createElement('name', $descrptions[$this->config->get('config_language_id')]['name']) );
            $manufacturer = $this->model_catalog_manufacturer->getManufacturer($product['manufacturer_id']);
            $offer->appendChild($this->dom->createElement('vendor', $manufacturer['name']) );
            $offer->appendChild($this->dom->createElement('vendorCode', $descrptions[$this->config->get('config_language_id')]['tag']) );
            $offer->appendChild($this->dom->createElement('model', $product['model']) );
            $offer->appendChild($this->dom->createElement('description', $descrptions[$this->config->get('config_language_id')]['description']) );
            $attributes = $this->model_catalog_product->getProductAttributes($product['product_id']);
            foreach($attributes as $attribute) {
                $value = $attribute['product_attribute_description'][$this->config->get('config_language_id')]['text'];
                $productAttribute = $this->model_catalog_attribute->getAttribute($attribute['attribute_id']);
                $param = $this->dom->createElement('param', $value);
                $name = $this->dom->createAttribute('name');
                $name->value = $productAttribute['name'];
                $param->appendChild($name);
                $offer->appendChild($param);
            }
        }
        
        return true;
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
    
    private function loadImages() {

        if(!(file_exists(DIR_PRODUCT_IMAGE_ABSOLUTE) && is_dir(DIR_PRODUCT_IMAGE_ABSOLUTE))) {
            mkdir(DIR_PRODUCT_IMAGE_ABSOLUTE);
        }
        else {
            $this->clearDir();
        }
        $images = $this->dom->getElementsByTagName('picture');
        foreach($images as $image) {
            $url = $image->nodeValue;
            $filename = basename($url);
            $content = file_get_contents($url);
            file_put_contents(DIR_PRODUCT_IMAGE_ABSOLUTE . $filename, $content);
        }
        return true;
    }
    
    private function clearDir() {
        if (file_exists(DIR_PRODUCT_IMAGE_ABSOLUTE)) {
            foreach (glob(DIR_PRODUCT_IMAGE_ABSOLUTE . '*') as $file) {
                unlink($file);
            }
        }
    }
        
    private function saveFile($content) {
        if(!(file_exists(DIR_EXPORT_XML) && is_dir(DIR_EXPORT_XML))) {
            mkdir(DIR_EXPORT_XML);
        }
        return file_put_contents(DIR_EXPORT_XML . 'exported.xml', $content);
    }

}