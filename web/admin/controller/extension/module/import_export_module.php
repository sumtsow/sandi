<?php

/**
 * Description of import_export_module
 *
 * @author sumtsow
 */

class ControllerExtensionModuleImportExportModule extends Controller { 
    private $error = array();
    
public function index() {
    $this->load->language('extension/module/import_export_module');

    $this->document->setTitle($this->language->get('heading_title'));

    //$this->load->model('setting/module');

    if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
        
        if (isset($this->request->post['url'])) {
            $xml = file_get_contents($this->request->post['url']);
            /*$parser = xml_parser_create();
            xml_parse_into_struct($parser, $xml, $values, $index);
            xml_parser_free($parser);*/

            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $rootNode = $dom->documentElement;
            $date_modified = $rootNode->getAttribute('date');
            $currencies = $dom->getElementsByTagName('currency');
            $categories = $dom->getElementsByTagName('category');
            $deliveryOptions = $dom->getElementsByTagName('delivery-options');
            $offers = $dom->getElementsByTagName('offer');
            $data['xml'] = $dom->saveXML();
            $this->load->model('extension/module/import_export_module');
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


                        /*$sth1 = $dbh->prepare('INSERT INTO `oc_category`(`category_id`, `parent_id`, `top`,  `column`, `status`, `date_added`, `date_modified`) VALUES ('
                                . '"' . $category->getAttribute('id') . '", '
                                . '"' . $parentId . '", '
                                . '"' . $top . '", '
                                . '0, '
                                . '1, '
                                . '"' . $date_modified . '", '
                                . '"' . $date_modified . '")');*/
                        $sth1 = $dbh->prepare('INSERT INTO `oc_category`(`category_id`, `parent_id`, `top`,  `column`, `status`, `date_added`, `date_modified`) VALUES (?, ?, ?, 0, 1, ?, ?)');
                        $sth1->bindParam(1, $category->getAttribute('id'), PDO::PARAM_INT);
                        $sth1->bindParam(2, $parentId, PDO::PARAM_INT);
                        $sth1->bindParam(3, $top, PDO::PARAM_BOOL);
                        $sth1->bindParam(4, $date_modified, PDO::PARAM_STR, 16);
                        $sth1->bindParam(5, $date_modified, PDO::PARAM_STR, 16);
                        $sth1->execute();
                        
                        /*$sth2 = $dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES ('
                                . '' . $category->getAttribute('id') . ', '
                                . '1, '
                                . '"' . $categoryName . '", '
                                . '"' . $category->getAttribute('description') . '", '
                                . '"' . $categoryName . '", '
                                . '"' . $category->getAttribute('description') . '", '
                                . '"")');*/
                        $sth2 = $dbh->prepare('INSERT INTO `oc_category_description` (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`) VALUES (?, 1, ?, ?, ?, ?, "")');
                        $sth2->bindParam(1, $category->getAttribute('id'), PDO::PARAM_INT);
                        $sth2->bindParam(2, $categoryName, PDO::PARAM_STR, 255);
                        $sth2->bindParam(3, $category->getAttribute('description'), PDO::PARAM_STR);
                        $sth2->bindParam(4, $categoryName, PDO::PARAM_STR, 255);
                        $sth2->bindParam(5, $category->getAttribute('description'), PDO::PARAM_STR);                                          $sth2->execute();                    
                        
                    $dbh->commit();

                } catch (Exception $e) {
                    $dbh->rollBack();
                    //echo "Ошибка: " . $e->getMessage();
                }
                
 
            }
        }

        $this->session->data['success'] = $this->language->get('text_success');

        //$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
    }

    if (isset($this->error['warning'])) {
        $data['error_warning'] = $this->error['warning'];
    }
    else {
        $data['error_warning'] = '';
    }

    if (isset($this->error['url'])) {
        $data['error_url'] = $this->error['url'];
    }
    else {
        $data['error_url'] = '';
    }

    $data['breadcrumb'] = $this->getBreadcrumbs();
 
    if (!isset($this->request->get['module_id'])) {
        $data['action'] = $this->url->link('extension/module/import_export_module', 'user_token=' . $this->session->data['user_token'], true);
    }
    else {
        $data['action'] = $this->url->link('extension/module/import_export_module', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'], true);
    }

    $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

    $data['user_token'] = $this->session->data['user_token'];

    if (isset($this->request->post['name'])) {
        $data['name'] = $this->request->post['name'];
    }
    elseif (!empty($module_info)) {
        $data['name'] = $module_info['name'];
    }
    else {
        $data['name'] = '';
    }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/module/import_export_module', $data));
    }
 
    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/import_export_module')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        /*if (!$this->request->post['url']) {
            $this->error['url'] = $this->language->get('error_url');
        }*/
        return !$this->error;
    }
    
    protected function getBreadcrumbs() {
 
        $breadcrumbs = array();

        $breadcrumbs[] = [
             'text' => $this->language->get('text_home'),
             'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ];

        $breadcrumbs[] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        ];

        if (!isset($this->request->get['module_id'])) {
        $breadcrumbs[] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/import_export_module', 'user_token=' . $this->session->data['user_token'], true)
        ];}
        else {
        $breadcrumbs[] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/import_export_module', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'], true)
        ];}
        
        return $breadcrumbs;
    }
}
