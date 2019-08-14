<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_Helper_Config_Checkout extends Highstreet_Hsapi_Helper_Config_Account {

    /**
     * Constructor class
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Returns onepage object model
     *
     * @return object Mage_Checkout_Type_Onepage
     */
    public function _getOnepage() {
        return Mage::getSingleton('checkout/type_onepage');
    }

    /**
     * @return Mage_Customer_Model_Session
     */
    public function _getSession() {
        return Mage::getSingleton('customer/session');
    }

    /**
     * @return Mage_Checkout_Model_Session
     */
    public function _getCheckoutSession() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * @return Highstreet_Hsapi_Helper_Config_Cart
     */
    public function _getCartHelper() {
        return Mage::helper('highstreet_hsapi/config_cart');
    }

    /**
     * Set billing address to quote object
     *
     * @param array $address
     */
    public function setBillingAddressToQuote($address) {

        $data = array(
            "address_id" => (isset($address['id']) && is_numeric($address['id'])) ? $address['id'] : null,
            "city" => $address['city'],
            "company" => @$address['company'],
            "firstname" => $address['first_name'],
            "lastname" => $address['last_name'],
            "email" => @$address['email'],
            "country_id" => $address['country_id'],
            "region_id" => @$address['state'],
            "postcode" => @$address['postal_code'],
            "street" => array($address['street'], @$address['house_number'], @$address['addition']),
            "telephone" => (string) trim($address['telephone']),
        );

        $result = $this->_getOnepage()->saveBilling($data, $data['address_id']);
        if (isset($result['error'])) {
            $this->log($result, 'Save Billing address');
        }

        if ($address['use_same_for_shipping']) {
            $this->setShippingAddressToQuote($data, true);
        }
        return;
    }

    /**
     * Set shipping address to quote object
     *
     * @param object $quote
     * @param array $address
     * @param bool $as_billing
     * @return object $quote
     */
    public function setShippingAddressToQuote($address, $as_billing = false) {
        if ($as_billing) {
            $data = $address;
            $data["same_as_billing"] = 1;
        } else {
            $data = $this->getAddressDataFromArray($address);
            $data["same_as_billing"] = 0;
            $data["address_id"] = (is_numeric($data["address_id"])) ? $address['id'] : null;
        }
        if (isset($data))
            $data['telephone'] = trim($data['telephone']);
        $result = $this->_getOnepage()->saveShipping($data, $data['address_id']);
        if (isset($result['error'])) {
            $this->log($result, 'Save Shipping address');
        }
        return;
    }

    /**
     * Populate array with errors
     *
     * @param array $data
     * @param array $invalidFields
     * @param array $missingFields
     * @return array
     */
    public function checkMissingInvalidFields($data, $invalidFields = array(), $missingFields = array()) {
        $nonRequiredFields = array('coupon_codes', 'tax_included');
        $errorArray = array();
        foreach (array_keys($data, null) as $key) {
            if (in_array($key, $nonRequiredFields))
                continue;
            $errorArray[] = array(
                "code" => "missing",
                "field" => $key,
            );
        }
        foreach ($invalidFields as $invalidField) {
            $errorArray[] = array(
                "code" => "invalid",
                "field" => $invalidField,
            );
        }

        // already defined missing fields by controller
        foreach ($missingFields as $missingField) {
            $errorArray[] = array(
                "code" => "missing",
                "field" => $missingField,
            );
        }

        $data['_errors'] = $errorArray;
        return $data;
    }

    /**
     * Populate array with address data
     *
     * @param array $address
     * @return array
     */
    public function getAddressDataFromArray($address) {
        return array(
            "address_id" => @$address['id'],
            "city" => @$address['city'],
            "company" => @$address['company'],
            "firstname" => @$address['first_name'],
            "lastname" => @$address['last_name'],
            "country_id" => @$address['country_id'],
            "region_id" => @$address['state'],
            "postcode" => @$address['postal_code'],
            "street" => array(@$address['street'], @$address['house_number'], @$address['addition']),
            "telephone" => (string) trim(@$address['telephone']) . ' '
        );
    }

    /**
     * check required default magento address fields
     *
     * @param array $address
     * @param bool $asBool
     * @param array $missing
     * @param string $type
     * @return array
     */
    public function checkRequiredAddressFields($address, $asBool = false, $missing = array(), $type = 'billing_address') {
        $requiredFields = array('first_name', 'last_name', 'street', 'city', 'country_id', 'telephone');
        foreach ($requiredFields as $rfield) {
            if (!isset($address[$rfield]) || $address[$rfield] == null || $address[$rfield] == 'null') {
                if ($asBool)
                    return false;
                $missing[] = $type;
                return $missing;
                //$missing[] = $type . '_' . $rfield;
            }
        }
        if ($asBool)
            return true;
        return $missing;
    }

    /**
     * check is shipping method valid
     *
     * @param string $shipping_method
     * @return bool true | false
     */
    public function checkIsShippingMethodValid($shipping_method) {
        $shipping_method = $this->escapeString($shipping_method);
        // if address is set, use different method of retrieving shipping methods
        $cart = Mage::getSingleton('checkout/cart');
        $address = $cart->getQuote()->getShippingAddress();
        if ($address->getQuoteId() && $address->getFirstname()) {
            $methods = $this->getShippingMethods($address, $cart->getQuote(), true);
        } else {
            $methods = $this->getAllShippingMethods(true);
        }
        foreach ($methods as $sh_method) {
            if (isset($sh_method['code'])) {
                if ($shipping_method == $sh_method['code'])
                    return true;
            }
        }
        return false;
    }

    /**
     * returns array for JSON with ALL shipping methods
     *
     * @return array
     */
    public function getAllShippingMethods($asArray = false) {
        $jsonMethods = array();
        $methods = array();
        $activeCarriers = Mage::getSingleton('shipping/config')->getActiveCarriers();
        $activeMethods = array();
        foreach ($activeCarriers as $carrierCode => $carrierModel) {
            $m = array(
                'type' => 'option',
                'title' => Mage::getStoreConfig('carriers/' . $carrierCode . '/title'),
                'code' => $carrierCode,
                'price' => 0, //hardcoded for now
            );
            if ($carrierMethods = $carrierModel->getAllowedMethods()) {
                $options = array();
                foreach ($carrierMethods as $methodCode => $method) {
                    $code = $carrierCode . '_' . $methodCode;
                    $o = array(
                        'type' => 'option',
                        'title' => Mage::getStoreConfig('carriers/' . $carrierCode . '/title'),
                        'code' => $code,
                        'price' => 0, //hardcoded for now
                    );
                    $options[] = $o;
                    if ($asArray)
                        $activeMethods[] = $o;
                }

                $m['options'] = $options;
                //$carrierTitle = Mage::getStoreConfig('carriers/' . $carrierCode . '/title');
            }
            $methods[] = $m;
        }
        if ($asArray)
            return $activeMethods;
        $jsonMethods['shipping_methods'] = $methods;
        return $jsonMethods;
    }

    /**
     * returns array for JSON with shipping methods dependent on address
     *
     * @return array
     */
    public function getShippingMethods($address, $quote, $asArray = false) {
        $sp = array();
        $quote = $this->_getOnepage()->getQuote();
        $quote->getShippingAddress()->collectShippingRates();
        foreach ($quote->getShippingAddress()->getGroupedAllShippingRates() as $rates) {
            foreach ($rates as $rate) {
                if ($rate instanceof Mage_Shipping_Model_Rate_Result_Error) {
                    $errors[$rate->getCarrierTitle()] = 1;
                } else {
                    if ($address->getFreeShipping()) {
                        $price = 0;
                    } else {
                        $price = $rate->getPrice();
                    }
                    if ($price) {
                        // get shipping method default price with tax helper, with or without tax
                        if ($this->_getCartHelper()->displayShippingIncludeTax()) {
                            $price = $quote->getStore()->convertPrice(Mage::helper('tax')->getShippingPrice($price, null, $address));
                        } else {
                            $price = $quote->getStore()->convertPrice(Mage::helper('tax')->getShippingPrice($price, null, $address, true));
                        }
                    }
                    
                    $sp[$rate->getCarrier()][] = array(
                        'label' => $rate->getMethodTitle(),
                        'carrier' => $rate->getCarrierTitle(),
                        'code' => $rate->getCarrier() . '_' . $rate->getMethod(),
                        'price' => $price,
                    );
                }
            }
        }
        $options = array();
        // creating array ready for json output
        foreach ($sp as $key => $mt) {
            $carrierCode = $key;
            foreach ($mt as $mt_key => $method) {
                $code = $method['code'];
                $optionsArray = array(
                    'type' => 'option',
                    'title' => $method['label'],
                    'code' => $method['code'],
                    'price' => $method['price'],
                );
                if (strstr($carrierCode, "postnl") !== false && $this->isPostNLDeliveryDaysEnabled()) {
                    $postNLOptionsRaw = $this->getPostNlDeliveryOptions($address);
                    if (count($postNLOptionsRaw)) {
                        $optionsArray['options'] = $this->convertPostNLResponseToSubOptions($postNLOptionsRaw, $method['price']);
                    }
                }
                $options[] = $optionsArray;
            }
        }
        if ($asArray)
            return $options;
        $jsonMethods['shipping_methods'] = $options;
        return $jsonMethods;
    }

    /**
     * get PostNl delivery day
     * 
     * @return bool
     */
    public function isPostNLDeliveryDaysEnabled() {
        return (bool) Mage::getStoreConfig('postnl/delivery_options/enable_delivery_days');
    }

    /**
     * convert raw data from postNl extension to custom format
     *
     * @param object $postNLOptionsRaw
     * @param float $price
     * @return array
     */
    public function convertPostNLResponseToSubOptions($postNLOptionsRaw, $price = 0) {
        $response = array();
        foreach ($postNLOptionsRaw as $postNLDate) {
            foreach ($postNLDate->Timeframes->TimeframeTimeFrame as $timeframes) {
                //var_dump($option->Timeframes->TimeframeTimeFrame);die;
                $timeFrom = date('H:i', strtotime($timeframes->From));
                $timeTo = date('H:i', strtotime($timeframes->To));
                $response[] = array(
                    'type' => 'option',
                    'title' => 'Op ' . $postNLDate->Date . ' tussen ' . $timeFrom . ' to ' . $timeTo,
                    'code' => $this->getPostNLCode($timeframes->Options->string[0], $postNLDate->Date, $timeframes->From, $timeframes->To),
                    'price' => $price,
                    'default' => false
                );
            }
        }
        return $response;
    }

    /**
     * convert postnl array to base64 string
     *
     * @return string base64_encode
     */
    public function getPostNLCode($delivery, $date, $from, $to, $costIncl = 0, $costExcl = 0) {
        // taxconfig code taken from DeliveryOptionsController
        $taxConfig = Mage::getSingleton('tax/config');
        $costs = ($taxConfig->shippingPriceIncludesTax()) ? $costIncl : $costExcl;

        // array structure is same as function saveDeliveryOption from PostNl DeliveryOptionsController expects
        $jsonArray = array(
            'type' => $this->translatePostNL($delivery),
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'costs' => $costs
        );
        return $this->b64e(json_encode($jsonArray));
    }

    /**
     * manual translate for PostNL delivery type
     *
     * @param string $string
     * @return string
     */
    public function translatePostNL($string) {
        return ($string == "Evening") ? "Avond" : "Overdag";
    }

    /**
     * returns array for JSON with ALL active payment methods filtered by country set by shipping address
     *
     * @return array
     */
    public function getAllPaymentMethods($asArray = false) {
        $jsonMethods = array();
        $methods = array();
        $model = new Mage_Checkout_Block_Onepage_Payment_Methods();
        $quote = Mage::getSingleton('checkout/cart')->getQuote();
        $selectedPaymentMethod;
        try {
            $selectedPaymentMethod = $quote->getPayment()->getData('method');
        } catch (Exception $e) {
            $this->logException($e, 'Get payment method');
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()));
            return;
        }
        foreach ($model->getMethods() as $method) {
            $methodTitle = $method->getTitle();
            $methodCode = $method->getCode();
            if ($methodCode == "paypal_express") { // PayPal. Has logo and strange label text, override
                $methodTitle = "PayPal";
            }
            $m = array(
                'type' => 'option',
                'title' => $method->getTitle(),
                'code' => $methodCode,
                'price' => $this->getPaymentMethodPrice($methodCode),
                // Payment fee (price) is not available for standard payment methods, for extensions this need to be individualy coded
                'image' => null,
                'options' => $this->_getSuboptionsForPaymentMethod($methodCode),
            );
            $methods[] = $m;
        }
        if ($asArray)
            return $methods;
        $jsonMethods['payment_methods'] = $methods;

        return $jsonMethods;
    }

    /**
     * get payment price, used for external payment methods (buckaroo, Adyen...)
     *
     * @param string $methodCode
     * @return float
     */
    public function getPaymentMethodPrice($methodCode) {
        if (strstr($methodCode, 'buckaroo'))
            return $this->getBuckarooFee($methodCode);
        if ($methodCode == "adyen_ideal")
            return $this->getAdyenIdealFee($methodCode);
        return 0;
    }

    /**
     * returns JSON with ALL active payment methods, methods that are enabled in backend
     *
     * @return string JSON
     */
    public function getAllActivePaymentMethods($asArray = false) {
        $jsonMethods = array();
        $methods = array();
        $allActivePaymentMethods = Mage::getModel('payment/config')->getActiveMethods();
        foreach ($allActivePaymentMethods as $paymentCode => $paymentModel) {
            $paymentTitle = Mage::getStoreConfig('payment/' . $paymentCode . '/title');
            if (strstr($paymentCode, 'buckaroo') && !strstr($paymentCode, 'lightbox') && !Mage::getStoreConfig('buckaroo/' . $paymentCode . '/active'))
                continue;
            $m = array(
                'type' => 'option',
                'title' => $paymentTitle,
                'code' => $paymentCode,
                'price' => (strstr($paymentCode, 'buckaroo')) ? $this->getBuckarooFee($paymentCode) : 0,
                // Payment fee (price) is not available for standard payment methods, for extensions this need to be individualy coded
                'image' => null,
                'options' => ($ideal_options = $this->_getSuboptionsForPaymentMethod($paymentCode)) ? $ideal_options : array(),
            );
            $methods[] = $m;
        }
        if ($asArray)
            return $methods;
        $jsonMethods['payment_methods'] = $methods;
        $this->_JSONencodeAndRespond($jsonMethods, "200 OK");
        return;
    }

    /**
     * returns JSON with ALL enabled shipping countries
     *
     * @return string JSON
     */
    public function getShippingCountries() {
        $countryArray = array();

        $countryList = Mage::getModel('directory/country')->getResourceCollection()
                ->loadByStore()
                ->toOptionArray(true);
        foreach ($countryList as $country) {
            if ($country['label'] == '' || $country['value'] == '')
                continue;
            $c = array(
                "name" => $country['label'],
                "code" => $country['value'],
                "states" => $this->getStatesOfCountry($country['value']),
            );
            $countryArray[] = $c;
        }
        $this->_JSONencodeAndRespond($countryArray, "200 OK");
        return;
    }

    /**
     * returns array with all states
     *
     * @param string ISO country code, example "NL", "US"
     * @return array
     */
    public function getStatesOfCountry($country) {
        $stateArray = Mage::getModel('directory/country')->load($country)->getRegions();
        $st = array();
        if (count($stateArray) > 0) {
            foreach ($stateArray as $state) {
                $st[] = array(
                    'code' => $state->getData('code'),
                    'name' => $state->getData('name')
                );
            }
            return $st;
        } else {
            return array();
        }
    }

    /**
     * returns subsoptions
     * used for Buckaroo method IDEAL, and Adyen IDEAL
     *
     * @param string $method
     * @return array/bool
     */
    public function _getSuboptionsForPaymentMethod($method) {
        $options = array();
        if ($method === "buckaroo3extended_ideal") {
            $options = $this->getBuckarooPaymentSuboptions($method);
        } elseif ($method === "adyen_ideal") {
            $options = $this->getAdyenPaymentSuboptions($method);
        }
        return $options;
    }

    /**
     * returns Adyen Ideal payment suboptions
     *
     * @param string $method
     * @return array
     */
    public function getAdyenPaymentSuboptions($method) {
        $options = array();
        $issuerList = $this->getAdyenIssuers();
        $price = $this->getAdyenIdealFee($method);
        foreach ($issuerList as $issuer => $issuerDetails) {
            // logo code from Adyen_Payment_Block_Form_Ideal
            $_bankFile = strtoupper(str_replace(" ", '', $issuerDetails['label']));
            $logo = Mage::getDesign()->getSkinUrl("images/adyen/$_bankFile.png");
            $option = array();
            $option["type"] = "option";
            $option["price"] = $price;
            $option["title"] = $issuerDetails['label'];
            $option["code"] = $issuer;
            $option["image"] = $logo;
            $options[] = $option;
        }
        return $options;
    }

    /**
     * gets JSON issuer list for ideal, and converts it to specific array
     *
     * @return array
     */
    public function getAdyenIssuers() {
        $storeId = $this->getStoreId();
        $json_issuers = Mage::getStoreConfig("payment/adyen_ideal/issuers", $storeId);
        // code partially taken from Adyen_Payment_Model_Adyen_Ideal
        $issuerData = json_decode($json_issuers, true);
        $issuers = array();
        if (!$issuerData) {
            return $issuers;
        }
        foreach ($issuerData as $issuer) {
            $issuers[(string) $issuer['issuerId'] . " "] = array(
                'label' => $issuer['name']
            );
        }
        ksort($issuers);
        return $issuers;
    }

    /**
     * returns Buckaroo Ideal payment suboptions
     *
     * @param string $method
     * @return array
     */
    public function getBuckarooPaymentSuboptions($method) {
        $options = array();

        $session = Mage::getSingleton('checkout/session');
        $sessionValue = $session->getData('buckaroo3extended_ideal_BPE_Issuer');
        $buckarooIdealModel = new TIG_Buckaroo3Extended_Block_PaymentMethods_Ideal_Checkout_Form();
        $issuerList = $buckarooIdealModel->getIssuerList();

        $price = $this->getBuckarooFee($method);
        foreach ($issuerList as $issuer => $issuerDetails) {
            $option = array();
            $optionChecked = false;
            if (!empty($sessionValue) && array_key_exists($sessionValue, $issuerList)) {
                if ($issuer == $sessionValue) {
                    $optionChecked = true;
                }
            }
            $option["type"] = "option";
            $option["price"] = $price;
            $option["title"] = $issuerDetails['name'];
            $option["code"] = $issuer;
            $option["image"] = $issuerDetails['logo'];
            $options[] = $option;
        }
        return $options;
    }

    /**
     * returns Buckaroo payment fee from magento configuration
     *
     * @param string $method
     * @return string $price
     */
    public function getBuckarooFee($method) {
        $xpath = 'buckaroo/' . $method . '/payment_fee';
        $price = (Mage::getStoreConfig($xpath)) ? Mage::getStoreConfig($xpath) : 0;
        return $price;
    }

    /**
     * returns Adyen payment fee from magento configuration
     *
     * @param string $method
     * @return string $price
     */
    public function getAdyenIdealFee($method) {
        $xpath = 'payment/' . $method . '/fee';
        $price = (Mage::getStoreConfig($xpath)) ? Mage::getStoreConfig($xpath) : 0;
        return $price;
    }

    /**
     * returns true if shipping address is same as billing
     *
     * @param Mage_Sales_Model_Quote_Address $shipping
     * @return bool
     */
    public function sameAsBilling($shipping) {
        return (bool) $shipping->getData('same_as_billing');
    }

    /**
     * returns shiping method in specific array
     *
     * @param string $method
     * @return array
     */
    public function getShippingMethod($method) {
        if (is_array($method) && isset($method['code'])) {
            $method = $method['code'];
        }
        if ($method) {
            return array(
                'code' => $method,
                'options' => ($this->_getSession()->getPostNLData()) ? array('code' => $this->_getSession()->getPostNLData()) : null,
            );
        }
        return null;
    }

    /**
     * returns payment method in specific array
     *
     * @param string $method
     * @return array
     */
    public function getPaymentMethod($method) {
        if (strstr($method, 'buckaroo3extended')) {
            return $this->getPaymentMethodBuckarooArray($method);
        } elseif (strstr($method, 'adyen_ideal')) {
            return $this->getPaymentMethodAdyenArray($method);
        } else {
            return array(
                'code' => $method,
                'options' => null,
            );
        }
    }

    /**
     * returns options array for Buckaroo method
     *
     * @param string $method
     * @return array
     */
    public function getPaymentMethodBuckarooArray($method) {
        $issuer = $this->_getCheckoutSession()->getData('additionalFields');
        $code = (isset($issuer) && isset($issuer['Issuer'])) ? $issuer['Issuer'] : null;
        return array(
            'code' => $method,
            'options' => array(array(
                    'code' => $code
                ))
        );
    }

    /**
     * returns options array for Adyen_Ideal method
     *
     * @param string $method
     * @return array
     */
    public function getPaymentMethodAdyenArray($method) {
        $quote = $this->_getCartHelper()->_getQuote();
        $code = ($quote->getPayment()->getPoNumber()) ? $quote->getPayment()->getPoNumber() : null;
        return array(
            'code' => $method,
            'options' => array(array(
                    'code' => (string) $code . " "
                ))
        );
    }

    public function getPostNlDeliveryOptions($address) {
        $street = $address->getStreet();
        $postNlOptions = array();
        $cif = Mage::getModel('postnl_deliveryoptions/cif');
        if ($cif) {
            $housenumber = "1"; //default to 1.
            if (Mage::getStoreConfig('customer/address/street_lines') == 1) {
                $st = (is_array($street)) ? $street[0] : $street;
                preg_match('([0-9]+)', $st, $matches);
                if (isset($matches) && count($matches) > 0) {
                    $housenumber = $matches[0];
                }
            } else {
                if (is_array($street) && isset($street[1]))
                    $housenumber = $street[1];
            }
            $postNlOptions = $cif->setStoreId(Mage::app()->getStore()->getId())
                    ->getDeliveryTimeframes(array(
                'postcode' => str_replace(" ", "", $address->getPostcode()), // Postcode
                'housenumber' => $housenumber,
                'deliveryDate' => date('d-m-Y', strtotime('+ 1 day')), // Set delivery day to tomorrow
                'country' => $address->getCountryId(),
            ));
        }
        return $postNlOptions;
    }

    /**
     * check is payment method valid
     *
     * @param string $payment_method
     * @return bool
     */
    public function checkIsPaymentMethodValid($payment_method) {
        $payment_method = $this->escapeString($payment_method);
        $validPaymentMethods = $this->getAllPaymentMethods(true);
        foreach ($validPaymentMethods as $method) {
            if ($method['code'] == $payment_method)
                return true;
        }
        return false;
    }

    /**
     * add additional options to payment array, before its saved
     *
     * @param array $payment
     * @param array $params
     * @return array $payment
     */
    public function addAdditionalPaymentOptions($payment, $params) {
        if ($params['payment_method']['code'] == "adyen_ideal" && isset($params['payment_method']['options']['code'])) {
            $trimmed = trim((string) $params['payment_method']['options']['code']);
            $payment["adyen_ideal_type"] = (string) $trimmed;
        }
        return $payment;
    }

}
