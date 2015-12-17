<?php
/*
 * ShopifyAPIClient
 * 
 *  @author    Web In Color - addons@webincolor.fr
 *  @version   1.0.0
 *  @since     1.0.0 - 13 oct. 2015
 *  @package   ShopifyAPIClient
 *  @copyright Copyright &copy; 2015, Web In Color
 *  @license   GNU GPL v3
 */

namespace WIC;

use Exception;

/**
 * Throwed when API calls fails
 */
class ShopifyApiException extends Exception
{

    protected $method;
    protected $path;
    protected $params;
    protected $response_headers;
    protected $response;

    function __construct($method, $path, $params, $response_headers, $response)
    {
        $this->method = $method;
        $this->path = $path;
        $this->params = $params;
        $this->response_headers = $response_headers;
        $this->response = $response;

        parent::__construct($response_headers['http_status_message'], $response_headers['http_status_code']);
    }

    function getMethod()
    {
        return $this->method;
    }

    function getPath()
    {
        return $this->path;
    }

    function getParams()
    {
        return $this->params;
    }

    function getResponseHeaders()
    {
        return $this->response_headers;
    }

    function getResponse()
    {
        return $this->response;
    }
}
