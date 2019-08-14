<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_CheckoutV3Controller extends Mage_Core_Controller_Front_Action {

    /**
     * detects request method
     * method: POST, GET, PATCH
     * body: JSON
     * Content-Type: application/json
     */
    public function indexAction() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!$this->_getHelper()->_checkIsRequestValid($this->getRequest())) {
            return;
        } else {
            switch ($method) {
                case 'POST':
                    $this->createCheckoutSession();
                    break;
                case 'GET':
                    $this->viewCheckoutSession();
                    break;
                case 'PATCH':
                    $this->updateCheckoutSession();
                    break;
            }
        }
        return;
    }

    /**
     * returns shipping methods
     * method: GET
     * body: JSON
     * Content-Type: application/json
     */
    public function shipping_methodsAction() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!$this->_getHelper()->_checkIsRequestValid($this->getRequest())) {
            return;
        }
        $cart = Mage::getSingleton('checkout/cart');
        $address = $cart->getQuote()->getShippingAddress();
        if ($address->getQuoteId() && $address->getFirstname()) {
            $jsonMethods = $this->_getHelper()->getShippingMethods($address, $cart->getQuote());
        } else {
            $jsonMethods = $this->_getHelper()->getAllShippingMethods();
        }

        Mage::dispatchEvent(
                'highstreet_hsapi_checkoutV3_shipping_methods_after', array(
            'shipping_methods' => &$jsonMethods,
            'quote' => $this->_getOnepage()->getQuote(),
            'request' => $this->getRequest()
                )
        );
        $this->_getHelper()->_JSONencodeAndRespond($jsonMethods, "200 OK");
        return;
    }

    /**
     * returns payment methods
     * method: GET
     * body: JSON
     * Content-Type: application/json
     */
    public function payment_methodsAction() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!$this->_getHelper()->_checkIsRequestValid($this->getRequest())) {
            return;
        }
        $paymentMethods = $this->_getHelper()->getAllPaymentMethods();

        Mage::dispatchEvent(
                'highstreet_hsapi_checkoutV3_payment_methods_after', array(
            'payment_methods' => &$paymentMethods,
            'quote' => $this->_getOnepage()->getQuote(),
            'request' => $this->getRequest()
                )
        );

        $this->_getHelper()->_JSONencodeAndRespond($paymentMethods, "200 OK");
        return;
    }

    /**
     * returns shipping countries
     * method: GET
     * body: JSON
     * Content-Type: application/json
     */
    public function shipping_countriesAction() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!$this->_getHelper()->_checkIsRequestValid($this->getRequest())) {
            return;
        }

        $this->_getHelper()->getShippingCountries();
        return;
    }

    /**
     * @return Highstreet_Hsapi_Helper_Config_Checkout
     */
    private function _getHelper() {
        return Mage::helper('highstreet_hsapi/config_checkout');
    }

    /**
     * @return Highstreet_Hsapi_Helper_Config_Redirect
     */
    private function _getRedirectHelper() {
        return Mage::helper('highstreet_hsapi/config_redirect');
    }

    /**
     * @return Highstreet_Hsapi_Helper_Config_Cart
     */
    private function _getCartHelper() {
        return Mage::helper('highstreet_hsapi/config_cart');
    }

    /**
     * @return Mage_Checkout_Model_Type_Onepage
     */
    protected function _getOnepage() {
        return Mage::getSingleton('checkout/type_onepage');
    }

    /**
     * @return Mage_Customer_Model_Session
     */
    protected function _getSession() {
        return Mage::getSingleton('customer/session');
    }

    /**
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckoutSession() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Update checkout session
     * method: PATCH
     * Content-Type: application/json
     */
    protected function updateCheckoutSession() {
        $request = $this->getRequest();
        if (!$this->_getHelper()->_checkIsRequestValid($request, array('PATCH'))) {
            return;
        }
        $quote = $this->_getOnepage()->getQuote();
        $params = $this->_getHelper()->_JSONtoArray($request->getRawBody());
        $invalidFields = array();
        $missingFields = array();

        //email is set only if customer is not logged in... Logged customers already have email preset.
        if (isset($params['email']) && !$this->_getHelper()->isLoggedIn()) {
            try {
                $email = $params['email'];
                if ($this->_getHelper()->checkIsEmailValid($email)) {
                    $quote->setCustomerEmail($params['email']);
                    $quote->save();
                } else {
                    $invalidFields[] = 'email';
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set Email address');
                $error = $this->__('Unable to set Email address');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        }

        // set billing address
        if (isset($params['addresses']['billing_address']) && $params['addresses']['billing_address'] != null && $params['addresses']['billing_address'] != 'null') {
            if (!$quote->getCustomerEmail()) {
                $error = $this->__('Please fill in email address');
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
            try {
                $billingAddress = $params['addresses']['billing_address'];
                $billingAddress['use_same_for_shipping'] = (isset($params['addresses']['shipping_address']) && $params['addresses']['shipping_address'] != null && $params['addresses']['shipping_address'] != 'null') ? 0 : 1;
                if ($quote->getCustomerEmail())
                    $billingAddress['email'] = $quote->getCustomerEmail();
                if ($this->_getHelper()->checkRequiredAddressFields($billingAddress, true)) {
                    $this->_getHelper()->setBillingAddressToQuote($billingAddress);
                    // save with billing info
                    $quote->save();
                    // save again after calculating new totals
                    $quote->collectTotals();
                    if ($billingAddress['use_same_for_shipping'])
                        $quote->getShippingAddress()->setCollectShippingRates(true);
                    $quote->save();
                } else {
                    $invalidFields[] = "billing_address";
                    if ($params['addresses']['shipping_address'] == null || $params['addresses']['shipping_address'] == 'null')
                        $invalidFields[] = "shipping_address";
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set Billing address');
                $error = $this->__('Unable to set Billing address');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        }
        // set shipping address
        if (isset($params['addresses']['shipping_address']) && $params['addresses']['shipping_address'] != null && $params['addresses']['shipping_address'] != 'null') {
            if (!$quote->getCustomerEmail()) {
                $error = $this->__('Please fill in email address');
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
            try {
                $shipping_address = $params['addresses']['shipping_address'];
                if ($quote->getCustomerEmail())
                    $shipping_address['email'] = $quote->getCustomerEmail();
                if ($this->_getHelper()->checkRequiredAddressFields($shipping_address, true)) {
                    $this->_getHelper()->setShippingAddressToQuote($shipping_address);
                    // save
                    $quote->save();
                    // save again after calculating new totals
                    $quote->collectTotals();
                    $quote->getShippingAddress()->setCollectShippingRates(true)->save();
                } else {
                    $invalidFields[] = "shipping_address";
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set Billing address');
                $error = $this->__('Unable to set Shipping address');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        }


        // set shipping method - depends on shipping address
        if (isset($params['shipping_method']) && $params['shipping_method'] != 'null' && $params['shipping_method'] != null) {
            try {
                $this->_getSession()->setPostNLData(null);
                $shippingMethod = $params['shipping_method'];
                if ($this->_getHelper()->checkIsShippingMethodValid($shippingMethod['code'])) {
                    if ($quote->getShippingAddress()->getSameAsBilling() || $quote->getShippingAddress()->getFirstname()) {
                        // check if shipping is postNl and it has suboption set
                        if (strstr($shippingMethod['code'], "postnl") && isset($shippingMethod['options']['code'])) {
                            $postNLdata = json_decode($this->_getHelper()->b64d($shippingMethod['options']['code']), true);
                            $this->_getSession()->setPostNLData($shippingMethod['options']['code']);
                            // code taken from PostNl controller DeliveryOptionsController
                            /** @var TIG_PostNL_Model_DeliveryOptions_Service $service */
                            $service = Mage::getModel('postnl_deliveryoptions/service');
                            $service->saveDeliveryOption($postNLdata);
                        }
                        $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
                        $result = $this->_getOnepage()->saveShippingMethod($shippingMethod['code']);
                        if ($result) {
                            $error = $this->__('Unable to set shipping method');
                        }
                        $quote->collectTotals()->save();
                    } else {
                        $error = $this->__('Unable to set shipping method without shipping address');
                    }
                } else {
                    $invalidFields[] = 'shipping_method';
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set shipping method');
                $error = $this->__('Unable to set shipping method');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        }

        // set coupon codes
        if (isset($params['coupon_codes']) && $params['coupon_codes'] != 'null' && $params['coupon_codes'] != null) {
            try {
                if (isset($params['coupon_codes'][0]['code']) && strlen($params['coupon_codes'][0]['code']) <= $this->_getCartHelper()->getCouponMaxLenght()) {
                    $couponCode = $params['coupon_codes'][0]['code'];
                    $oCoupon = Mage::getModel('salesrule/coupon')->load($couponCode, 'code');
                    if ($oCoupon->getRuleId()) {
                        $quote = Mage::getSingleton('checkout/cart')->getQuote();
                        $quote->getShippingAddress()->setCollectShippingRates(true);
                        $quote->setCouponCode($couponCode)
                                ->collectTotals()
                                ->save();
                    }
                    if ($couponCode != $quote->getCouponCode()) {
                        $invalidFields[] = 'coupon_codes';
                    } else {
                        $this->_getCartHelper()->updateCartEtag();
                    }
                } else {
                    $invalidFields[] = 'coupon_codes';
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set coupon code');
                $error = $this->__('Unable to set coupon code');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        } elseif (isset($params['coupon_codes']) && $params['coupon_codes'] == null) {
            // remove coupon codes
            $quote = Mage::getSingleton('checkout/cart')->getQuote();
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $quote->setCouponCode(null)
                    ->collectTotals()
                    ->save();
        }

        // set payment method
        if (isset($params['payment_method']) && isset($params['payment_method']['code'])) {
            try {
                $payment = array('method' => $params['payment_method']['code']);
                if ($this->_getHelper()->checkIsPaymentMethodValid($payment['method'])) {
                    // add additionl payment options, example: Adyen_Ideal bank id
                    $payment = $this->_getHelper()->addAdditionalPaymentOptions($payment, $params);
                    $result = $this->_getOnepage()->savePayment($payment);
                    if (isset($result['error'])) {
                        Mage::log($result);
                        $invalidFields[] = 'payment_method';
                    } else {
                        // set options to session like Buckaroo BPE Issuer
                        if (isset($params['payment_method']['options']['code'])) {
                            $this->_getCheckoutSession()->setData('additionalFields', array('Issuer' => $params['payment_method']['options']['code']));
                        }
                    }
                } else {
                    $invalidFields[] = 'payment_method';
                }
            } catch (Exception $e) {
                $this->_getHelper()->logException($e, 'Unable to set Payment Method');
                $error = $this->__('Unable to set Payment Method.');
            }
            if (isset($error)) {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $error), "200 OK");
                return;
            }
        }


        $this->viewCheckoutSession('200 OK', $invalidFields, $missingFields);
        return;
    }

    /**
     * View checkout session
     * method: GET
     * Content-Type: application/json
     * @param string $response
     * @param array $invalidFields
     * @param array $missingFields
     * @return json
     */
    protected function viewCheckoutSession($response = "200 OK", $invalidFields = array(), $missingFields = array()) {
        // call function saveCartAndQuote() just in case so collectTotals is calculated
        $this->_getCartHelper()->saveCartAndQuote();
        $request = $this->getRequest();
        $quote = $this->_getOnepage()->getQuote();
        $billAddress = $quote->getBillingAddress();
        $shipAddress = $quote->getShippingAddress();
        //populate fields
        $data = array(
            "id" => $quote->getId(),
            "cart_etag" => Mage::helper('highstreet_hsapi/config_cart')->getCartEtag(),
            "email" => $quote->getCustomerEmail(),
            "payment_method" => ($quote->getPayment()->getMethod()) ? $this->_getHelper()->getPaymentMethod($quote->getPayment()->getMethod()) : null,
            "shipping_method" => ($shipAddress->getQuoteId() && $shipAddress->getShippingMethod()) ? $this->_getHelper()->getShippingMethod($shipAddress->getShippingMethod()) : null,
            "coupon_codes" => ($quote->getCouponCode()) ? array(array('code' => $quote->getCouponCode())) : array(),
            "addresses" => array(
                "billing_address" => ($billAddress->getQuoteId() && $billAddress->getFirstname()) ? $this->_getHelper()->getAddressData($billAddress) : null,
                "shipping_address" => ($shipAddress->getQuoteId() && !$shipAddress->getSameAsBilling() && $shipAddress->getFirstname()) ? $this->_getHelper()->getAddressData($shipAddress) : null,
            ),
            "tax_included" => ($shipAddress && $shipAddress->getTaxAmount()) ? true : false,
            "totals" => $this->_getCartHelper()->getTotals(),
        );

        // shipping and billing address missing errors
        if ($data['addresses']['billing_address'] == null)
            $missingFields[] = "billing_address";
        if (($data['addresses']['billing_address'] == null && $data['addresses']['shipping_address'] == null) || ($data['addresses']['shipping_address'] == null && !$shipAddress->getSameAsBilling()) || ($data['addresses']['shipping_address'] == null && in_array('shipping_address', $invalidFields)))
            $missingFields[] = "shipping_address";
        // check missing fields
        $data = $this->_getHelper()->checkMissingInvalidFields($data, $invalidFields, $missingFields);
        // log data if logging is enabled
        if ($this->_getHelper()->isLogEnabled()) {
            $this->_getHelper()->log(array(
                "Response code" => $response,
                "Data array for JSON" => $data), 'View checkout session');
        }
        $this->_getHelper()->_JSONencodeAndRespond($data, $response);
        return;
    }

    /**
     * Create checkout session
     * method: POST
     * Content-Type: application/json
     */
    protected function createCheckoutSession() {
        //Can be used to determine elsewhere in the code whether this is a checkout session initiated in the app.
        Mage::getSingleton('checkout/session')->setIsHighstreetCheckoutSession(true);


        $request = $this->getRequest();
        if (!$this->_getHelper()->_checkIsRequestValid($request, array('POST'))) {
            return;
        }
        $quote = $this->_getOnepage()->getQuote();
        $params = $this->_getHelper()->_JSONtoArray($request->getRawBody());
        if (!isset($params['cart_id']) || $params['cart_id'] != $quote->getId()) {
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "cart_id is not set or do not match"));
            return;
        }
        if (!isset($params['cart_etag']) || $params['cart_etag'] != Mage::helper('highstreet_hsapi/config_cart')->getCartEtag()) {
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "cart_etag is not set or do not match"), "409"); // or 412 ???
            return;
        }

        $customer = $this->_getSession()->getCustomer();
        $isLoggedIn = $this->_getSession()->isLoggedIn();

        if ($isLoggedIn) {
            $defaultBillingAddressId = $customer->getDefaultBilling();
            $defaultShippingAddressId = $customer->getDefaultShipping();
            if ($defaultBillingAddressId > 0) {
                $billingAddress = $customer->getAddressById($defaultBillingAddressId);
                // if email is not pre set, do it now
                if (!$this->_getOnepage()->getQuote()->getCustomerEmail() && $customer->getEmail())
                    $this->_getOnepage()->getQuote()->setCustomerEmail($customer->getEmail());
                $this->_getOnepage()->getQuote()->getBillingAddress()->importCustomerAddress($billingAddress);
                $this->_getOnepage()->getQuote()->getShippingAddress()->importCustomerAddress($billingAddress);
                $this->_getOnepage()->getQuote()->getShippingAddress()->setSameAsBilling(1);
                $this->_getOnepage()->getQuote()->getShippingAddress()->setCollectShippingRates(true);
                if ($defaultBillingAddressId != $defaultShippingAddressId) {
                    $shippingAddress = $customer->getAddressById($defaultShippingAddressId);
                    $this->_getOnepage()->getQuote()->getShippingAddress()->importCustomerAddress($shippingAddress);
                    $this->_getOnepage()->getQuote()->getShippingAddress()->setSameAsBilling(0);
                    $this->_getOnepage()->getQuote()->getShippingAddress()->setCollectShippingRates(true);
                }
            }
            $quote->setCheckoutMethod('customer')->save();
        } else {
            $quote->setCheckoutMethod('guest')->save();
        }
        $this->viewCheckoutSession();
        return;
    }

    /**
     * Finalize checkout and create order
     * method: POST
     * body: tid
     * Content-Type: application/json
     */
    public function finalizeAction() {
        $request = $this->getRequest();
        if (!$this->_getHelper()->_checkIsRequestValid($request, array('POST'))) {
            return;
        }
        $params = $this->_getHelper()->_JSONtoArray($request->getRawBody());
        if (!isset($params['tid'])) {
            $this->_getHelper()->_fieldError(Highstreet_Hsapi_Helper_Config_Account::MISSING, 'tid');
            return;
        }
        Mage::getSingleton('checkout/session')->setHsTid($params['tid']);
        $result = array();
        $quote = $this->_getOnepage()->getQuote();
        try {
            $quote->collectTotals()->save();
            $this->_getOnepage()->saveOrder();
            $redirectUrl = $this->_getOnepage()->getCheckout()->getRedirectUrl();
        } catch (Mage_Payment_Model_Info_Exception $e) {
            $this->_getHelper()->logException($e, 'Finalize Checkout - Mage_Payment_Model_Info_Exception');
            // if payment method is not valid return ViewCheckout session with all missing fields
            $this->viewCheckoutSession('400 Bad Request');
            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getHelper()->logException($e, 'Finalize Checkout - Mage_Core_Exception');
            Mage::helper('checkout')->sendPaymentFailedEmail($this->_getOnepage()->getQuote(), $e->getMessage());
            // if payment method via Core functions is not valid return ViewCheckout session with all missing fields
            $this->viewCheckoutSession('400 Bad Request');
            return;
        } catch (Exception $e) {
            // if anything goes wrong, return proccessing error, log exception
            $this->_getHelper()->logException($e, 'Finalize Checkout');
            Mage::helper('checkout')->sendPaymentFailedEmail($this->_getOnepage()->getQuote(), $e->getMessage());
            $this->_getHelper()->_JSONencodeAndRespond($this->__('There was an error processing your order. Please contact us or try again later.'), "400 Bad Request");
            return;
        }
        $this->_getOnepage()->getQuote()->save();
        $result['hpp'] = null;
        if (isset($redirectUrl)) {
            $result['hpp'] = $redirectUrl;
        }

        $this->_getHelper()->_JSONencodeAndRespond($result, "200 Ok");
    }

    /**
     * External checkout
     * method: POST
     * POST_param: tid
     * POST_param: session
     * Content-Type: application/json
     */
    public function externalAction() {
        $requestObject = Mage::app()->getRequest();

        // get decoded path from reuest and set cookies
        $decodedPath = $this->_getRedirectHelper()->getPathAndSetCookies($requestObject);

        // redirect to web checkout
        if ($decodedPath)
            Mage::app()->getResponse()->setRedirect($redirectUrl)->sendResponse();
    }

}
