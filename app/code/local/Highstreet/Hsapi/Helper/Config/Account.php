<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_Helper_Config_Account extends Highstreet_Hsapi_Helper_Config_Default {

    const INVALID = "invalid";
    const MISSING = "missing";
    const DUPLICATE = "duplicate";

    public function getAddressStreetLines() {
        return Mage::getStoreConfig('customer/address/street_lines');
    }

    public function getNewAccountEmailTemplate() {
        return Mage::getStoreConfig('highstreet_hsapi/api/new_account_email_template');
    }

    public function getNewAccountEmailTemplateIsEnabled() {
        return Mage::getStoreConfig('highstreet_hsapi/api/new_account_email_enabled');
    }

    /**
     * Populate array with address data
     *
     * @param object $address
     * @return array
     */
    public function getAddressData($address) {
        $street = $address->getStreet();
        // extract house number from first line of address
        if (!isset($street[1]) && preg_match('/^([^\d]*[^\d\s]) *(\d.*)$/', $street[0], $pregResult)) {
            $street[0] = trim($pregResult[1]);
            $street[1] = $pregResult[2];
        }
        return array(
            'id' => $address->getAddressId(),
            'first_name' => $address->getFirstname(),
            'last_name' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => (is_array($street)) ? $street[0] : $street,
            'house_number' => (isset($street[1]) && !empty($street[1])) ? $street[1] : null,
            'addition' => (isset($street[2])) ? $street[2] : null,
            'postal_code' => $address->getPostcode(),
            'city' => $address->getCity(),
            'state' => $address->getRegion(),
            'country_id' => $address->getCountryId(),
            'telephone' => (string) $address->getTelephone() . ' ',
        );
    }

    /**
     * Find CustomerId from session hash
     *
     * @param string $authCode
     * @return bool/string
     */
    public function _getCustomerId($authCode) {
        // instantiate session model first
        $session = Mage::getSingleton('core/session');
        // close session. the session model does not provide a method for this
        session_write_close();
        unset($_SESSION);
        // open new session
        $session->setSessionId($authCode);
        $session->init('checkout', 'frontend');

        return (isset($_SESSION['core']['visitor_data']['customer_id'])) ? $_SESSION['core']['visitor_data']['customer_id'] : false;
    }

    /**
     * Get CustomerId from session 
     *
     * @return bool/string
     */
    public function _getCId() {
        return Mage::helper('customer')->getCustomer()->getId();
    }

    /**
     * Get is user logged in, Magento requires valid "frontend" cookie 
     *
     * @return bool/string
     */
    public function isLoggedIn() {
        return Mage::helper('customer')->isLoggedIn();
    }

    /**
     * Send corresponding email template
     *
     * @param object Mage_Customer_Model_Customer
     */
    public function _sendEmailTemplate($customer) {
        if ($this->getNewAccountEmailTemplateIsEnabled()) {
            // send custom email
            $template = $this->getNewAccountEmailTemplate();
            $store_id = Mage::app()->getStore()->getStoreId();
            $emailTemplate = Mage::getModel('core/email_template')->loadByCode($template);

            //variables passed to template
            $emailTemplateVariables = array(
                'customer.email' => $customer->getEmail(),
            );

            $processedTemplate = $emailTemplate->getProcessedTemplate($emailTemplateVariables);
            $emailTemplate->setSenderName(Mage::getStoreConfig('trans_email/ident_general/name'));
            $emailTemplate->setSenderEmail(Mage::getStoreConfig('trans_email/ident_general/email'));

            // subject is set inside email template
            //$emailTemplate->setTemplateSubject("subject");

            $emailTemplate->send($customer->getEmail(), $customer->getName(), $emailTemplateVariables);
        } else {
            // send default email
            $customer->sendNewAccountEmail('registered', '', Mage::app()->getStore()->getId());
        }
        return $this;
    }

    /**
     * checks required fields
     *
     * @param array $params
     * @return bool
     */
    public function _checkCreateAccountFields($params) {

        $fields = array('email', 'first_name', 'last_name', 'password');
        foreach ($fields as $field) {
            if (!isset($params[$field])) {
                $this->_fieldError(self::MISSING, $field);
                return false;
            } elseif (isset($params[$field]) && empty($params[$field])) {
                $this->_fieldError(self::INVALID, $field);
                return false;
            } elseif ($field == 'email') {
                if (!Zend_Validate::is($params[$field], 'EmailAddress')) {
                    $this->_fieldError(self::INVALID, $field, '"' . $params[$field] . '" is not a valid email');
                    return false;
                }
                $customer = Mage::getModel('customer/customer');
                $customer->setWebsiteId(Mage::app()->getWebsite()->getId());
                $customer->loadByEmail($params[$field]);
                if ($customer->getId()) {
                    $this->_fieldError(self::DUPLICATE, $field, 'This customer email already exists');
                    return false;
                }
            }
        }
        return true;
    }

    public function checkIsEmailValid($email) {
        return Zend_Validate::is($email, 'EmailAddress');
    }

    /**
     * checks required fields for Update action
     *
     * @param array $params
     * @return bool
     */
    public function _checkUpdateAccountFields($params) {

        foreach ($params as $key => $field) {
            if ($key == 'address')
                continue;
            if (empty($params[$key])) {
                $this->_fieldError(self::INVALID, $key);
                return false;
            }
            if ($key == 'email' && !empty($params[$key])) {
                if (!$this->checkIsEmailValid($params[$key])) {
                    $this->_fieldError(self::INVALID, 'email', '"' . $params[$key] . '" is not a valid email');
                    return false;
                }
            }
        }
        if (isset($params['address']) && !$this->_checkUpdateAccountAddressFields($params))
            return false;
        return true;
    }

    /**
     * checks required address fields for Update action
     *
     * @param array $params
     * @return bool
     */
    public function _checkUpdateAccountAddressFields($params) {

        $fields = array(
            'id',
            'first_name',
            'last_name',
            'company',
            'street',
            'house_number',
            'addition',
            'postal_code',
            'city',
            'state',
            'country_id',
            'telephone',
        );
        $missingFields = array(
            'first_name',
            'last_name',
            'street',
            'city',
            'country_id',
            'telephone',
        );

        foreach ($missingFields as $field) {
            if (!isset($params['address'][$field])) {
                $this->_fieldError(self::MISSING, $field, 'Fill in address ' . $field);
                return false;
            }
        }
        foreach ($fields as $field) {
            if (isset($params['address'][$field]) && empty($params['address'][$field])) {
                $this->_fieldError(self::INVALID, $field, 'Fill in address ' . $field);
                return false;
            }
        }
        return true;
    }

    /**
     * sets body to json error
     *
     * @param string $code
     * @param string $field
     */
    public function _fieldError($code, $field, $message = false) {
        $msg = ($message) ? $message : 'Fill in a ' . $field;
        $this->_JSONencodeAndRespond(array("code" => $code, "field" => $field, 'message' => $msg));
        return;
    }

    /**
     * prepare customer data for json
     *
     * @param object $customer
     * @return array
     */
    public function getCustomerData($customer) {
        $quote = $this->getCustomerQuote($customer->getEntityId());
        return array(
            'id' => $customer->getEntityId(),
            'email' => $customer->getEmail(),
            'handle' => $customer->getEmail(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname(),
            'cart_id' => $quote->getId()
        );
    }

    /**
     * retreives customer last quote
     *
     * @param string $customerId
     * @return object Mage_Sales_Model_Resource_Quote_Collection
     */
    public function getCustomerQuote($customerId) {

        /** @var Mage_Sales_Model_Resource_Quote_Collection $quoteCollection */
        // Get cart_id
        // reason for this approach: Magento CE1.7 and EE1.12 have bug where user can have 2 or more active quote's
        // by this method we are retreiving latest active one
        // there is no offical reported bug about this, but here its mentioned http://magento.stackexchange.com/questions/29621/how-can-registered-customer-have-two-active-quotes

        $quoteCollection = Mage::getModel('sales/quote')->getCollection()
                ->addFieldToFilter('customer_id', $customerId)
                ->addOrder('updated_at');
        $quote = $quoteCollection->getFirstItem();
        return $quote;
    }

    /**
     * fix for Magento street lines
     * if address is in 1 line all params are combined
     * if address is in 2 lines, house num, and addition are combined
     * any other case will go unmodified
     *
     * @param array $params
     * @return array $params
     */
    public function fixStreetLines($params) {
        $tempParams = $params;
        $tempParams['address']['street'] = (isset($tempParams['address']['street'])) ? $tempParams['address']['street'] : '';
        $tempParams['address']['house_number'] = (isset($tempParams['address']['house_number'])) ? $tempParams['address']['house_number'] : '';
        $tempParams['address']['addition'] = (isset($tempParams['address']['addition'])) ? $tempParams['address']['addition'] : '';
        switch ($this->getAddressStreetLines()) {
            case 1:
                $params['address']['street'] = $tempParams['address']['street'] .
                        ' ' . $tempParams['address']['house_number'] .
                        ' ' . $tempParams['address']['addition'];
                $params['address']['house_number'] = '';
                $params['address']['addition'] = '';
                break;
            case 2:
                $params['address']['house_number'] = $tempParams['address']['house_number'] .
                        ' ' . $tempParams['address']['addition'];
                $params['address']['addition'] = '';
                break;
        }
        return $params;
    }

    public function login($requestObject) {
        $session = Mage::getSingleton('customer/session');

        $success = false;
        $message = "";

        $loginArray = $requestObject->getParam('login');

        $email = $loginArray["username"];
        $password = $loginArray["password"];
        $this->log($loginArray, 'User login');
        if ($session->isLoggedIn()) {
            $success = false;
            $message = "hsapi.loginAction.success.already";
        } else {
            try {
                if ($session->login($email, $password)) {
                    $success = true;
                    $message = "hsapi.loginAction.success";
                }
            } catch (Mage_Core_Exception $e) {
                switch ($e->getCode()) {
                    case Mage_Customer_Model_Customer::EXCEPTION_EMAIL_NOT_CONFIRMED: { // E-mail not confirmed
                            $success = false;
                            $message = "hsapi.loginAction.error.activate";
                            break;
                        }
                    case Mage_Customer_Model_Customer::EXCEPTION_INVALID_EMAIL_OR_PASSWORD: { // E-mail or password wrong
                            $success = false;
                            $message = "hsapi.loginAction.error";
                            break;
                        }
                    default: {
                            $success = false;
                            $message = "hsapi.loginAction.error.fatal";
                            break;
                        }
                }
            } catch (Exception $e) {
                $success = false;
                $message = "hsapi.loginAction.error.fatal";
            }
        }

        $response = array();
        $response["success"] = $success;
        $response["message"] = $message;
        $responseCode = ($success) ? "200 OK" : "400 Bad Request";
        $this->log($response, $responseCode);
        $this->_JSONencodeAndRespond($response, $responseCode);
        return;
    }

    public function logout() {
        Mage::getSingleton('customer/session')->logout();
        $this->_JSONencodeAndRespond(array("OK"), "200 OK");
        return;
    }

    /**
     * @return Mage_Customer_Model_Session
     */
    public function getCustomer() {
        return Mage::getSingleton('customer/session')->getCustomer();
    }

    /**
     * @param string $string
     * @return string
     */
    public function escapeString($string) {
        return Mage::helper('core')->htmlEscape($string);
    }

    /**
     * @param string $string
     * @return string base64encode
     */
    public function b64e($string) {
        return base64_encode($string);
    }

    /**
     * @param string $string
     * @return string base64decode
     */
    public function b64d($string) {
        return base64_decode($string);
    }

}
