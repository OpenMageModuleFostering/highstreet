<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_Helper_Config_Default extends Mage_Core_Helper_Abstract {

    const LOG_FILE = "Highstreet_HSAPI.log";

    /**
     * Constructor class
     */
    public function __construct() {
        $this->_response = Mage::app()->getResponse();
    }

    /**
     * JSON validation
     *
     * @param string $json
     * @return boolen
     */
    public function isJSONValid($json) {
        $json_array = $this->_JSONtoArray($json);
        return (is_array($json_array)) ? true : false;
    }

    /**
     * Sets the proper headers 
     */
    public function _setHeader($responseCode) {
        Mage::getSingleton('core/session')->setLastStoreCode(Mage::app()->getStore()->getCode());
        header_remove('Pragma'); // removes 'no-cache' header
        $this->_response->setHeader('Content-Type', 'application/json', true);
        if ($responseCode) {
            $this->_response->setHeader('HTTP/1.1', $responseCode);
        }
    }

    /**
     * Sets headers and body with proper JSON encoding
     */
    public function _JSONencodeAndRespond($data, $responseCode = "400 Bad Request", $numeric_check = true) {
        $this->_setHeader($responseCode);
        if ($numeric_check === FALSE || version_compare(PHP_VERSION, '5.3.3', '<')) {
            $this->_response->setBody(json_encode($data));
        } else {
            $this->_response->setBody(json_encode($data, JSON_NUMERIC_CHECK));
        }
    }

    /**
     * checks request
     *
     *
     * @param object $request
     * @param array $methods
     * @return bool
     */
    public function _checkIsRequestValid($request, $methods = array('POST', 'GET', 'PUT', 'PATCH'), $checkBody = true) {
        if (!in_array($_SERVER['REQUEST_METHOD'], $methods)) {
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => "Wrong method"));
            return false;
        } elseif (strtolower($request->getHeader('Content-Type')) != 'application/json' && $_SERVER['REQUEST_METHOD'] == 'POST') {
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => "Wrong media type"));
            return false;
        } elseif (!$this->isJSONValid($request->getRawBody()) && $_SERVER['REQUEST_METHOD'] == 'POST' && $checkBody) {
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => "No JSON body"));
            return false;
        }
        return true;
    }

    /**
     * converts raw json to array
     *
     * @param string $raw_json
     * @return array
     */
    public function _JSONtoArray($raw_json) {
        try {
            $this->log($raw_json, 'JSON to decode');
            $decodedJson = Mage::helper('core')->jsonDecode($raw_json);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'JSON decoding failed');
            return;
        }
        return $decodedJson;
    }

    /**
     * HSAPI logger
     *
     * @param array | string $data
     * @param string $message
     * @param int $level
     * 
     * Log levels:
     * 0 - Emergency: system is unusable
     * 1 - Alert: action must be taken immediately
     * 2 - Critical: critical conditions
     * 3 - Error: error conditions
     * 4 - Warning: warning conditions
     * 5 - Notice: normal but significant condition
     * 6 - Informational: informational messages
     * 7 - Debug: debug messages
     */
    public function log($data = array(), $message = '', $level = Zend_Log::DEBUG) {
        if ($this->isLogEnabled()) {
            $dataToLog = array('message' => $message, 'data' => $data);
            Mage::log($dataToLog, $level, self::LOG_FILE, true);
        }
        return;
    }

    /**
     * Exception logger
     * 
     * @param Exception $e
     * @param string $message
     */
    public function logException(Exception $e, $message = '') {
        $logData = array(
            "Exception message" => $e->getMessage(),
            "Exception" => "\n" . $e->__toString());
        $this->log($logData, $message);
        return;
    }

    /**
     * returns setting from admin
     */
    public function isLogEnabled() {
        return Mage::getStoreConfig('highstreet_hsapi/developer/log_enabled');
    }

    /**
     * Use for direct DB access in read mode
     * 
     * @return Mage_Core_Model_Resource getConnection
     */
    public function directDBRead() {
        return $this->getCoreResource()->getConnection('core_read');
    }

    /**
     * Use for direct resource functions
     * 
     * @return Mage_Core_Model_Resource
     */
    public function getCoreResource() {
        return Mage::getSingleton('core/resource');
    }

    /**
     * Get current store id
     * 
     * @return int
     */
    public function getStoreId() {
        return Mage::app()->getStore()->getStoreId();
    }

}
