<?php

error_reporting(0);

class ControllerExtensionPaymentCraftgatePaymentGateway extends Controller
{
    private $error = array();

    private $settings_fields = array(
        array('name' => 'payment_craftgate_payment_gateway_status', 'rules' => ''),
        array('name' => 'payment_craftgate_payment_gateway_title', 'rules' => 'required'),
        array('name' => 'payment_craftgate_payment_gateway_live_api_key', 'rules' => 'required'),
        array('name' => 'payment_craftgate_payment_gateway_live_secret_key', 'rules' => 'required'),
        array('name' => 'payment_craftgate_payment_gateway_sandbox_api_key', 'rules' => 'required'),
        array('name' => 'payment_craftgate_payment_gateway_sandbox_secret_key', 'rules' => 'required'),
        array('name' => 'payment_craftgate_payment_gateway_sandbox_mode', 'rules' => ''),
        array('name' => 'payment_craftgate_payment_gateway_enabled_payment_methods', 'rules' => ''),
        array('name' => 'payment_craftgate_payment_gateway_order_status_id', 'rules' => ''),
        array('name' => 'payment_craftgate_payment_gateway_sort_order', 'rules' => ''),
    );

    public function index()
    {
        $this->language->load('extension/payment/craftgate_payment_gateway');
        $this->load->model('extension/payment/craftgate_payment_gateway');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_craftgate_payment_gateway', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        foreach ($this->settings_fields as $field) {
            $field_name = $field['name'];
            $data["error_{$field_name}"] = isset($this->error[$field_name]) ? $this->error[$field_name] : '';
            $data[$field_name] = isset($this->request->post[$field_name]) ? $this->request->post[$field_name] : $this->config->get($field_name);
        }

        $data['action'] = $this->url->link('extension/payment/craftgate_payment_gateway', 'user_token=' . $this->session->data['user_token'], 'SSL');
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], 'SSL');
        $this->load->model('localisation/order_status');
        if ($data['payment_craftgate_payment_gateway_order_status_id'] == '') {
            $data['payment_craftgate_payment_gateway_order_status_id'] = $this->config->get('config_order_status_id');
        }

        $data['webhook_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/craftgate_payment_gateway/webhook';
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/payment/craftgate_payment_gateway', $data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/craftgate_payment_gateway')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        foreach ($this->settings_fields as $field) {
            if (empty($field['rules'])) continue;

            $field_name = $field['name'];
            if ($field['rules'] === 'required' && empty($this->request->post[$field_name])) {
                $field_error = $this->language->get("error_$field_name");
                $error_text = $field_error != "error_$field_name" ? $field_error : $this->language->get("error_required");
                $this->error[$field_name] = $error_text;
            }
        }

        return !$this->error;
    }


    public function install()
    {
        $this->load->model('extension/payment/craftgate_payment_gateway');
        $this->model_extension_payment_craftgate_payment_gateway->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/payment/craftgate_payment_gateway');
        $this->model_extension_payment_craftgate_payment_gateway->uninstall();
    }
}
