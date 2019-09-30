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
            /*$parser = xml_parser_create();
            xml_parse_into_struct($parser, $xml, $values, $index);
            xml_parser_free($parser);*/            
            $xml = file_get_contents($this->request->post['url']);
            $this->load->model('extension/module/import_export_module');
            $model = $this->regisry->get('model_extension_module_import_export_module');
            $model->import($xml);
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
