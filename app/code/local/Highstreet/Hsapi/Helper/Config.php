<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Helper_Config extends Mage_Core_Helper_Abstract
{
    const HAS_COUPON_CODES_ENABLES = "has_coupon_codes_enabled";
    const FILTERS_CATEGORIES = "filters_categories";
    const ALWAYS_ADD_SIMPLE_PRODUCTS_TO_CART = "always_add_simple_products_to_cart";
    const CHECKOUT_URL = "checkout_url";
    const CHECKOUT_URL_FALLBACK = "checkout/onepage";
    const HAS_NEW_CHECKOUT = "new_checkout";
    const SHIPPING_METHODS_TEMPLATE_PATH = "shipping_methods_template_path";
    const SHIPPING_METHODS_TEMPLATE_PATH_FALLBACK = "highstreet/checkout/checkout/shipping_method/available.phtml";


    public function hasCouponCodeEnabled() {
        $configurations = $this->_getConfigurations();
        return $configurations[self::HAS_COUPON_CODES_ENABLES];
    }

    public function filtersCategories() {
        $configurations = $this->_getConfigurations();
        return $configurations[self::FILTERS_CATEGORIES];
    }

    public function alwaysAddSimpleProductsToCart() {
        $configurations = $this->_getConfigurations();
        $addSimpleProducts = $configurations[self::ALWAYS_ADD_SIMPLE_PRODUCTS_TO_CART];
        return ($addSimpleProducts === NULL) ? false : $addSimpleProducts;
    }

    public function checkoutUrl() {
        $configurations = $this->_getConfigurations();
        $checkoutUrl = $configurations[self::CHECKOUT_URL];
        return ($checkoutUrl === NULL) ? self::CHECKOUT_URL_FALLBACK : $checkoutUrl;
    }

    public function hasNewCheckout() {
        $configurations = $this->_getConfigurations();
        $hasNewCheckout = $configurations[self::HAS_NEW_CHECKOUT];
        return ($hasNewCheckout === NULL) ? false : $hasNewCheckout;
    }

    public function shippingMethodsTemplatePath() {
        $configurations = $this->_getConfigurations();
        $shippingMethodsTemplatePath = $configurations[self::SHIPPING_METHODS_TEMPLATE_PATH];
        return ($shippingMethodsTemplatePath === NULL) ? self::SHIPPING_METHODS_TEMPLATE_PATH_FALLBACK : $shippingMethodsTemplatePath;
    }

    /**
     * Loads the configuration JSON and returns an array of 
     *
     * @return array Array of the settings
     */
    private function _getConfigurations() {
    	$file = file_get_contents(Mage::getBaseDir('code') . "/local/Highstreet/Hsapi/etc/Configuration.json");
    	return json_decode($file, true);
    }
}



