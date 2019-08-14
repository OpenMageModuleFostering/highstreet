<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_AccountController extends Mage_Core_Controller_Front_Action {

    /**
     * @return Highstreet_Hsapi_Helper_Config_Account
     */
    private function _getHelper() {
        return Mage::helper('highstreet_hsapi/config_account');
    }

    /**
     * @return Highstreet_Hsapi_Helper_Config_Checkout
     */
    private function _getCheckoutHelper() {
        return Mage::helper('highstreet_hsapi/config_checkout');
    }

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
                    $this->createUser();
                    break;
                case 'GET':
                    $this->viewUser();
                    break;
                case 'PATCH':
                    $this->updateUser();
                    break;
            }
        }
        return;
    }

    /**
     * Create Magento user
     * method: POST
     * body: JSON
     * Content-Type: application/json
     */
    protected function createUser() {
        $request = $this->getRequest();
        $params = $this->_getHelper()->_JSONtoArray($request->getRawBody());
        if (!$this->_getHelper()->_checkCreateAccountFields($params))
            return;

        // create Magento user
        $websiteId = Mage::app()->getWebsite()->getId();
        $store = Mage::app()->getStore();
        $email = (isset($params['email'])) ? $params['email'] : $params['handle'];

        $customer = Mage::getModel("customer/customer");
        $customer->setWebsiteId($websiteId)
                ->setStore($store)
                ->setFirstname($params['first_name'])
                ->setLastname($params['last_name'])
                ->setEmail($email)
                ->setPassword($params['password']);
        try {
            $customer->save();
            $customer->setConfirmation(null);
            $customer->save();
            $this->_getHelper()->_sendEmailTemplate($customer);
        } catch (Exception $e) {
            $this->_getHelper()->logException($e, 'Customer save');
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()));
            return;
        }
        $this->_getHelper()->_JSONencodeAndRespond(array(
            "id" => $customer->getId(),
            "email" => $customer->getEmail(),
            'handle' => $customer->getEmail(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname(),
                ), "201 Created");
        return;
    }

    /**
     * checks Authorization from header
     * not used function !!!
     * @return bool/string
     */
    protected function _checkAuth() {
        if ($this->getRequest()->getHeader('Authorization') == '') {
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "No authorization code"), "401 Unauthorized");
            return false;
        }
        return substr($this->getRequest()->getHeader('Authorization'), 7);
    }

    /**
     * View Magento user data
     * Validate user from session token and session files
     * method: GET
     * body: JSON
     * Content-Type: application/json
     * Authorization: frontend cookie, Magento authorization
     */
    protected function viewUser() {
        if ($this->_getHelper()->isLoggedIn()) {
            if ($customerId = $this->_getHelper()->_getCId()) {
                $customer = Mage::getModel('customer/customer')->load($customerId);

                $data = $this->_getHelper()->getCustomerData($customer);

                // get Primary Billing Address
                $primaryBillingAddress = $customer->getPrimaryBillingAddress();
                if ($primaryBillingAddress) {
                    $address = $this->_getHelper()->getAddressData($primaryBillingAddress);
                } else {
                    $addresses = $customer->getAddresses();
                    if (count($addresses)) {
                        foreach ($addresses as $adr) {
                            $address = $this->_getHelper()->getAddressData($adr);
                            // only first address
                            continue;
                        }
                    }
                }
                $data['address'] = (isset($address)) ? $address : array();
            } else {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "Session expired"), "401 Unauthorized");
                return;
            }
        } else {
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "Session expired"), "401 Unauthorized");
            return;
        }
        $this->_getHelper()->_JSONencodeAndRespond($data, "200 OK");
        return;
    }

    /**
     * Update Magento user data
     * Validate user from session token and session files
     * method: PATCH
     * body: JSON
     * Content-Type: application/json
     * Authorization: Bearer valid_token
     */
    protected function updateUser() {
        if ($this->_getHelper()->isLoggedIn()) {
            if ($customerId = $this->_getHelper()->_getCId()) {
                $customer = Mage::getModel('customer/customer')->load($customerId);
                $params = $this->_getHelper()->_JSONtoArray($this->getRequest()->getRawBody());

                if (!$this->_getHelper()->_checkUpdateAccountFields($params))
                    return;

                $userChanged = false;
                // update user FirstName if needed
                if (isset($params['first_name'])) {
                    $customer->setFirstname($params['first_name']);
                    $userChanged = true;
                }
                // update user LasttName if needed
                if (isset($params['last_name'])) {
                    $customer->setLastname($params['last_name']);
                    $userChanged = true;
                }
                // update user email if needed
                if (isset($params['email'])) {
                    $customer->setEmail($params['email']);
                    $userChanged = true;
                }

                // update customer if any field is changed - less SQL resources in use
                if ($userChanged) {
                    try {
                        $customer->save();
                    } catch (Exception $e) {
                        $this->_getHelper()->logException($e, 'Customer update');
                        $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()));
                        return;
                    }
                }

                // update or add address if address is set in json
                if (isset($params['address'])) {
                    if (isset($params['address']['id']) && is_numeric($params['address']['id'])) {
                        $address = Mage::getModel('customer/address')->load($params['address']['id']);
                        if ($address->getId()) {
                            $address->setId($params['address']['id']);
                        } else {
                            // if address ID is invalidor missing, we assume user is adding new default address
                            $address->setIsDefaultBilling('1')
                                    ->setIsDefaultShipping('1');
                        }
                    } else {
                        // if address ID is invalidor missing, we assume user is adding new default address
                        $address = Mage::getModel("customer/address");
                        $address->setIsDefaultBilling('1')
                                ->setIsDefaultShipping('1');
                    }
                    // fix for diffrent street line settings
                    $params = $this->_getHelper()->fixStreetLines($params);

                    $address->setCustomerId($customerId)
                            ->setFirstname($params['address']['first_name'])
                            ->setLastname($params['address']['last_name'])
                            ->setCountryId($params['address']['country_id'])
                            ->setCity($params['address']['city'])
                            ->setTelephone($params['address']['telephone'])
                            ->setStreet(
                                    array(
                                        '0' => $params['address']['street'],
                                        '1' => $params['address']['house_number'],
                                        '2' => $params['address']['addition']
                            ))
                            ->setSaveInAddressBook('1');
                    if (isset($params['address']['postal_code']))
                        $address->setPostcode($params['address']['postal_code']);
                    if (isset($params['address']['state']))
                        $address->setRegion($params['address']['state']);
                    if (isset($params['address']['company']))
                        $address->setCompany($params['address']['company']);

                    try {
                        $address->save();
                    } catch (Exception $e) {
                        $this->_getHelper()->logException($e, 'Address add / update');
                        $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => $e->getMessage()));
                        return;
                    }
                } // endif address is set
            } else {
                $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "Session expired"), "401 Unauthorized");
                return;
            }
        } else {
            $this->_getHelper()->_JSONencodeAndRespond(array("title" => "Error", "content" => "Session expired"), "401 Unauthorized");
            return;
        }

        $data = $this->_getHelper()->getCustomerData($customer);
        if (isset($params['address'])) {
            $addressArray = $this->_getHelper()->getAddressData($address);
            $data['address'] = $addressArray;
        }
        $this->_getHelper()->_JSONencodeAndRespond($data, "200 OK");
        return;
    }

    /**
     * Logout action
     */
    public function logoutAction() {
        $this->_getHelper()->logout();
        return;
    }

    /**
     * Logs the user in. Gets the parameters "email" and "password" from POST
     *
     * @author Tim Wachter
     *
     */
    public function loginAction() {
        $requestObject = Mage::app()->getRequest();
        $this->_getHelper()->login($requestObject);
        return;
    }

}
