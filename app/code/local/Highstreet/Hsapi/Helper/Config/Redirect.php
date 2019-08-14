<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_Helper_Config_Redirect extends Highstreet_Hsapi_Helper_Config_Checkout {

    const BASE_PATH = "/hsapi/";

    /**
     * Constructor class
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Get decoded path and set session cookies
     * 
     * @param object $requestObject
     * @param bool $redirectType (true = external, false = redirect)
     * @return string
     */
    public function getPathAndSetCookies($requestObject, $redirectType = true) {
        $pathHash = $requestObject->getParam('path', false);
        $sessionHash = $requestObject->getParam('session', false);
        $tid = $requestObject->getParam('tid', false);

        if (!$pathHash && !$redirectType) {
            $this->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'path');
            return false;
        }
        if (!$tid && $redirectType) {
            $this->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'tid');
            return false;
        }

        if (!$sessionHash) {
            $this->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'session');
            return false;
        }

        try {
            $decodedSessionJson = base64_decode($sessionHash);
            $paramsSession = $this->_JSONtoArray($decodedSessionJson);
            if (!$redirectType)
                $decodedPath = base64_decode($pathHash);
        } catch (Exception $e) {
            $this->logException($e, 'Redirect base64decode');
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()), "200 OK");
            return false;
        }

        if (!isset($paramsSession['frontend'])) {
            $this->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'frontend');
            return false;
        }
        if (!$redirectType && !isset($decodedPath)) {
            $this->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'path');
            return false;
        }

        $sessionCookie = $paramsSession['frontend'];

        // try to init session by cookie/session ID
        try {
            $session = Mage::getSingleton('core/session');
            // close session. the session model does not provide a method for this
            session_write_close();
            unset($_SESSION);
            // open new session
            $session->setSessionId($sessionCookie);
            $session->init('frontend', 'checkout');
        } catch (Exception $e) {
            $this->logException($e, 'Set Cookie Action - setting session cookie');
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()), "200 OK");
            return false;
        }

        // if this is external checkout, we set TID to session, and get checkout url from config or helper
        if ($redirectType) {
            $session->setHsTid($tid);
            $decodedPath = $this->getRedirectUrl();
        }

        // set cookie 
        $cookie = Mage::getSingleton('core/cookie');
        $cookie->set('frontend', $sessionCookie, time() + 3600, '/');
        if (isset($paramsSession['frontend_cid']))
            $cookie->set('frontend_cid', $paramsSession['frontend_cid'], time() + 3600, '/');

        return $decodedPath;
    }

    /**
     * get redirect url for external checkout
     * 
     * @return string
     */
    public function getRedirectUrl() {
        // get redirect url from API helper
        $urlFromHelper = Mage::helper('highstreet_hsapi/config_api')->checkoutRedirectUrl();
        return ($urlFromHelper) ? $urlFromHelper : Mage::helper('checkout/url')->getCheckoutUrl();
    }

}
