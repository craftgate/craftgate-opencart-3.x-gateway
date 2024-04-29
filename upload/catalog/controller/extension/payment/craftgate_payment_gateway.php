<?php
error_reporting(0);

class ControllerExtensionPaymentCraftgatePaymentGateway extends Controller
{
    const ORDER_STATUS_ID_INITIAL = 0;
    const ORDER_STATUS_ID_FAILED = 10;

    public function index()
    {
        $this->load->language('extension/payment/craftgate_payment_gateway');
        return $this->load->view('extension/payment/craftgate_payment_gateway');
    }

    public function initCheckoutForm()
    {
        $this->setCookies();
        $this->response->addHeader('Content-Type: application/json');
        try {
            $request = $this->buildInitCheckoutFormRequest();
            $response = $this->initCraftgateClient()->initCheckoutPayment($request);
            $this->response->setOutput(json_encode($response), true);
        } catch (Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    public function callback()
    {
        $server_conn_slug = $this->getServerConnectionSlug();
        $this->load->language('extension/payment/craftgate_payment_gateway');
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/craftgate_payment_gateway');

        try {
            $this->validateCallbackParams();
            $order_id = $this->request->get['order_id'];

            $checkout_form_result = $this->initCraftgateClient()->retrieveCheckoutPayment($this->request->post["token"]);
            $this->validateOrder($checkout_form_result);

            if (!isset($checkout_form_result->paymentError) && $checkout_form_result->paymentStatus === 'SUCCESS') {
                $this->complete_order($checkout_form_result, $order_id);
                echo "<script>window.top.location.href = '" . $this->url->link('checkout/success', '', $server_conn_slug) . "';</script>";
            } else {
                $error = $checkout_form_result->paymentError->errorCode . ' - ' . $checkout_form_result->paymentError->errorDescription . ' - ' . $checkout_form_result->paymentError->errorGroup;
                throw new Exception($error);
            }
        } catch (Exception $ex) {
            $resp_msg = $ex->getMessage();
            $resp_msg = !empty($resp_msg) ? $resp_msg : $this->language->get('invalid_request');
            $this->session->data['error'] = $resp_msg;
            echo "<script>window.top.location.href = '" . $this->url->link('checkout/checkout', '', $server_conn_slug) . "';</script>";
        }
    }

    public function webhook()
    {
        $webhook_data = json_decode(file_get_contents('php://input'), true);
        if (!$this->should_process_webhook_request($webhook_data)) {
            exit();
        }

        $checkout_token = $webhook_data['payloadId'];
        $checkout_form_result = $this->initCraftgateClient()->retrieveCheckoutPayment($checkout_token);

        if ($checkout_form_result->paymentStatus !== 'SUCCESS' || !isset($checkout_form_result->conversationId)) {
            exit();
        }

        $this->complete_order($checkout_form_result, $checkout_form_result->conversationId);
    }

    private function complete_order($checkout_form_result, $order_id)
    {
        $order_info = $this->getOrder($order_id);
        if (!$this->is_order_eligible_to_complete($order_info)) {
            return;
        }

        $message = 'Craftgate Payment Id: ' . $checkout_form_result->id . ' <br>';
        $message .= 'Craftgate Payment URL: ' . $this->buildCraftgatePaymentUrl($checkout_form_result->id);
        $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_craftgate_payment_gateway_order_status_id'), $message, false);
        if ($checkout_form_result->installment > 1) {
            $this->model_extension_payment_craftgate_payment_gateway->addInstallmentFeeToOrder($order_info['order_id'], $checkout_form_result->paidPrice);
            $installmentMessage = $checkout_form_result->cardBrand . ' - ' . $checkout_form_result->installment . ' Installment';
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_craftgate_payment_gateway_order_status_id'), $installmentMessage);
        }
    }

    private function should_process_webhook_request($webhook_data)
    {
        if (!isset($webhook_data) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        if ($webhook_data['eventType'] !== 'CHECKOUTFORM_AUTH' || $webhook_data['status'] !== "SUCCESS" || !isset($webhook_data['payloadId'])) {
            return false;
        }
        return true;
    }

    private function is_order_eligible_to_complete($order_info)
    {
        $order_status_id = $order_info['order_status_id'];
        return in_array($order_status_id, array(self::ORDER_STATUS_ID_INITIAL, self::ORDER_STATUS_ID_FAILED));
    }

    private function initCraftgateClient()
    {
        $this->load->model('extension/payment/craftgate_payment_gateway');

        $is_sandbox_active = $this->config->get('payment_craftgate_payment_gateway_sandbox_mode');
        if ($is_sandbox_active) {
            $api_key = $this->config->get('payment_craftgate_payment_gateway_sandbox_api_key');
            $secret_key = $this->config->get('payment_craftgate_payment_gateway_sandbox_secret_key');
        } else {
            $api_key = $this->config->get('payment_craftgate_payment_gateway_live_api_key');
            $secret_key = $this->config->get('payment_craftgate_payment_gateway_live_secret_key');
        }

        return $this->model_extension_payment_craftgate_payment_gateway->craftgate($api_key, $secret_key, $is_sandbox_active);

    }

    private function retrieveEnabledPaymentMethods()
    {
        $this->load->model('extension/payment/craftgate_payment_gateway');
        $enabledPaymentMethods = $this->config->get('payment_craftgate_payment_gateway_enabled_payment_methods');
        if (empty($enabledPaymentMethods)) {
            return null;
        }
        return array_map('trim', explode(',', $enabledPaymentMethods));

    }

    private function validateCallbackParams()
    {
        $order_id = $this->request->get["order_id"];
        $token = $this->request->post["token"];
        if (!isset($order_id) || !isset($token)) {
            throw new Exception('Your payment could not be processed.');
        }
    }

    private function validateOrder($checkout_form_result)
    {
        if (!isset($checkout_form_result->conversationId) || $checkout_form_result->conversationId != $this->request->get["order_id"]) {
            throw new Exception('Your payment could not be processed.');
        }
    }

    private function buildCraftgatePaymentUrl($craftgate_payment_id)
    {
        $url = 'https://panel.craftgate.io/payments/';
        if ($this->config->get('payment_craftgate_payment_gateway_sandbox_mode')) {
            $url = 'https://sandbox-panel.craftgate.io/payments/';
        }

        $url .= $craftgate_payment_id;
        $link = "<a target='_blank' href='$url'>$url</a>";
        return $link;
    }


    private function buildInitCheckoutFormRequest()
    {
        $this->load->model('checkout/order');
        $order_id = $this->session->data['order_id'];
        $order_info = $this->getOrder($order_id);
        $cart_total_amount = $order_info['total'] * $order_info['currency_value'];

        $items = $this->buildCartItems($order_info);
        return array(
            'price' => $this->calculateTotalProductPrice($items),
            'paidPrice' => $this->formatPrice($cart_total_amount),
            'currency' => 'TRY',
            'paymentGroup' => 'LISTING_OR_SUBSCRIPTION',
            'conversationId' => $order_id,
            'callbackUrl' => $this->getSiteUrl() . 'index.php?route=extension/payment/craftgate_payment_gateway/callback&order_id=' . $order_id,
            'enabledPaymentMethods' => $this->retrieveEnabledPaymentMethods(),
            'items' => $items,
        );
    }

    private function buildCartItems($order_info)
    {
        $products = $this->cart->getProducts();
        $shippingInfo = $this->getShippingInfo();
        $items = [];

        if ($products) {
            foreach ($products as $product) {
                $items[] = [
                    'externalId' => $product['product_id'],
                    'name' => $product['name'],
                    'price' => $this->formatPrice($product['total'] * $order_info['currency_value']),
                ];
            }
        }

        if ($shippingInfo && $shippingInfo['cost'] && $this->formatPrice($shippingInfo['cost']) > 0) {
            $items[] = [
                'externalId' => $shippingInfo['title'] . '-' . $shippingInfo['code'],
                'name' => $shippingInfo['title'],
                'price' => $this->formatPrice($shippingInfo['cost'] * $order_info['currency_value']),
            ];
        }

        return $items;
    }

    private function buildErrorResponse($errorCode, $errorDescription)
    {
        return array(
            'errors' => array(
                'errorCode' => $errorCode,
                'errorDescription' => $errorDescription
            )
        );
    }

    private function calculateTotalProductPrice($items)
    {
        $total_price = 0;
        foreach ($items as $item) {
            $total_price += $this->formatPrice($item['price']);
        }
        return $total_price;
    }

    private function formatPrice($number)
    {
        return round((float)$number, 2);
    }

    private function getShippingInfo()
    {
        if (isset($this->session->data['shipping_method'])) {
            $shipping_info = $this->session->data['shipping_method'];
        } else {
            $shipping_info = false;
        }
        return $shipping_info;
    }

    private function getOrder($order_id)
    {
        $this->load->model('extension/payment/craftgate_payment_gateway');
        return $this->model_extension_payment_craftgate_payment_gateway->getOrder($order_id);
    }


    private function setCookies()
    {
        $cookieControl = false;

        if (isset($_COOKIE['PHPSESSID'])) {
            $sessionKey = "PHPSESSID";
            $sessionValue = $_COOKIE['PHPSESSID'];
            $cookieControl = true;
        }

        if (isset($_COOKIE['OCSESSID'])) {

            $sessionKey = "OCSESSID";
            $sessionValue = $_COOKIE['OCSESSID'];
            $cookieControl = true;
        }

        if ($cookieControl) {
            $setCookie = $this->setcookieSameSite($sessionKey, $sessionValue, time() + 86400, "/", $_SERVER['SERVER_NAME'], true, true);
        }
    }

    private function setcookieSameSite($name, $value, $expire, $path, $domain, $secure, $httponly)
    {

        if (PHP_VERSION_ID < 70300) {

            setcookie($name, $value, $expire, "$path; samesite=None", $domain, $secure, $httponly);
        } else {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => $path,
                'domain' => $domain,
                'samesite' => 'None',
                'secure' => $secure,
                'httponly' => $httponly,
            ]);

        }
    }

    private function getSiteUrl()
    {
        return self::isHttps()
            ? ($this->config->get('config_ssl') ?: HTTPS_SERVER)
            : ($this->config->get('config_url') ?: HTTP_SERVER);
    }

    private function getServerConnectionSlug()
    {
        return self::isHttps() ? 'SSL' : 'NONSSL';

    }

    private static function isHttps()
    {
        static $ret;

        isset($ret) || $ret = @ (
            $_SERVER['REQUEST_SCHEME'] == 'https' ||
            $_SERVER['SERVER_PORT'] == '443' ||
            $_SERVER['HTTPS'] == 'on'
        );

        return $ret;
    }
}
