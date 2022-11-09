<?php

class EklaseOAuth
{
    const PROVIDER_HOST = "https://my.e-klase.lv/Auth/OAuth/";
    private $clientID;
    private $clientSecret;
    private $redirectURL;

    private $timeout = 15;
    private $connectimeout = 10;

    const TAG = "EklaseOAuth";
    private $error;

    public function getError()
    {
        return $this->error;
    }

    public function __construct($clientID, $clientSecret, $redirectURL)
    {
        $this->clientID = $clientID;
        $this->clientSecret = $clientSecret;
        $this->redirectURL = $redirectURL;
    }

    public static function getAuthorizeURL($clientID, $redirectURL)
    {
        return self::PROVIDER_HOST . "?client_id=" . $clientID . "&redirect_uri=" . $redirectURL;
    }

    public function getAccessToken($code, $parameters = array())
    {
        $parameters = array(
            "client_id" => $this->clientID,
            "redirect_uri" => $this->redirectURL,
            "client_secret" => $this->clientSecret,
            "code" => $code
        );
        $response = $this->doRequest($url = self::PROVIDER_HOST . 'GetAccessToken/', $parameters);

        $query = parse_url('?' . $response, PHP_URL_QUERY);
        parse_str($query, $params);
        return (isset($params['access_token'])) ? $params['access_token'] : "";
    }

    public function getResource($url, $token, $parameters = array())
    {
        $parameters = array("access_token" => $token);
        return $this->doRequest($url, $parameters);
    }

    public function doRequest($url = '', $parameters = array(), $requestType = 'GET')
    {
        $ci = curl_init();
        curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connectimeout);
        curl_setopt($ci, CURLOPT_HEADER, 0);
        curl_setopt($ci, CURLOPT_FAILONERROR, 0);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ci, CURLOPT_HTTPHEADER, array('Expect:'));
        switch ($requestType) {
            case "GET":
                curl_setopt($ci, CURLOPT_HTTPGET, 1);
                $url .= "?" . self::convertParameters($parameters);
                break;
            case "POST":
                curl_setopt($ci, CURLOPT_POST, 1);
                curl_setopt($ci, CURLOPT_POSTFIELDS, self::convertParameters($parameters));
                curl_setopt($ci, CURLOPT_FOLLOWLOCATION, TRUE);
                break;
        }

        curl_setopt($ci, CURLOPT_URL, $url);
        $response = curl_exec($ci);
        curl_close($ci);

        $result = json_decode($response);
        if (isset($result->error)) {
            error_log(self::TAG . ": type:" . $result->error->type . ", message:" . $result->error->message);
            return false;
        } else {
            return $response;
        }
    }

    public static function convertParameters($parameters = array())
    {
        $result = array();
        if (!empty($parameters)) {
            foreach ($parameters as $key => $value) {
                array_push($result, $key . "=" . trim($value));
            }
        }
        return implode("&", $result);
    }
}
