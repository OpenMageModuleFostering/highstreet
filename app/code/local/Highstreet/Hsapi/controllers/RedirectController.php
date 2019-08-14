<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_RedirectController extends Mage_Core_Controller_Front_Action {

    /**
     * @return Highstreet_Hsapi_Helper_Config_Redirect
     */
    private function _getHelper() {
        return Mage::helper('highstreet_hsapi/config_redirect');
    }

    /**
     * External checkout
     * method: POST
     * POST_param: tid
     * POST_param: session
     * Content-Type: application/json
     */
    public function setCookieAction() {
        $requestObject = Mage::app()->getRequest();
        
        // get decoded path from reuest and set cookies
        $decodedPath = $this->_getHelper()->getPathAndSetCookies($requestObject, false);
        
        // redirect to web checkout
        if ($decodedPath)
            Mage::app()->getResponse()->setRedirect($decodedPath)->sendResponse();
    }

}
