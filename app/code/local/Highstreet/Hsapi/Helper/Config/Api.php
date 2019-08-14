<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2015 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Helper_Config_Api extends Mage_Core_Helper_Abstract {
	const MIDDLEWARE_URL_SCHEME = "http://";
    const MIDDLEWARE_URL_ENVIRONMENT_STAGING = "api-dev";
    const MIDDLEWARE_URL_ENVIRONMENT_PRODUCTION = "api";
    const MIDDLEWARE_URL_HOST_PATH = "highstreetapp.com/hs-api/1.4";
    const CHECKOUT_URL_FALLBACK = "checkout/cart";

    public function alwaysAddSimpleProductsToCart() {
        $alwaysAddSimpleProductsToCart = Mage::getStoreConfig('highstreet_hsapi/api/always_add_simple_products');
        return ($alwaysAddSimpleProductsToCart === NULL) ? false : (bool)$alwaysAddSimpleProductsToCart;
    }

    public function shippingInCartDisabled() {
        $shippingInCartDisabled = Mage::getStoreConfig('highstreet_hsapi/api/shipping_in_cart');
        return ($shippingInCartDisabled === NULL) ? false : (bool)$shippingInCartDisabled;
    }

    public function storeIdentifier() {
        $store_id = Mage::getStoreConfig('highstreet_hsapi/api/store_id');
        return ($store_id === NULL) ? "" : $store_id;
    }

    public function environment() {
        $environment = Mage::getStoreConfig('highstreet_hsapi/api/environment');
        return ($environment === NULL) ? "staging" : $environment;
    }

    public function nativeSmartbannerActive() {
        return (bool) Mage::getStoreConfig('highstreet_hsapi/api/smartbanner_native_active');
    }

    public function nativeSmartbannerAppId() {
        $app_id = Mage::getStoreConfig('highstreet_hsapi/api/smartbanner_native_app_id');
        return ($app_id === NULL) ? "" : $app_id;
    }

    public function nativeSmartbannerAppUrl() {
        $app_url = Mage::getStoreConfig('highstreet_hsapi/api/smartbanner_native_app_url');
        return ($app_url === NULL) ? "" : $app_url;
    }

    public function nativeSmartbannerAppName() {
        $app_name = Mage::getStoreConfig('highstreet_hsapi/api/smartbanner_native_app_name');
        return ($app_name === NULL) ? "" : $app_name;
    }

    public function standaloneCheckoutActive() {
        $saco_active = Mage::getStoreConfig('highstreet_hsapi/api/checkout_saco_active');
        return ($saco_active === NULL) ? true : (bool)$saco_active;
    }

    public function checkoutRedirectUrl() {
        $checkout_redirect_url = Mage::getStoreConfig('highstreet_hsapi/api/checkout_redirect_url');
        return ($checkout_redirect_url === NULL) ? self::CHECKOUT_URL_FALLBACK : $checkout_redirect_url;
    }

    public function middlewareUrl() {
        if ($this->storeIdentifier() == "") {
            return NULL;
        }

        $url = self::MIDDLEWARE_URL_SCHEME . $this->storeIdentifier();

        if ($this->environment() === 'staging') {
            $url .= '.' . self::MIDDLEWARE_URL_ENVIRONMENT_STAGING;
        } else {
            $url .= '.' . self::MIDDLEWARE_URL_ENVIRONMENT_PRODUCTION;
        }

        $url .= '.' . self::MIDDLEWARE_URL_HOST_PATH;


        return $url;
    }

    public function shouldShowNativeSmartbanner() {
        return ($this->nativeSmartbannerActive() && $this->nativeSmartbannerAppId() != "");
    }

    public function attributesSortOrderRaw() {
        return Mage::getStoreConfig('highstreet_hsapi/api/attribute_sort_order');
    }

    public function attributesSortOrder() {
        // Can return NULL or a string
        $jsonString = $this->attributesSortOrderRaw();

        // Will return NULL if given NULL or a malformed string
        $data = json_decode($jsonString, true);
        
        return ($data === NULL) ? array() : $data;
    }
}