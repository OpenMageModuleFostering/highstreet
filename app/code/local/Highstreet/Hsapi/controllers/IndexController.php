<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2015 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_IndexController extends Mage_Core_Controller_Front_Action
{

    /**
     * @return Highstreet_Hsapi_Helper_Data
     */
    private function _getHelper()
    {
        return Mage::helper('highstreet_hsapi/data');
    }

    /** Disable actions */
    public function indexAction() {
        return false;
    }

    /**
     * Get CMS page call
     *
     * Examples:
     * {url}?page_id=12
     * {url}?content_key=home
     * {url}?page_ids[title-1]=10&page_ids[title-2]=11&page_ids[title-3]=12
     * {url}?content_keys[title-1]=home&content_keys[title-2]=home-2&content_keys[title-3]=home-3
     *
     */
    public function pageAction() {

        $pageId = Mage::app()->getRequest()->getParam('page_id');
        $contentKey = Mage::app()->getRequest()->getParam('content_key');

        $pageIds = Mage::app()->getRequest()->getParam('page_ids');
        $contentKeys = Mage::app()->getRequest()->getParam('content_keys');

        if (($pageId === null && $contentKey === null) && 
            ($pageIds === null && $contentKeys === null)) {
            $this->_JSONencodeAndRespond(array("title" => "Error", "content" => "No arguments found"));
        } else if ($pageId !== null || $contentKey !== null) {
            $page = Mage::getModel('cms/page');
            $page->setStoreId(Mage::app()->getStore()->getId());
            if ($pageId !== null) {
                $page->load($pageId);
            } else if ($contentKey !== null) {
                $page->load($contentKey, 'identifier');
            }
            $this->_JSONencodeAndRespond(array("title" => $page->getData('title'), "content" => $page->getData('content')));
        } else if ($pageIds !== null || $contentKeys !== null) {
            $response = array();
            if ($pageIds !== null) {
                foreach ($pageIds as $key => $value) {
                    try {
                        $page = Mage::getModel('cms/page');
                        $page->setStoreId(Mage::app()->getStore()->getId());
                        $page->load($value, 'identifier');
                        $response[$key] = array("title" => $page->getData('title'), "content" => $page->getData('content'));
                        unset($page);
                    } catch (Exception $e) {
                        $response[$key] = array("title" => "Page not found", "content" => "");
                    }
                }
            } else if ($contentKeys !== null) {
                foreach ($contentKeys as $key => $value) {
                    try {
                        $page = Mage::getModel('cms/page');
                        $page->setStoreId(Mage::app()->getStore()->getId());
                        $page->load($value, 'identifier');
                        $response[$key] = array("title" => $page->getData('title'), "contentKey" => $value, "content" => $page->getData('content'));
                        unset($page);
                    } catch (Exception $e) {
                        $response[$key] = array("title" => "Page not found", "content" => "");
                    }
                }
            }

            $this->_JSONencodeAndRespond($response);
        }
    }

    public function pingAction() {
        $configApi = Mage::helper('highstreet_hsapi/config_api');
        $this->_JSONencodeAndRespond("OK");
    }

    public function infoAction() {
        $encryptionHelper = Mage::helper('highstreet_hsapi/encryption');
        if (!$encryptionHelper->APISignatureStringIsValid()) {
            $this->_respondWith401();
            return;
        }

        $configApi = Mage::helper('highstreet_hsapi/config_api');
        $this->_JSONencodeAndRespond(array("status" => "OK", 
                                            "identifer" => $configApi->storeIdentifier(), 
                                            "version" => (string)Mage::getConfig()->getNode()->modules->Highstreet_Hsapi->version,
                                            "magento_version" => (string)Mage::getVersion(),
                                            "environment" => $configApi->environment(),
                                            "storefront" => Mage::app()->getStore()->getCode(),
                                            "street_line_number" =>  Mage::getStoreConfig('customer/address/street_lines'),
                                            "attributes_sort_order_setting_raw" => $configApi->attributesSortOrderRaw()));
    }

    /**
     * Categories
     */
    public function categoryAction()
    {
        return $this->categoriesAction();
    }

    /**
     * Categories action
     */
    public function categoriesAction()
    {
        //Get categoryId and check
        $request = Mage::app()->getRequest();
        $categoryId = $request->getParam('id');
        $maxDepth = $request->getParam('max_depth');
        if ($maxDepth === null) {
            $maxDepth = -1;
        }

        $categories = null;
        $categoryModel = Mage::getModel('highstreet_hsapi/categories');

        $categories = $categoryModel->getCategory($categoryId, $maxDepth);
        
        if($categories == null) {
            $this->_respondWith404();
            return false;
        }

        $this->_JSONencodeAndRespond($categories);
    }

    /**
     * Category Products action. (A proxy for Products)
     */
    public function categoryProductsAction()
    {
        return $this->productsAction();
    }

    public function checkout_v2Action() {
        $requestObject = Mage::app()->getRequest();
        $data = json_decode($requestObject->getParam('items'), true);

        $trackingId = $requestObject->getParam('tid');
        Mage::getSingleton('checkout/session')->setHsTid($trackingId);

        $country = $requestObject->getParam('country');
        return $this->_postCheckout($data,$country);
        
    }

    private function _postCheckout($items,$country = null) {
        $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
        
        $checkoutModel->fillCartWithProductsAndQuantities($items);

        $configApi = Mage::helper('highstreet_hsapi/config_api');
        if ($configApi->standaloneCheckoutActive()) {
            $this->_JSONencodeAndRespond($checkoutModel->getStartData());
        } else {
            $url = $configApi->checkoutRedirectUrl();

            Mage::app()->getFrontController()->getResponse()->setRedirect($url);
        }
    }


    public function cartAction() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->postCart();
        }
        else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $this->getCart();
        }

    }

    public function cart_v2Action() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->postCart_v2();
        }
        else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $this->getCart_v2();
        }

    }

    public function postCart_v2() {
        $requestObject = Mage::app()->getRequest();
        $data = json_decode($requestObject->getParam('items'), true);
        
        $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
        $cart = $checkoutModel->getQuoteWithProductsAndQuantities($data);

        $this->_JSONencodeAndRespond($cart);

    }
    
    public function getCart_v2() {
        $requestObject = Mage::app()->getRequest();
        $quote_id = $requestObject->getParam('quote_id');
        if(!$quote_id) {
            $this->_respondWith404();
            return false;
        }

        $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
        $cart = $checkoutModel->getQuoteWithProductsAndQuantities(null,$quote_id);

        $this->_JSONencodeAndRespond($cart);


    }

    /**
     * Get single product
     */
    public function productAction()
    {
        $requestObject = Mage::app()->getRequest();
        $model = Mage::getModel('highstreet_hsapi/products');

        $productObject = Mage::getModel('catalog/product')->load($requestObject->getParam('id'));

        if (!$productObject->getId()) {
            $this->_respondWith404();
            return false;
        }

        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && $_SERVER["HTTP_IF_MODIFIED_SINCE"] !== NULL) {
            if (!$model->productHasBeenModifiedSince($productObject, $_SERVER["HTTP_IF_MODIFIED_SINCE"])) {
                $this->_respondWith304();
                return false;
            }
        }

        $response = $model->getSingleProduct($productObject, 
                                            $requestObject->getParam('additional_attributes'), 
                                            $requestObject->getParam('include_configuration_details'),
                                            $requestObject->getParam('include_media_gallery'));


        if ($response == null) {
            $this->_respondWith404();
            return false;
        }

        $this->_JSONencodeAndRespond($response);
    }

    /*
     * Returns stock information for a given product
     */
    public function stockAction() 
    {
        $requestObject = Mage::app()->getRequest();
        $model = Mage::getModel('highstreet_hsapi/products');

        $stockinfo = $model->getStockInfo($requestObject->getParam('id'));
        
        if ($stockinfo == null) {
            $this->_respondWith404();
            return false;
        }

        $response = array();
        $response["stock"] = $stockinfo;


        $this->_JSONencodeAndRespond($response);
    }

    /**
     * Get multiple products based on a category
     */
    public function productsAction()
    {
        $requestObject = Mage::app()->getRequest();
        $model = Mage::getModel('highstreet_hsapi/products');
        
        $products = $model->getProductsForResponse($requestObject->getParam('additional_attributes'), 
                                                    $requestObject->getParam('order'), 
                                                    $requestObject->getParam('range'), 
                                                    $requestObject->getParam('filter'),
                                                    $requestObject->getParam('search'),
                                                    $requestObject->getParam('id'),
                                                    $requestObject->getParam('hide_attributes'),
                                                    $requestObject->getParam('hide_filters'), 
                                                    $requestObject->getParam('include_configuration_details'),
                                                    $requestObject->getParam('include_media_gallery'));

        if ($products == $model::PRODUCTS_ERROR_NOT_FOUND) {
            $this->_respondWith404();
            return false;
        } else if ($products == $model::PRODUCTS_ERROR_OUT_OF_RANGE) {
            $this->_respondWith403();
            return false;
        }

        $this->_JSONencodeAndRespond($products);
    }

    /**
     * Get a batch of products based on product ids
     */

    public function batchProductsAction() 
    {
        $requestObject = Mage::app()->getRequest();
        $model = Mage::getModel('highstreet_hsapi/products');

        $productObjects = array();
        if ($requestObject->getParam('ids') != "") {
            $idsArray = explode(',', $requestObject->getParam('ids'));
            if (count($idsArray) >= Highstreet_Hsapi_Model_Products::RANGE_LIMIT) {
                $this->_JSONencodeAndRespond(array());
                return false;
            }


            foreach ($idsArray as $value) {
                $productObject = Mage::getModel('catalog/product')->load($value);
                if (is_object($productObject) && $productObject->getId() > 0) {
                    $productObjects[] = $productObject;
                }
            }
        }

        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && $_SERVER["HTTP_IF_MODIFIED_SINCE"] !== NULL) {
            $productHasChanged = false;

            foreach ($productObjects as $productObject) {
                if ($model->productHasBeenModifiedSince($productObject, $_SERVER["HTTP_IF_MODIFIED_SINCE"])) {
                    $productHasChanged = true;
                }
            }

            if (!$productHasChanged) {
                $this->_respondWith304();
                return false;
            }
        }
        
        $products = $model->getBatchProducts($productObjects,
                                             $requestObject->getParam('additional_attributes'),
                                             $requestObject->getParam('include_configuration_details'),
                                             $requestObject->getParam('include_media_gallery'));

        $this->_JSONencodeAndRespond($products);
    }

    /**
     * Returns related / cross-sell / up-sell products
     */
    public function relatedProductsAction()
    {
        $requestObject = Mage::app()->getRequest();
        $productsModel = Mage::getModel('highstreet_hsapi/products');

        $response = $productsModel->getRelatedProducts($requestObject->getParam('type'), 
                                                       $requestObject->getParam('id'), 
                                                       $requestObject->getParam('additional_attributes'), 
                                                       $requestObject->getParam('range'),
                                                       $requestObject->getParam('hide_attributes'),
                                                       $requestObject->getParam('include_configuration_details'),
                                                       $requestObject->getParam('include_media_gallery'));

        if ($response == $productsModel::PRODUCTS_ERROR_OUT_OF_RANGE) {
            $this->_respondWith403();
            return false;
        }

        $this->_JSONencodeAndRespond($response);
    }

    /**
     * attributes
     */
    public function attributesAction()
    {
        $helper = $this->_getHelper();
        $attributesModel = Mage::getModel('highstreet_hsapi/attributes');

        /** @var  $params */
        $params = $helper->extractRequestParam(Mage::app()->getRequest()->getParams());

        if (is_string($params)) {
            //get Single Attribute
            $attributes = $attributesModel->getAttribute($params);
            $responseBody = $attributes;
        } else {
            //Get all attributes
            $attributes = $attributesModel->getAttributes();
            $responseBody = $attributes;
        }

        if($attributes == null){
            $this->_respondWith404();
            return false;
        }

        $this->_JSONencodeAndRespond($responseBody);
    }

    /**
     * Returns search suggestions
     * http://docs.test.touchwonders.com/highstreet/#api-Search-Search_suggestions
     * 
     * @author Tim Wachter
     * 
     */
    public function searchsuggestionsAction() {
        $requestObject = Mage::app()->getRequest();
        $searchModel = Mage::getModel('highstreet_hsapi/searchSuggestions');

        $response = $searchModel->getSearchSuggestions($requestObject->getParam('limit'), $requestObject->getParam('search'), $requestObject->getParam('category'));

        if($response == null){
            $this->_respondWith404();
            return false;
        }

        $this->_JSONencodeAndRespond($response, FALSE);
    }

    public function getOrderAction () {
        $encryptionHelper = Mage::helper('highstreet_hsapi/encryption');
        if (!$encryptionHelper->APISignatureStringIsValid()) {
            $this->_respondWith401();
            return;
        }

        $requestObject = Mage::app()->getRequest();

        $quoteIds = array();
        if ($requestObject->getParam('quote_id') != null) {
            $quoteIds[] = $requestObject->getParam('quote_id');
        } 

        if ($requestObject->getParam('quote_ids') != null) {
            $quoteIds = array_merge($quoteIds, explode(',', $requestObject->getParam('quote_ids')));
        }

        $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');

        $response = array();
        foreach ($quoteIds as $quoteId) {
            $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId); 
            $order = Mage::getModel('sales/order')->loadByIncrementId($quote->getData('reserved_order_id'));

            $response[] = $checkoutModel->getOrderInformationFromOrderObject($order, $quoteId);
        }

        $this->_JSONencodeAndRespond($response);
    }

    /**
     * Header and http functions
     */
    protected function _respondWith304()
    {
        $this->getResponse()->setHeader('HTTP/1.1','304 Not Modified');
        $this->getResponse()->sendHeaders();
        return;
    }
    
    protected function _respondWith403()
    {
        $this->getResponse()->setHeader('HTTP/1.1','403 Forbidden');
        $this->getResponse()->sendHeaders();
        return;
    }

    protected function _respondWith404()
    {
        $this->getResponse()->setHeader('HTTP/1.1','404 Not Found');
        $this->getResponse()->sendHeaders();
        return;
    }

    protected function _respondWith401()
    {
        $this->getResponse()->setHeader('HTTP/1.1','401 Unauthorized');
        $this->getResponse()->sendHeaders();
        return;
    }

    /**
     * Sets the proper headers 
     */
    protected function _setHeader()
    {
        Mage::getSingleton('core/session')->setLastStoreCode(Mage::app()->getStore()->getCode());
        header_remove('Pragma'); // removes 'no-cache' header
        $this->getResponse()->setHeader('Content-Type','application/json', true);
    }

    /**
     * Sets headers and body with proper JSON encoding
     */
    protected function _JSONencodeAndRespond($data, $numeric_check = TRUE) {
        //set response body
        $this->_setHeader();
        if ($numeric_check === FALSE || version_compare(PHP_VERSION, '5.3.3', '<')) {
            $this->getResponse()->setBody(json_encode($data));
        } else {
            $this->getResponse()->setBody(json_encode($data, JSON_NUMERIC_CHECK));
        }
    }

}