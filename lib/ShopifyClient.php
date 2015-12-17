<?php

namespace WIC;

use WIC\ShopifyApiException;
use WIC\ShopifyCurlException;

class ShopifyClient
{

    public $shop_domain;
    protected $token;
    protected $api_key;
    protected $secret;
    protected $last_response_headers = null;
    protected $calls_left;

    /**
     * Create a Shopify Client
     *
     * @param String $shop_domain Shop domain
     * @param String $token Token getted after install
     * @param String $api_key App API Key
     * @param String $secret App API Secret
     */
    public function __construct($shop_domain, $token, $api_key, $secret) {
        $this->name = "ShopifyClient";
        $this->shop_domain = preg_replace('/^https?:\/\//i', '', $shop_domain);
        $this->token = $token;
        $this->api_key = $api_key;
        $this->secret = $secret;
        $this->calls_left = 2;
    }

    /**
     * Get the URL required to request authorization
     *
     * @param Array||string $scope Scope(s) used by our Shopify App
     * @param String $redirect_url
     * @return String url to redirect to
     */
    public function getAuthorizeUrl($scope, $redirect_url = null) {

        $scope = is_array($scope) ? implode(',', $scope) : $scope;
        $data = array(
            'client_id' => $this->api_key,
            'scope'     => $scope
        );

        if ($redirect_url)
            $data['redirect_uri'] = $redirect_url;

        return sprintf('http://%s/admin/oauth/authorize?%s', $this->shop_domain, http_build_query($data));
    }

    /**
     * Once the User has authorized the app, call this with the code to get the access token
     *
     * @param string $code
     * @return token || false
     */
    public function getAccessToken($code) {
        // POST to  POST https://SHOP_NAME.myshopify.com/admin/oauth/access_token
        $url = "https://{$this->shop_domain}/admin/oauth/access_token";
        $payload = http_build_query(array(
            'client_id'     => $this->api_key,
            'client_secret' => $this->secret,
            'code'          => $code
        ));
        $response = $this->curlHttpApiRequest('POST', $url, '', $payload, array());
        $response = json_decode($response, true);

        // If there is token, we affect it to the client
        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
            return $this->token;
        }

        // Else we return false
        return false;
    }

    public function callsMade() {
        return $this->shopApiCallLimitParam(0);
    }

    public function callLimit() {
        return $this->shopApiCallLimitParam(1);
    }

    public function callsLeft() {
        return $this->callLimit() - $this->callsMade();
    }

    /**
     * Make a request to Shopify API
     *
     * @param String $method HTTP Method: 'GET', 'POST', 'DELETE', 'PUT'
     * @param String $path URL to call
     * @param Array $params Data or filters
     * @return type
     * @throws ShopifyApiException
     */
    protected function call($method, $path, $params = array()) {
        $baseurl = "https://{$this->shop_domain}/";

        $url = $baseurl . ltrim($path, '/');
        $query = in_array($method, array('GET', 'DELETE')) ? $params : array();
        $payload = in_array($method, array('POST', 'PUT')) ? json_encode($params) : array();
        $request_headers = in_array($method, array('POST', 'PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

        // add auth headers
        $request_headers[] = 'X-Shopify-Access-Token: ' . $this->token;

        $response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);
        $response = json_decode($response, true);

        if (isset($response['errors']) or ( $this->last_response_headers['http_status_code'] >= 400))
            throw new ShopifyApiException($method, $path, $params, $this->last_response_headers, $response);

        return $response;
    }

    /**
     * Make a GET request
     *
     * @param String $path URL to call
     * @param Array $filters filters
     */
    public function get($path, Array $filters = array()) {
        return $this->call('GET', $path, $filters);
    }

    /**
     * Make a POST request
     *
     * @param String $path URL to call
     * @param Array $data data to send
     */
    public function post($path, Array $data) {
        return $this->call('POST', $path, $data);
    }

    /**
     * Make a PUT request
     *
     * @param String $path URL to call
     * @param Array $params Data or filters
     */
    public function put($path, Array $data) {
        return $this->call('PUT', $path, $data);
    }

    /**
     * Make a DELETE request
     *
     * @param String $path URL to call
     * @param Array $params Data or filters
     */
    public function delete($path, $params = array()) {
        return $this->call('DELETE', $path, $params);
    }

    /**
     * Create a new model (CRUD Helper)
     *
     * @param string $path URL to call
     * @param array $data Model data
     * @return array the new model
     */
    public function create($path, Array $data) {
        return $this->post($path, $data);
    }

    /**
     * Retrieve a model or a list of models (CRUD Helper)
     *
     * @param string $path URL to call
     * @param array $filters filters to precise request
     * @return array Model or model list
     */
    public function read($path, Array $filters = array()) {
        return $this->get($path, $filters);
    }

    /**
     * Update a model (CRUD Helper)
     *
     * @param string $path URL to call
     * @param array $data Model updated data
     * @return array Updated model
     */
    public function update($path, Array $data) {
        return $this->put($path, $data);
    }

    /**
     * Verify if request is valid
     *
     * @param Array $query
     * @return boolean
     */
    public function validateSignature($query) {
        if (!is_array($query) || empty($query['hmac']) || !is_string($query['hmac']))
            return false;

        $hmac = $query['hmac'];
        $map = array();
        unset($query['hmac']);

        if (!empty($query['signature']) && is_string($query['signature']))
            unset($query['signature']);

        ksort($query);
        foreach ($query as $key => $value)
            $map[] = $key . '=' . $value;

        $signature = hash_hmac('sha256', implode('&', $map), $this->secret);
        return $hmac === $signature;
    }

    protected function curlHttpApiRequest($method, $url, $query = '', $payload = '', $request_headers = array()) {
        if ($this->calls_left < 1) {
            sleep(1);
        }

        $url = $this->curlAppendQuery($url, $query);
        $ch = curl_init($url);
        $this->curlSetopts($ch, $method, $payload, $request_headers);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new ShopifyCurlException($error, $errno);
        }

        list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
        $this->last_response_headers = $this->curlParseHeaders($message_headers);
        $this->calls_left = (int)$this->callsLeft();
        return $message_body;
    }

    protected function curlAppendQuery($url, $query) {
        if (empty($query))
            return $url;
        if (is_array($query))
            return "$url?" . http_build_query($query);
        else
            return "$url?$query";
    }

    protected function curlSetopts($ch, $method, $payload, $request_headers) {
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ohShopify-php-api-client');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($request_headers))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

        if ($method != 'GET' && !empty($payload)) {
            if (is_array($payload))
                $payload = http_build_query($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    protected function curlParseHeaders($message_headers) {
        $header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
        $headers = array();
        list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim(array_shift($header_lines)), 3);
        foreach ($header_lines as $header_line) {
            list($name, $value) = explode(':', $header_line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
        }

        return $headers;
    }

    protected function shopApiCallLimitParam($index) {
        if ($this->last_response_headers == null) {
            throw new Exception('Cannot be called before an API call.');
        }
        $params = explode('/', $this->last_response_headers['http_x_shopify_shop_api_call_limit']);
        return (int)$params[$index];
    }

}
