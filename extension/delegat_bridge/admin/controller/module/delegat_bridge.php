<?php
namespace Opencart\Admin\Controller\Extension\DelegatBridge\Module;

class DelegatBridge extends \Opencart\System\Engine\Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/delegat_bridge/module/delegat_bridge');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_delegat_bridge', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
        }

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/delegat_bridge/module/delegat_bridge', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/delegat_bridge/module/delegat_bridge', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        $fields = array(
            'module_delegat_bridge_status',
            'module_delegat_bridge_api_key',
            'module_delegat_bridge_allowed_ips',
            'module_delegat_bridge_default_store_id',
            'module_delegat_bridge_default_language_id'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        if ($data['module_delegat_bridge_default_store_id'] === null || $data['module_delegat_bridge_default_store_id'] === '') {
            $data['module_delegat_bridge_default_store_id'] = 0;
        }

        if ($data['module_delegat_bridge_default_language_id'] === null || $data['module_delegat_bridge_default_language_id'] === '') {
            $data['module_delegat_bridge_default_language_id'] = (int)$this->config->get('config_language_id');
        }

        $catalog = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;
        $data['bridge_url'] = rtrim($catalog, '/') . '/index.php?route=extension/delegat_bridge/api/delegat';

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/delegat_bridge/module/delegat_bridge', $data));
    }

    public function install() {
        $this->load->model('setting/setting');

        $defaults = array(
            'module_delegat_bridge_status' => 0,
            'module_delegat_bridge_api_key' => $this->generateApiKey(),
            'module_delegat_bridge_allowed_ips' => '',
            'module_delegat_bridge_default_store_id' => 0,
            'module_delegat_bridge_default_language_id' => (int)$this->config->get('config_language_id')
        );

        $this->model_setting_setting->editSetting('module_delegat_bridge', $defaults);
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/delegat_bridge/module/delegat_bridge')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        $api_key = isset($this->request->post['module_delegat_bridge_api_key'])
            ? trim($this->request->post['module_delegat_bridge_api_key'])
            : '';

        if (strlen($api_key) < 16) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        return !$this->error;
    }

    private function generateApiKey() {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(24));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(24));
        }

        return sha1(uniqid(mt_rand(), true)) . sha1(uniqid(mt_rand(), true));
    }
}
