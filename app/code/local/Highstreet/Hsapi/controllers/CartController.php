<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_CartController extends Mage_Core_Controller_Front_Action {

    /**
     * @return Highstreet_Hsapi_Helper_Config_Cart
     */
    private function _getHelper() {
        return Mage::helper('highstreet_hsapi/config_cart');
    }

    /**
     * detects request method
     * method: POST, GET, PATCH
     * body: JSON
     * Content-Type: application/json
     */
    public function indexAction() {
        $method = $_SERVER['REQUEST_METHOD'];
        $request = $this->getRequest();
        $_errors = array();
        if (!$this->_getHelper()->_checkIsRequestValid($request)) {
            return;
        } else {
            // initialize cart before everything
            if ($method == "POST")
                $this->_getHelper()->cartInit();
            $response = "200 OK";
            if ($method == 'PUT') {
                $etag = $request->getHeader('If-Match');
                if ($etag && $etag != $this->_getHelper()->getCartEtag()) {
                    $response = "412 Precondition failed";
                }
            }
            
            // check if there are some unavailable products, and remove them
            if ($method == "GET")
                $_errors = $this->_getHelper()->checkAndUpdateCartInventory($_errors);
            
            $params = $this->_getHelper()->_JSONtoArray($request->getRawBody());
            
            // add products and coupon codes if method is POST or PUT
            if (count($params) && ($method == "POST" || $method == "PUT"))
                $_errors = $this->_getHelper()->addProductsAndCouponsToQuote($params);
        
            $this->_getHelper()->saveCartAndQuote();
            
            // get data for JSON
            $data = $this->_getData($_errors, $params, $method);

            // assign quote item id to errors 
            $data = $this->_getHelper()->assignQuoteItemIdToErrors($data, $params);

            // log data if logger is enabled
            if ($this->_getHelper()->isLogEnabled()) {
                $this->_getHelper()->log(array(
                    "Response code" => $response,
                    "Method" => $method,
                    "Data" => $data), 'Cart indexAction');
            }

            $this->_getHelper()->_JSONencodeAndRespond($data, $response);
        }
        return;
    }

    /**
     * get $data array
     * 
     * @param array $_errors
     * @param array $params
     * @param string $method
     * @return array
     */
    protected function _getData($_errors = array(), $params = array(), $method) {
        $request = $this->getRequest();
        $cart = $this->_getHelper()->_getCart();
        $data = array();

        // add etag to body
        $data['cart_etag'] = Mage::helper('highstreet_hsapi/config_cart')->getCartEtag();
        // add quote id
        $data['id'] = $this->_getHelper()->_getQuote()->getId();
        // add errors
        $data['_errors'] = $_errors;
        // add messages
        $data['_messages'] = array();
        // add products
        $data['items'] = $this->_getHelper()->getProductsFromCart($params);
        // add coupon code
        $data['coupon_codes'] = $this->_getHelper()->getCouponCodes();
        // add tax included
        $data['tax_included'] = $this->_getHelper()->isTaxIncluded();
        // add totals
        $data['totals'] = $this->_getHelper()->getTotals();

        return $data;
    }

}
