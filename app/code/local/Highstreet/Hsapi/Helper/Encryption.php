<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2015 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Helper_Encryption extends Mage_Core_Helper_Abstract {
    private $quoteIdHashSalt = "yw14dhejnr";
    private $hmacEncryptionKey = "5JbqhKdBGtV8J4PH82cm5YDr5f8b4Rbk";

    public function hashQuoteId($quoteId = 0) {
        return md5($quoteId . $this->quoteIdHashSalt);
    }

    public function APISignatureStringIsValid() {
    	$givenAPISignature = $_SERVER['HTTP_X_API_SIGNATURE'];
    	$signatureString = $this->_getSignatureString();
    	$encryptedString = $this->_SHA256EncryptString($signatureString);

    	return ($givenAPISignature !== NULL && $givenAPISignature === $encryptedString);
    }

    private function _getSignatureString() {
    	$serverMethod = $_SERVER['REQUEST_METHOD'];
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') {
			$serverScheme = 'https'; 
		} else {
			$serverScheme = 'http';
		}
        $serverHost = $_SERVER['HTTP_HOST'];
        $serverPort = $_SERVER['SERVER_PORT'];
        $serverUri = $_SERVER['REQUEST_URI'];

        $urlComponents = explode('?', $serverUri);

        $serverUri = $urlComponents[0];

        $params = array();
        if (count($urlComponents) >= 1) {
        	$params = $urlComponents[1];
        	parse_str($params, $params);
        }

        ksort($params);
        foreach ($params as $key => $value) {
            $paramString .= $key . '=' . urlencode($value);
            
            if (array_search($key, array_keys($params)) < count($params)-1) {
                $paramString .= '&';
            }
        }

        $string = $serverMethod . $serverScheme . $serverHost . ':' . $serverPort . $serverUri . $paramString;

        return $string;
    }

    private function _SHA256EncryptString($string) {
    	return hash_hmac('sha256', $string, $this->hmacEncryptionKey);
    }
}



