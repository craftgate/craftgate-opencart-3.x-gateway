<?php

class ModelExtensionPaymentCraftgatePaymentGateway extends Model
{

    public function getMethod($address, $total)
    {
        if (!in_array($this->session->data['currency'], ['TRY'])) {
            return array();
        }

        $this->load->language('extension/payment/craftgate_payment_gateway');

        return array(
            'code' => 'craftgate_payment_gateway',
            'title' => $this->config->get('payment_craftgate_payment_gateway_title'),
            'terms' => '',
            'sort_order' => $this->config->get('payment_craftgate_payment_gateway_sort_order')
        );
    }

    public function craftgate($api_key, $secret_key, $is_sandbox_active)
    {
        $base_url = $is_sandbox_active ? Craftgate::SANDBOX_API_URL : Craftgate::API_URL;
        return new Craftgate($api_key, $secret_key, $base_url);
    }

    public function getOrder($order_id)
    {
        $this->load->model('checkout/order');
        $order_id 		 = $this->db->escape($order_id);
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            throw new Exception("Order not found!");
        }
        return $order_info;

    }

    public function addInstallmentFeeToOrder($order_id, $new_total_amount) {

        $this->load->language('extension/payment/craftgate_payment_gateway');
        $order_info = $this->getOrder($order_id);        
		$order_total = (array) $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . $order_id . "' AND code = 'total' ");

		$last_sort_value = $order_total['row']['sort_order'] - 1;
		$last_sort_value = $this->db->escape($last_sort_value);

		$exchange_rate = $this->currency->getValue($order_info['currency_code']);
		$old_amount = $order_info['total'] * $order_info['currency_value'];

		$installment_fee = (float) ($new_total_amount - $old_amount) / $exchange_rate;
		$installment_fee = $this->db->escape($installment_fee);


        $this->db->query("INSERT INTO " . DB_PREFIX . "order_total SET order_id = '" .
		    $order_id . "',code = 'installment_fee',  title = '". $this->language->get('text_installment_fee')."', `value` = '" .
		    $installment_fee . "', sort_order = '" . $last_sort_value . "'");


		$order_total_data = (array) $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . $order_id . "' AND code != 'total' ");

		$total = 0;
		foreach ($order_total_data['rows'] as $row) {
		        $total += $row['value'];
		}
		$total = $this->db->escape($total);

		$this->db->query("UPDATE " . DB_PREFIX . "order_total SET  `value` = '" . $total . "' WHERE order_id = '$order_id' AND code = 'total' ");
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET total = '" . $total . "' WHERE order_id = '" . $order_id . "'");
	}

}

class Craftgate
{
    const API_URL = 'https://api.craftgate.io';
    const SANDBOX_API_URL = 'https://sandbox-api.craftgate.io';

    private $apiKey;
    private $secretKey;
    private $baseUrl = self::API_URL;


    public function __construct($apiKey, $secretKey, $baseUrl)
    {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->baseUrl = $baseUrl;
    }

    public function initCheckoutPayment(array $request)
    {
        $path = "/payment/v1/checkout-payments/init";
        $url = $this->prepareUrl($path);
        $headers = $this->prepareHeaders($path, $request);
        $response = Curl::post($url, $headers, $request);
        return $this->buildResponse($response);
    }

    public function retrieveCheckoutPayment($token)
    {
        $path = "/payment/v1/checkout-payments/" . $token;
        $url = $this->prepareUrl($path);
        $headers = $this->prepareHeaders($path);
        $response = Curl::get($url, $headers);
        return $this->buildResponse($response);
    }

    private function prepareUrl($path)
    {
        return $this->baseUrl . '/' . trim($path, '/');
    }

    private function prepareHeaders($path, $request = null)
    {

        $headers = array(
            'accept: application/json',
            'content-type: application/json'
        );

        $headers[] = 'x-api-key: ' . $this->apiKey;
        $headers[] = 'x-rnd-key: ' . ($randomString = Util::generateGuid());
        $headers[] = 'x-auth-version: v1';
        $headers[] = 'x-signature: ' . $this->generateSignature($path, $randomString, $request);

        return $headers;
    }

    private function generateSignature($path, $randomString, $request = null)
    {
        $hash = $this->baseUrl . urldecode($path)
            . $this->apiKey . $this->secretKey
            . $randomString . ($request ? json_encode($request) : '');

        return base64_encode(hash('sha256', $hash, true));
    }


    private function buildResponse($response)
    {
        $response_json = json_decode($response);
        if (isset($response_json->data)) {
            return $response_json->data;
        } else {
            return $response_json;
        }
    }

}

class Util
{

    public static function generateGuid()
    {
        if (function_exists('random_bytes')) {
            $input = random_bytes(32); // PHP 7.0
        } else {
            srand();
            $input = uniqid();
            while (strlen($input) < 32) {
                $input = substr($input . dechex(rand()), 0, 32);
            }
        }

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5($input), 4));
    }

}

class Curl
{
    const CONNECT_TIMEOUT = 10;
    const READ_TIMEOUT = 150;

    public static function get($url, array $headers)
    {
        return self::request($url, array(
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ));
    }

    private static function request($url, $options)
    {
        $request = curl_init($url);

        curl_setopt_array($request, $options);

        $response = curl_exec($request);

        if ($response === false) {
            throw new Exception(curl_error($request), curl_errno($request));
        }

        curl_close($request);
        unset($request); // PHP 8.0

        return $response;
    }

    public static function post($url, array $headers, $content)
    {
        return self::request($url, array(
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($content),
        ));
    }

}

