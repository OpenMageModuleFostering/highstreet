<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
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
            $page = Mage::getModel('cms/page');
            $page->setStoreId(Mage::app()->getStore()->getId());

            $response = array();
            if ($pageIds !== null) {
                foreach ($pageIds as $key => $value) {
                    try {
                        $page->load($value, 'identifier');
                        $response[$key] = array("title" => $page->getData('title'), "content" => $page->getData('content'));
                    } catch (Exception $e) {
                        $response[$key] = array("title" => "Page not found", "content" => "");
                    }
                }
            } else if ($contentKeys !== null) {
                foreach ($contentKeys as $key => $value) {
                    try {
                        $page->load($value, 'identifier');
                        $response[$key] = array("title" => $page->getData('title'), "contentKey" => $value, "content" => $page->getData('content'));
                    } catch (Exception $e) {
                        $response[$key] = array("title" => "Page not found", "content" => "");
                    }
                }
            }

            $this->_JSONencodeAndRespond($response);
        }
    }

    public function pingAction() {
        $this->_JSONencodeAndRespond(array("OK"));
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
        $categoryId = Mage::app()->getRequest()->getParam('id');

        $categories = null;
        $categoryModel = Mage::getModel('highstreet_hsapi/categories');

        if ($categoryId === 'tree') {
            $categories = $categoryModel->getCategoryTree();
        } else if(!$categoryId || $categoryId !== '') {
            $categories = $categoryModel->getCategories($categoryId);
        }
        
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

    public function checkoutAction() {
        $requestObject = Mage::app()->getRequest();
        $data = json_decode($requestObject->getParam('products'), true);

        $checkoutModel = Mage::getModel('highstreet_hsapi/checkout');
        $checkoutModel->fillCartWithProductsAndQuantities($data["checkout"]);

        $locale = Mage::app()->getLocale()->getLocaleCode();
        
        $country = $requestObject->getParam('country');
        $country = preg_replace("/[^a-zA-Z]/", "", $country);
        $country = strtoupper($country);

        if ($country === "") { // No country given in URL, fallback on store country from locale
            $locale = Mage::app()->getLocale()->getLocaleCode();
            $country = strtoupper(substr($locale, strpos($locale, "_")+1));
        }

        Mage::getSingleton('core/session')->setHSCheckoutCountry($country);

        $urlOptions = array();
        if (!empty($_SERVER['HTTPS'])) { // Server is not HTTPS
            $urlOptions['_secure'] = TRUE;
        }
        
        $config = Mage::helper('highstreet_hsapi/config');
        $checkoutUrl = $config->checkoutUrl();
        
        Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl($checkoutUrl, $urlOptions));
    }


    public function cartAction() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->postCart();
        }
        else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $this->getCart();
        }

    }


    public function postCart() {
        $requestObject = Mage::app()->getRequest();
        $data = json_decode($requestObject->getParam('products'), true);

        $checkoutModel = Mage::getModel('highstreet_hsapi/checkout');
        $cart = $checkoutModel->getQuoteWithProductsAndQuantities($data["checkout"]);

        $this->_JSONencodeAndRespond($cart);

    }

    public function getCart() {
        $requestObject = Mage::app()->getRequest();
        $quote_id = $requestObject->getParam('quote_id');
        if(!$quote_id) {
            $this->_respondWith404();
            return false;
        }

        $checkoutModel = Mage::getModel('highstreet_hsapi/checkout');
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
        
        $id = $requestObject->getParam('id');
        $attributes = $requestObject->getParam('attributes');
        $child_product_attributes = $requestObject->getParam('child_product_attributes');

        $response = $model->getSingleProduct($id, $attributes,$child_product_attributes);

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
        
        $products = $model->getProductsForResponse($requestObject->getParam('attributes'), 
                                                    $requestObject->getParam('child_product_attributes'),
                                                    $requestObject->getParam('order'), 
                                                    $requestObject->getParam('range'), 
                                                    $requestObject->getParam('filter'),
                                                    $requestObject->getParam('search'),
                                                    $requestObject->getParam('id'));

        if ($products == null) {
            $this->_respondWith404();
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
        
        $products = $model->getBatchProducts($requestObject->getParam('ids'),
                                             $requestObject->getParam('attributes'),
                                             $requestObject->getParam('child_product_attributes'));

        if ($products == null) {
            $this->_respondWith404();
            return false;
        }

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
                                                       $requestObject->getParam('attributes'), 
                                                       $requestObject->getParam('child_product_attributes'),
                                                       $requestObject->getParam('range'));

        if ($response == null) {
            $this->_respondWith404();
            return false;
        }

        $this->_JSONencodeAndRespond($response);
    }

    /**
     * Media
     */
    public function imagesAction() {
        $requestObject = $this->getRequest();

        $imageUrl = Mage::getModel('highstreet_hsapi/images')->getImage(urldecode($requestObject->getParam('src')), $requestObject->getParam('size'));
        
        if ($imageUrl === null || !file_exists($imageUrl)) {
            $this->_respondWith404();
            return false;
        }
        
        $this->getResponse()->setHeader('Content-Type', $this->_getHelper()->imageHeaderStringForImage($imageUrl), true);
        $this->getResponse()->setBody(file_get_contents($imageUrl));
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

        if(is_string($params))
        {
            //get Single Attribute
            $attributes = $attributesModel->getAttribute($params);
            $responseBody = $attributes;
        }
        else{
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
    
    /**
     * Header and http functions
     */
    private function _respondWith404()
    {
        $this->getResponse()->setHeader('HTTP/1.1','404 Not Found');
        $this->getResponse()->sendHeaders();
        return;
    }

    /**
     * Sets the proper headers 
     */
    private function _setHeader()
    {
        Mage::getSingleton('core/session')->setLastStoreCode(Mage::app()->getStore()->getCode());
        header_remove('Pragma'); // removes 'no-cache' header
        $this->getResponse()->setHeader('Content-Type','application/json', true);
    }

    /**
     * Sets headers and body with proper JSON encoding
     */
    private function _JSONencodeAndRespond($data, $numeric_check = TRUE) {
        //set response body
        $this->_setHeader();
        if ($numeric_check === FALSE) {
            $this->getResponse()->setBody(json_encode($data));
        } else {
            $this->getResponse()->setBody(json_encode($data, JSON_NUMERIC_CHECK));
        }
    }

}