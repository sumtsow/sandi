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
    $this->load->model('extension/module/import_export_module');
    $model = $this->model_extension_module_import_export_module;

    $this->document->setTitle($this->language->get('heading_title'));

    $data['import'] = 'active';
    
    if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
        

        if (isset($this->request->post['url'])) {
            $xml = file_get_contents($this->request->post['url']);
            if($model->import($xml)) {
                $data['import'] = 'active';
                $data['export'] = '';
            }
            else {
                $this->error['url'] = '';
            }
        }
        
        elseif (isset($this->request->post['save'])) {
            $data['xml'] = $model->export();
            $data['import'] = '';
            $data['export'] = 'active';
            
        }

        $data['success'] = $this->language->get('text_success');

        $this->response->setOutput($this->load->view('extension/module/import_export_module', $data));
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
    
    if (isset($this->error['save'])) {
        $data['error_save'] = $this->error['save'];
    }
    else {
        $data['error_save'] = '';
    }

    $data['breadcrumb'] = $this->getBreadcrumbs();

    $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

    $data['user_token'] = $this->session->data['user_token'];

    /*if (isset($this->request->post['name'])) {
        $data['name'] = $this->request->post['name'];
    }
    elseif (!empty($module_info)) {
        $data['name'] = $module_info['name'];
    }
    else {
        $data['name'] = '';
    }*/

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/module/import_export_module', $data));
    }
 
    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/import_export_module')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
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
