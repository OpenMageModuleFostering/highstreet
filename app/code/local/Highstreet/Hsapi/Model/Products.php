<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2015 Touchwonders b.v. (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_Products extends Mage_Core_Model_Abstract
{
    const MEDIA_PATH = '/media/';
    const PRODUCTS_MEDIA_PATH = '/media/catalog/product';
    const NO_IMAGE_PATH = 'no_selection';
    const RANGE_FALLBACK_RANGE = 20;
    const RANGE_LIMIT = 100;
    const SPECIAL_PRICE_FROM_DATE_FALLBACK = "1970-01-01 00:00:00";

    const PRODUCTS_ERROR_NOT_FOUND = 404;
    const PRODUCTS_ERROR_OUT_OF_RANGE = 403;

    protected $_attributesModel = null;

    public function __construct() {
        $this->_attributesModel = Mage::getModel('highstreet_hsapi/attributes');
    }

    /**
     * Gets a single product for a given productId and attributes
     * 
     * @param object Product object for the product to be gotten
     * @param string Additional Attributes, string of attributes straight from the URL
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products)
     * @param bool include_media_gallery, weather to include the media gallery in the product object
     * @return array Product
     */
    public function getSingleProduct($productObject = false, $additional_attributes, $include_configuration_details, $include_media_gallery)
    {
        if (!$productObject) {
            return null;
        } 
        
        return $this->_getProductAttributes($productObject, $additional_attributes, $include_configuration_details, $include_media_gallery);
    }

    public function productHasBeenModifiedSince($productObject = false, $since) {
        if (!is_numeric($since)) {
            if (($since = strtotime($since)) === false) {
                return true; // String to time failed to convert, return the product as if it was modified
            }
        }

        if (!$productObject) {
            return false;
        }

        if (strtotime($productObject->getUpdatedAt()) >= $since) {
            return true;
        }

        if ($productObject->getTypeId() == 'configurable') {
            $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($productObject);
            $simple_collection = $conf->getUsedProductCollection()
                                      ->addAttributeToSelect(array('updated_at'));

            foreach ($simple_collection as $product) {
                if (strtotime($product->getUpdatedAt()) >= $since) {
                    return true;
                }
            }
        } elseif ($productObject->getTypeId() == 'bundle') {
            $bundleProduct = Mage::getModel('bundle/product_type')->setProduct($productObject);
            $bundleProducts = $bundleProduct->getSelectionsCollection($bundleProduct->getOptionsIds());

            foreach ($bundleProducts as $product) {
                if (strtotime($product->getUpdatedAt()) >= $since) {
                    return true;
                }
            }
        } 

        return false;
    }

    /**
     * Gets products with attributes for given order, range, filters, search and categoryId
     * 
     * @param string Additional Attributes, string of attributes straight from the URL
     * @param string Order, order for the products
     * @param string Range of products. Must formatted like "0,10" where 0 is the offset and 10 is the count
     * @param string Search string for filtering on keywords
     * @param integer CategoryId, category id of the category which will be used to filter
     * @param boolean Hide attributes, only returns product ID's (vastly improving the speed of the API)
     * @param boolean Hide filters, hides filters if set to true
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products)
     * @param bool include_media_gallery, weather to include the media gallery in the product object
     * @return array Product
     */
    public function getProductsForResponse($additional_attributes, $order, $range, $filters, $search, $categoryId, $hideAttributes, $hideFilters, $include_configuration_details, $include_media_gallery)
    {
        $searching = !empty($search);

        // get order
        if (!empty($order)) {
            $order = explode(',', $order);
        }


        // apply search
        if ($searching) {

                ////////
                $_GET['q'] = $search; //this is the only to pass the search query
                $query = Mage::helper('catalogsearch')->getQuery();
                $query->setStoreId(Mage::app()->getStore()->getId());

                //Code here inspired from ResultController.php
                if ($query->getQueryText() != '') {
                    if (Mage::helper('catalogsearch')->isMinQueryLength()) {
                        $query->setId(0)
                        ->setIsActive(1)
                        ->setIsProcessed(1);
                    }
                    else {
                        if ($query->getId()) {
                            $query->setPopularity($query->getPopularity()+1);
                        }
                        else {
                            $query->setPopularity(1);
                        }
                    }
                    if (!Mage::helper('catalogsearch')->isMinQueryLength()) {
                        $query->prepare();
                    }
                }
                $query->save();


                $catalogSearchModelCollection = Mage::getResourceModel('catalogsearch/fulltext_collection');

                $catalogSearchModelCollection->addSearchFilter($search);

                $collection = $catalogSearchModelCollection;
        } else {
            // initialize
            $collection = Mage::getModel('catalog/product')->getCollection();
        }


        $collection->addStoreFilter()
                   ->addMinimalPrice()
                   ->addFinalPrice()
                   ->addTaxPercents()
                   ->distinct(true);
        Mage::getSingleton('catalog/product_status')->addVisibleFilterToCollection($collection);
        Mage::getSingleton('catalog/product_visibility')->addVisibleInCatalogFilterToCollection($collection);

        
        $categoryNotSet = false;
        
        if (empty($categoryId)) {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
            $categoryNotSet = true;
        }
        
        $category = Mage::getModel('catalog/category')->load($categoryId);
        if ($category->getId() === NULL) {
            return self::PRODUCTS_ERROR_NOT_FOUND;
        }

        // apply search
        if ($categoryId && !$categoryNotSet) {
           $collection = $this->_addCategoryFilterToProductCollection($collection, $category);
        } 

        if (!empty($range)) {
            $range = explode(',', $range);
        }

        if (!is_array($range)) {
            $range = array(0, self::RANGE_FALLBACK_RANGE);
        }

        if ($range[1] > self::RANGE_LIMIT) {
            return self::PRODUCTS_ERROR_OUT_OF_RANGE;
        }

        $collection->getSelect()->limit($range[1], $range[0]);

        $attributesArray = array();
        // get attributes
        if (!empty($additional_attributes)) {
            $attributesArray = $this->_translateAdditionalAttributes(explode(',', $additional_attributes));
        }

        $attributesArray = array_merge($attributesArray, $this->_getCoreAttributes());

        // apply attributes
        if (!$hideAttributes) {
            $collection->addAttributeToSelect($attributesArray);
        } else {
            $collection->addAttributeToSelect('entity_id');
        }
        
        //apply filters
        if(!empty($filters)) {
            $collection = $this->_filterProductsCollection($collection, $filters);
        }

        // Add 'out of stock' filter, if preffered 
        if (!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock')) {
            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection);
        }
        
        // Apply type filter, we only want Simple and Configurable and Bundle products in our API
        $collection->addAttributeToFilter('type_id', array('simple', 'configurable', 'bundle'));
        // apply sort order
        if($searching) {
            $collection->setOrder('relevance', 'desc');
        } else {
            $collection = $this->_addSortingToProductCollection($collection, $order, $category);
        }

        /**
         * Format result array
         */
        $products = array('products' => array());
        
        // If range requests no products to be returned, return no products. The limit() doesn't take 0 for an answer
        if ($range[1] > 0) {
            if (!$hideAttributes) {
                foreach($collection as $product) {
                    array_push($products['products'], $this->_getProductAttributes($product, $additional_attributes, $include_configuration_details, $include_media_gallery));
                }
            } else {
                // getAllIds resets product order
                foreach($collection as $product) {
                    array_push($products['products'], $product->getEntityId());
                }
            }
        }

        $products['filters'] = array();
        if (!$hideFilters) {
            $products['filters'] = $this->getFilters($categoryId);
        }

        $products['product_count'] = $this->_getCountForProductCollection($collection, $categoryId, $categoryNotSet);

        $rangeLength = $range[1];
        if ($rangeLength > count($products["products"])) {
            $rangeLength = count($products["products"]);
        }

        $products['range'] = array("location" => $range[0], "length" => $rangeLength);

        return $products;
    }


    /**
     * 
     * Gets products for a set of product id's
     * 
     * @param array productIds, product id's to filter on
     * @param string additional_attributes, comma seperated string of attributes
     * @param string range, formatted range string
     * @param boolean Hide Attributes, only returns product ID's (vastly improving the speed of the API)
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products)
     * @param bool include_media_gallery, weather to include the media gallery in the product object
     * @return array Array of products
     *
     */

    public function getProductsFilteredByProductIds($productIds = false, $additional_attributes, $range, $hideAttributes, $include_configuration_details, $include_media_gallery) {

        $products = array('products' => array());

        if (!$productIds) {
            $products['product_count'] = 0;
            $products['range'] = array("location" => 0, "length" => 0);
            return $products;
        }


        $collection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('entity_id', array('in' => $productIds)); 

        $attributesArray = array();
        // get attributes
        if (!empty($additional_attributes)) {
            $attributesArray = explode(',', $additional_attributes);
        }

        $attributesArray = array_merge($attributesArray, $this->_getCoreAttributes());

        $collection->addAttributeToSelect($attributesArray);

        if (!empty($range)) {
            $range = explode(',', $range);
        }

        if (!is_array($range)) {
            $range = array(0, self::RANGE_FALLBACK_RANGE);
        }

        if ($range[1] > self::RANGE_LIMIT) {
            return self::PRODUCTS_ERROR_OUT_OF_RANGE;
        }

        $collection->getSelect()->limit($range[1], $range[0]);

        // Add 'out of stock' filter, if preffered 
        if (!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock')) {
            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection);

            // For comments, see :222
            $collectionConfigurable = Mage::getResourceModel('catalog/product_collection')->addAttributeToFilter('type_id', array('eq' => 'configurable'));
            $collectionConfigurable->addAttributeToFilter('entity_id', array('in' => $productIds)); 

            $outOfStockConfis = array();
            foreach ($collectionConfigurable as $_configurableproduct) {
                $product = Mage::getModel('catalog/product')->load($_configurableproduct->getId());
                if (!$product->getData('is_salable')) {
                   $outOfStockConfis[] = $product->getId();
                }
            }
            
            if (count($outOfStockConfis) > 0) {
                $collection->addAttributeToFilter('entity_id', array('nin' => $outOfStockConfis));
            }
        }

        /**
         * Format result array
         */
        if (!$hideAttributes) {
            foreach($collection as $product) {
                array_push($products['products'], $this->_getProductAttributes($product, $additional_attributes, $include_configuration_details, $include_media_gallery));
            }
        } else {
            $products['products'] = $collection->getAllIds();
        }

        $products['product_count'] = $this->_getCountForProductCollection($collection);

        $rangeLength = $range[1];
        if ($rangeLength > count($products["products"])) {
            $rangeLength = count($products["products"]);
        }

        $products['range'] = array("location" => $range[0], "length" => $rangeLength);

        return $products;
    }

    /**
     * Gets a batch of products for a given comma sepperated productIds and attributes
     * 
     * @param array ProductObjects, array of Magento product Objects
     * @param string Additional Attributes, string of attributes, comma sepperated
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products)
     * @param bool include_media_gallery, weather to include the media gallery in the product object
     * @return array Product
     */

    public function getBatchProducts($productObjects, $additional_attributes, $include_configuration_details, $include_media_gallery) {
        $products = array();
        foreach ($productObjects as $productObject) {
            $products[] = $this->_getProductAttributes($productObject, $additional_attributes, $include_configuration_details, $include_media_gallery);
        }

        return $products;
    }


    public function getStockInfo($productId = false) {
        if(!$productId) {
            return;
        }
        $product = Mage::getModel('catalog/product')->load($productId);

        if(!$product->getId())
            return;


        $products = array();
        $response = array();

        if($product->getTypeId() == 'configurable') {
            $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($product);
            $simple_collection = $conf->getUsedProductCollection()
                                      ->addFilterByRequiredOptions()
                                      ->addAttributeToFilter('status', 1); // Only return products that are active

            $products = $simple_collection;
        } else if($product->getTypeId() == 'simple'){
            $products[] = $product;
        } else if($product->getTypeId() == 'bundle'){
            $bundleProduct = Mage::getModel('bundle/product_type')->setProduct($product);
            $products = $bundleProduct->getSelectionsCollection($bundleProduct->getOptionsIds());
        } else {
            return;
        }


        foreach($products as $simpleproduct)
            $response[] = $this->_getStockInformationForProduct($simpleproduct);


        return array_values($response);


    }

    /**
     * Gets related products for a type and product id
     *
     * @param string Type, type of related products, can either be 'cross-sell', 'up-sell' or empty, in which case it will return 'regular' related products
     * @param int productId, id used for base of related products
     * @param string Additonal Attributes, comma seperated string of attributes
     * @param string range, formatted range string
     * @param boolean Hide attributes, only return product ID's (vastly improving the speed of the API)
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products)
     * @param bool include_media_gallery, weather to include the media gallery in the product object
     * @return array Array of product ids
     *
     */

    public function getRelatedProducts($type, $productId = false, $additional_attributes, $range, $hideAttributes, $include_configuration_details, $include_media_gallery) {
        if (!$productId) {
            return;
        }

        if ($type == "cross-sell") {
            $productIds = $this->getCrossSellProductIds($productId);
        } else if ($type == "up-sell") {
            $productIds = $this->getUpSellProductIds($productId);
        } else {
            $productIds = $this->getRelatedProductIds($productId);
        }

        return $this->getProductsFilteredByProductIds($productIds, $additional_attributes, $range, $hideAttributes, $include_configuration_details, $include_media_gallery);

    }


    /**
     * Convenience functions
     */

    /**
     *
     * Get related product id's for a product id
     *
     * @param int productId, id used to filter related products
     * @return array Array of product ids
     *
     */

    public function getRelatedProductIds($productId = false) {
        if (!$productId) {
            return;
        }

        $productModel = Mage::getModel('catalog/product')->load($productId);
        return $productModel->getRelatedProductIds();
    }
    
    /**
     *
     * Get cross sell product id's for a product id
     *
     * @param int productId, id used to filter cross sell products
     * @return array Array of product ids
     * 
     */

    public function getCrossSellProductIds($productId = false) {
        if (!$productId) {
            return;
        }

        $productModel = Mage::getModel('catalog/product')->load($productId);
        return $productModel->getCrossSellProductIds();
    }

    /**
     *
     * Get up sell product id's for a product id
     *
     * @param int productId, id used to filter up sell products
     * @return array Array of product ids
     * 
     */

    public function getUpSellProductIds($productId = false) {
        if (!$productId) {
            return;
        }

        $productModel = Mage::getModel('catalog/product')->load($productId);
        return $productModel->getUpSellProductIds();
    }

    /**
     * Returns filters
     *
     * @param int $categoryId
     * @return array
     *
     */
    public function getFilters($categoryId = false) {
        if (!$categoryId) {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
        }

        $layer = Mage::getModel('catalog/layer');

        $category = Mage::getModel('catalog/category')->load($categoryId);
        $layer->setCurrentCategory($category);
        $controller = Mage::app()->getLayout();
        $attributes = $layer->getFilterableAttributes('price');
        $resultFilters = array();
        foreach ($attributes as $attribute) {
            if ($attribute->getAttributeCode() == 'price') {
                $filterBlockName = 'catalog/layer_filter_price';
            } else {
                $filterBlockName = 'catalog/layer_filter_attribute';
            }

            $result = $controller->createBlock($filterBlockName)->setLayer($layer)->setAttributeModel($attribute)->init();
            $options = array();
            foreach($result->getItems() as $option) {
                
                array_push($options, $this->getFilterOptionValue($option));
            }

            if (count($options) > 1) {
                array_push($resultFilters, 
                    array(
                        'title' => $attribute->getData('store_label'), 
                        'type' => $attribute->getFrontendInput(), 
                        'code' => $attribute->getAttributeCode(), 
                        'options' => $options
                        )
                    );
            }
        }

        return $resultFilters;
    }

    /***********************************/
    /**
     * PRIVATE/protected FUNCTIONS
     */
    /***********************************/

    /**
     * Returns a formatted object for a given option item
     * Made for subclassing
     *
     * @param $optionItem
     * @return array
     *
     */
    protected function getFilterOptionValue($optionItem = null) {
        if (!$optionItem) {
            return;
        }

        $title = str_replace('<span class="price">', "", $optionItem->getLabel());
        $title = str_replace('</span>', "", $title);

        $count = $optionItem->getData('count');

        return array('value' => $optionItem->getValue(), 'title' => $title, 'product_count' => $count);
    }

    /**
     * Gets count for given collection. Function made for easier subclassing
     *
     * @param mixed Collection
     * @param int Category ID, can be needed
     * @param bool Category not set, used to signify if the category was set or not 
     * @return int Product count
     *
     */

    protected function _getCountForProductCollection ($collection = null, $categoryId = -1, $categoryNotSet = false) {
        if ($collection === null) {
            return -1;
        }

        return $collection->getSize();
    }

    /**
     * Adds the category filter to a product collection. Function made for subclassing
     * 
     * @param mixed Collection
     * @param mixed Category object
     * @return mixed Collection
     */

    protected function _addCategoryFilterToProductCollection ($collection = null, $categoryObject = null) {
        if ($collection === null || $categoryObject === null) {
            return $collection;
        }

        $collection->addCategoryFilter($categoryObject);

        return $collection;
    }

    /**
     * Adds the sort order to a product collection. Function made for subclassing
     * 
     * @param mixed Collection
     * @param mixed sortOrder
     * @return mixed Collection
     */

    protected function _addSortingToProductCollection ($collection = null, $sortOrder = array(), $categoryObject = null) {
        if ($collection === null) {
            return $collection;
        }

        if (!empty($sortOrder)) {
            foreach ($sortOrder as $orderCondition) {
                $orderBy = explode(':', $orderCondition);
                $collection->setOrder($orderBy[0], $orderBy[1]);
            }
        } else {
            $sortKey = $categoryObject->getDefaultSortBy();
            if (!$sortKey) {
                $sortKey = Mage::getStoreConfig('catalog/frontend/default_sort_by');
            }

            $sortOrder = 'asc';
            if ($sortKey == 'created_at' || $sortKey == 'updated_at') {
                $sortOrder = 'desc';
            }


            $configApi = Mage::helper('highstreet_hsapi/config_api');
            $sortOrderConfig = $configApi->attributesSortOrder();
            if (array_key_exists($sortKey, $sortOrderConfig)) {
                $sortOrder = $sortOrderConfig[$sortKey];
            }

            $collection->setOrder($sortKey, $sortOrder);
        }

        return $collection;
    }

    /**
     * Joins tables for attributes and adds given filters 
     * Inspired by the applyFilterToCollection function by the class Mage_Catalog_Model_Resource_Layer_Filter_Attribute
     *
     * @param mixed Collection
     * @param array filters
     * @return mixed Collection
     *
     */
    protected function _filterProductsCollection ($collection = null, $filters = array()) {
        if ($collection === null || count($filters) <= 0) {
            return $collection;
        }

        foreach ($filters as $filter) {
            if (array_key_exists('attribute', $filter)) {

                if ($filter['attribute'] === 'price') {
                    foreach ($filter as $operator => $value) {
                        if ($operator != 'attribute') {
                            $collection->addAttributeToFilter(array(array('attribute' => $filter['attribute'], $operator => $value)));
                        }
                    }
                } else {
                    $attributeObject = $this->_attributesModel->getAttribute($filter['attribute'], false);
                    $resource = Mage::getSingleton('core/resource');
                    $connection = $resource->getConnection('default_read');
                    $tableAlias = $filter['attribute'] . '_idx';
                    $conditions = array(
                        "{$tableAlias}.entity_id = e.entity_id",
                        $connection->quoteInto("{$tableAlias}.attribute_id = ?", $attributeObject['id']),
                        $connection->quoteInto("{$tableAlias}.store_id = ?", $collection->getStoreId())
                    );

                    foreach ($filter as $operator => $filterValue) {
                        if ($operator != 'attribute') {
                            if (is_array($filterValue)) {
                                
                                $whereString = "(";
                                $i = 0;
                                foreach ($filterValue as $value) {
                                    if ($i >= count($filterValue)-1) {
                                        $whereString .= $connection->quoteInto("{$tableAlias}.value = ?", $value);
                                    } else {
                                        $whereString .= $connection->quoteInto("{$tableAlias}.value = ?", $value) . ' OR ';
                                    }
                                    $i++;
                                }
                                $whereString .= ")";

                                array_push($conditions, $whereString);
                                
                            } else {
                                array_push($conditions, $connection->quoteInto("{$tableAlias}.value = ?", $filterValue));
                            }
                        }
                    }

                    $collection->getSelect()->join(
                        array($tableAlias => $resource->getTableName('catalog/product_index_eav')),
                        implode(' AND ', $conditions),
                        array()
                    );
                }
            }
        }

        return $collection;
    }

    /**
     *
     * Gets attributes of a given product object. 
     *
     * @param Mage_Catalog_Model_Product ResProduct, a product object
     * @param string Additional_attributes, an string of attributes to get for the product, comma delimited
     * @param bool include_configuration_details, weather to include child products in the product object and configurable attributes (For both configurable products and bundled products). Default value is fale
     * @param bool include_media_gallery, weather to include the media gallery in the product object. Default value is fale
     * @return array Array with information about the product, according to the Attributes array param
     *
     */

    private function _getProductAttributes($resProduct = false, $additional_attributes = null, $include_configuration_details = false, $include_media_gallery = false) {
        if (!$resProduct) {
            return null;
        }

        $product = array();

        $attributes = $this->_getCoreAttributes();

        foreach ($attributes as $attribute) {
            //always set final price to the special price field
            if ($attribute === "special_price" || $attribute === "final_price") {
                $product[$attribute] = $resProduct->getFinalPrice(1);

                if ($product[$attribute] === false) {
                    $product[$attribute] = null;
                }

                continue;
            }

            if ($attribute === "is_salable") {
                $product["is_salable"] = (bool)$resProduct->getData('is_salable');
                continue;
            }

            // Translate this into an array of "translations" if we run into more problems
            $fieldName = $attribute;
            if ($attribute == "type") {
                $attribute = "type_id";
                $fieldName = "type";
            }

            if ($resProduct->getResource()->getAttribute($attribute)) {
                $value = $resProduct->getResource()->getAttribute($attribute)->getFrontend()->getValue($resProduct);
                $product[$fieldName] = $value;
            }
        }

        $product['images'] = array();
        $product['images']['small_image'] = $product['small_image'];
        $product['images']['image'] = $product['image'];
        $product['images']['thumbnail'] = $product['thumbnail'];
        unset($product['small_image']);
        unset($product['image']);
        unset($product['thumbnail']);


        if (!empty($additional_attributes)) {
            $additionalAttributesArray = explode(',', $additional_attributes);
        }

        $product['attribute_values'] = array(); // Make sure to always return an object for this key
        // if additional attributes specified
        if (!empty($additionalAttributesArray) && count($additionalAttributesArray) > 0) {
            $product['attribute_values'] = $this->_getAdditionalAttributesForProduct($resProduct, $additionalAttributesArray);
        } 

        $product['id'] = $resProduct->getId();


        //We will deprecate special_from_date and special_to_date soon, but for now we make sure that the special price is always applicable
        //this is correct because special_price always has the value of the finalprice
        $product["special_from_date"] = self::SPECIAL_PRICE_FROM_DATE_FALLBACK;
        $product["special_to_date"] = null;


        if (array_key_exists("special_price", $product) && array_key_exists("price", $product) && $product["special_price"] >= $product["price"]) {
            $product["special_price"] = null;
        }

        
        if ($resProduct->getTypeId() == 'bundle') {
            $product["price"] = Mage::getModel('bundle/product_price')->getTotalPrices($resProduct,'min',1);
        }

        
        if ($include_media_gallery) {
            $mediaGalleryValue = $this->_getMediaGalleryImagesForProductID($product["id"]);
            $product['media_gallery'] = $mediaGalleryValue;
        }

        if($resProduct->getTypeId() == 'configurable' && $include_configuration_details){
            // Remove `hs_specifications` attribute from additionalAttributes for child products, this is currently not needed and can make the API call much slower
            if (($index = array_search('hs_specifications', $additionalAttributesArray)) !== FALSE) {
                unset($additionalAttributesArray[$index]);
            }

            $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($resProduct);

            //build the configuration_attributes array
            $configurableAttributes = $conf->getConfigurableAttributesAsArray($resProduct);

            $tmpConfigurableAttributes = array();
            foreach($configurableAttributes as $attribute) {
                array_push($tmpConfigurableAttributes,$attribute['attribute_code']);
            }

            $product['configurable_attributes'] = $tmpConfigurableAttributes;

            //build the configuration_attributes array if we want to display these
            $product['child_products'] = array();
            $simple_collection = $conf->getUsedProductCollection()
                ->addAttributeToSelect('*')
                ->addFilterByRequiredOptions();

            foreach($simple_collection as $resProduct){
                if(!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock') 
                    && !$resProduct->isSaleable())
                    continue;

                if ($resProduct->getData('status') == Mage_Catalog_Model_Product_Status::STATUS_DISABLED || $resProduct->getStatus() == Mage_Catalog_Model_Product_Status::STATUS_DISABLED ||
                    $resProduct->getData('status') == "Uitgeschakeld" || $resProduct->getStatus() == "Uitgeschakeld") {
                    continue;
                }

                $simpleProductAdditionalAttributesArray = $product['configurable_attributes']; // A configurable product always has a configuration so 'configurable_attributes' is always filled
                if (!empty($additionalAttributesArray) && count($additionalAttributesArray) > 0) { // If we want to get additional attributes, merge them
                    $simpleProductAdditionalAttributesArray = array_merge($additionalAttributesArray, $simpleProductAdditionalAttributesArray);
                }
                
                $simpleProductAdditionalAttributesArray = array_unique($simpleProductAdditionalAttributesArray); // Make sure that we don't get multiple of the same attributes
                $simpleProductAdditionalAttributesString = implode($simpleProductAdditionalAttributesArray, ',');

                $simpleProductObject = $this->_getProductAttributes($resProduct, $simpleProductAdditionalAttributesString, $include_configuration_details, $include_media_gallery); 

                array_push($product['child_products'], (object)$simpleProductObject);
            }

            unset($tmpConfigurableAttributes);
        }

        if($resProduct->getTypeId() == 'bundle' && $include_configuration_details) {
            // Remove `hs_specifications` attribute from additionalAttributes for child products, this is currently not needed and can make the API call much slower
            if (($index = array_search('hs_specifications', $additionalAttributesArray)) !== FALSE) {
                unset($additionalAttributesArray[$index]);
            }

            $bundleProduct = Mage::getModel('bundle/product_type')->setProduct($resProduct);
            $bundles = $bundleProduct->getOptionsCollection()->getData();
            foreach($bundles as $bundle) {

                $children = $bundleProduct->getSelectionsCollection(array($bundle['option_id']));
                foreach($children as $child) {


                    $childRes['position'] = $child->getPosition();
                    $childRes['selection_id'] = $child->getSelectionId();
                    $childRes['selection_qty'] = $child->getSelectionQty();                    
                    $childRes['selection_can_change_qty'] = $child->getSelectionCanChangeQty();
                    $childRes['is_default'] = $child->getIsDefault();

                    //flinders-specific, but should not throw an error when not implemented
                    $childRes['selection_thumbnail'] = $child->getSelectionThumbnail();
                    $childRes['selection_modified_name'] = $child->getSelectionModifiedname();
                    
                    $bundledProductAdditionalAttributesString = implode($additionalAttributesArray, ',');

                    $childRes['product'] = $this->_getProductAttributes($child, $bundledProductAdditionalAttributesString, $include_configuration_details, $include_media_gallery);
                    $bundle['children'][] = $childRes;
                }
                $product['bundles'][] = $bundle;
            }




        }
        
        $this->_convertProductDates($product);

        $product = $this->_setImagePaths($product);

        return $product;
    }

    protected function _getAdditionalAttributesForProduct($resProduct = null, $additionalAttributesArray) {
        if ($resProduct === null) {
            return array();
        }

        $response = array();

        foreach ($additionalAttributesArray as $attribute) {
            if ($attribute == "media_gallery") {
                continue;
            }

            if ($attribute === "share_url") {
                $additionalAttributeData = array();
                $additionalAttributeData['title'] = "Share url";
                $additionalAttributeData['code'] = "share_url";
                $additionalAttributeData['type'] = "url";
                $additionalAttributeData['inline_value'] = $resProduct->getProductUrl();
                $response[] = $additionalAttributeData;

                continue;
            }

            if ($attribute === "hs_specifications") {
                $additionalAttributeData = array();
                $additionalAttributeData['title'] = "Highstreet Specifications";
                $additionalAttributeData['code'] = "hs_specifications";
                $additionalAttributeData['type'] = "html";
                $html = "";

                // Register product in the Mage registry, needed for the following block
                Mage::register('product', $resProduct);

                // SEE: Mage_Catalog_Block_Product_View_Attributes
                $controller = Mage::app()->getLayout();
                $block = $controller->createBlock('catalog/product_view_attributes');

                // Same function as used by the layouting
                foreach ($block->getAdditionalData() as $attributeData) {

                    if ($attributeData['value'] === Mage::helper('catalog')->__('No') || $attributeData['value'] === Mage::helper('catalog')->__('N/A')) {
                        continue;
                    }


                    $html .=  "<p><strong>".$attributeData['label'].":</strong></br>".$attributeData['value']."</p>";
                }

                $additionalAttributeData['inline_value'] = $html;
                $response[] = $additionalAttributeData;

                // Take responsibility to unregister the product 
                Mage::unregister('product');

                continue;
            }

            if ($attribute === "tax_price") {
                $additionalAttributeData = array();
                $additionalAttributeData['title'] = "Price with tax";
                $additionalAttributeData['code'] = "tax_price";
                $additionalAttributeData['type'] = "number";
                $additionalAttributeData['inline_value'] = Mage::helper('tax')->getPrice($resProduct, $resProduct->getFinalPrice(), true);
                $response[] = $additionalAttributeData;

                continue;
            }

            $attributeObject = $resProduct->getResource()->getAttribute($attribute);

            if ($attributeObject !== false) {
                $readableAttributeValue = $attributeObject->getFrontend()->getValue($resProduct); // 'frontend' value, human readable value

                $attributesData = $this->_attributesModel->getAttribute($attribute);

                if (!array_key_exists('title', $attributesData) ||
                    !array_key_exists('code', $attributesData) ||
                    $attributesData['title'] == null ||
                    $attributesData['code'] == null ||
                    $attributeObject->getFrontendInput() == null) {
                    continue;
                }

                // Pre-make attribute object to be put into json
                $additionalAttributeData = array();
                $additionalAttributeData['title'] = $attributesData['title'];
                $additionalAttributeData['code'] = $attributesData['code'];
                $additionalAttributeData['type'] = $attributeObject->getFrontendInput();

                // Switch statement from /app/code/core/Mage/Catalog/Model/Product/Attribute/Api.php:301
                // Gets all attribute types and fill in the value field of the attribute object
                switch ($attributesData['type']) {
                    case 'text':
                    case 'textarea':
                    case 'price':
                        $additionalAttributeData['inline_value'] = $readableAttributeValue;
                    break;
                    case 'date':
                        if ($readableAttributeValue == null) {
                            $additionalAttributeData['inline_value'] = null;
                        } else {
                            $additionalAttributeData['inline_value'] = strtotime($readableAttributeValue);
                        }

                        $additionalAttributeData['raw_value'] = $readableAttributeValue;
                    break;
                    case 'boolean':
                        $attributeMethod = "get" . uc_words($attribute);
                        $idAttributeValue = $resProduct->$attributeMethod();
                        $additionalAttributeData['raw_value'] = $readableAttributeValue;
                        $additionalAttributeData['inline_value'] = ($idAttributeValue == 1 ? true : false);
                    break;
                    case 'select':
                    case 'multiselect':
                        $hasFoundValue = false;
                        $additionalAttributeData['value'] = array();

                        $mutliSelectValues = $resProduct->getAttributeText($attribute); // Get values for multiselect type (array)

                        // Loop trough select options of attribute
                        foreach ($attributesData['options'] as $key => $value) {
                            if (($value->title === $readableAttributeValue && $attributesData['type'] === 'select') ||  // If attribute type is single select option, check title
                                ((is_array($mutliSelectValues) && in_array($value->title, $mutliSelectValues) || ($value->title === $mutliSelectValues)) && 
                                 $attributesData['type'] === 'multiselect') // If attribute type is multi select option, check if value is in array of possible options or equal to the title
                                ) {
                                $attributeValueObject = array();
                                $attributeValueObject['id'] = $value->value;
                                $attributeValueObject['title'] = $value->title;
                                $attributeValueObject['sort_hint'] = $value->sort_hint;
                                $additionalAttributeData['value'][] = $attributeValueObject;
                                $hasFoundValue = true;

                                if ($attributesData['type'] === 'select') {
                                    break; // single select option doesn't have to loop trough all possibilities
                                }
                            }
                        }

                        // If type is select and there is only one element, return the element as an object and not an array with 1 object
                        if ($attributesData['type'] == 'select' && count($additionalAttributeData['value']) == 1) {
                            $additionalAttributeData['value'] = $additionalAttributeData['value'][0];
                        }

                        // No value was found, make value field in attribute object null
                        if (!$hasFoundValue) {
                            $additionalAttributeData['value'] = null;
                        }
                    break;
                    default:
                        if ($readableAttributeValue != null) {
                            $additionalAttributeData['inline_value'] = $readableAttributeValue;
                        }
                    break;
                }

                $response[] = $additionalAttributeData;
            }
        }

        return $response;
    }

    /**
     * Converts product dates to timestamp
     *
     */
    private function _convertProductDates(& $product) {

        if (!empty($product['created_at'])) {
            $product['created_at'] = strtotime($product['created_at']);
        }
        if (!empty($product['updated_at'])) {
            $product['updated_at'] = strtotime($product['updated_at']);
        }
        if (!empty($product['special_from_date'])) {
            $product['special_from_date'] = strtotime($product['special_from_date']);
        }
        if (!empty($product['special_to_date'])) {
            $product['special_to_date'] = strtotime($product['special_to_date']);
        }
        if (!empty($product['news_from_date'])) {
            $product['news_from_date'] = strtotime($product['news_from_date']);
        }
        if (!empty($product['news_to_date'])) {
            $product['news_to_date'] = strtotime($product['news_to_date']);
        }

    }

    /**
     * Gets stock (voorraad) information about a certain product
     * 
     * @param product A product
     * 
     * @return array Array of stock data
     */

    protected function _getStockInformationForProduct($product) {
        $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        $stockinfo = array();

        $stockinfo['product_entity_id'] = $product->getId();
        $stockinfo['quantity'] = $stock->getQty();
        $stockinfo['is_in_stock'] = (boolean)$stock->getIsInStock();
        $stockinfo['min_sale_quantity'] = $stock->getMinSaleQty();
        $stockinfo['max_sale_quantity'] = $stock->getMaxSaleQty();
        $stockinfo['manage_stock'] = (boolean)$stock->getManageStock();
        $stockinfo['backorders'] = (boolean)$stock->getBackorders();
        
        $stockinfo['quantity_increments'] = (boolean)$stock->getQtyIncrements();
        $stockinfo['quantity_increments_value'] = (int)$stock->getQtyIncrements();

        return $stockinfo;                     
    }

    /**
     * Sets the image paths properly with the relative path.
     * 
     * @param product Array with product information
     * @return product Same product, but with formatted image uri's
     */
    private function _setImagePaths($product = false) {
        if (!$product) {
            return $product;
        }
        
        foreach ($product['images'] as $key => $value) {
            if (!strstr($value, self::PRODUCTS_MEDIA_PATH)) {
                if($value != self::NO_IMAGE_PATH && $value != null) {
                    $value = self::PRODUCTS_MEDIA_PATH . $value;
                } else {
                    $value = null;
                }
            }
            
            $product['images'][$key] = $value;
        }
        
        return $product;
    }

    /** 
     * Gets media gallery items for a given product id. Returns an array or media gallery items
     *
     * This function explicitly makes new product objects. 
     * During development this was found to be faster then passing a product object and calling "->load('media_gallery')" on the object
     *
     * @param integer Product ID, ID of a product to get media gallery images for
     * @return array Array of media gallery items
     */

    public function _getMediaGalleryImagesForProductID($productId = null) {
        if (!$productId) {
            return null;
        }

        $output = array();

        $resProduct = Mage::getModel('catalog/product')->load($productId);
        foreach ($resProduct->getMediaGalleryImages()->getItems() as $key => $value) {
            $imageData = $value->getData();

$resProduct = false;
            if ($this->_shouldExcludeImageFromMediaGallery($imageData["file"], $resProduct)) {
                continue;
            }

            if (array_key_exists('file', $imageData) && !strstr($imageData['file'], self::PRODUCTS_MEDIA_PATH)) {
                $imageData['file'] = self::PRODUCTS_MEDIA_PATH . $imageData['file'];
            }
            unset($imageData["path"]);
            unset($imageData["url"]);
            unset($imageData["id"]);
            $output[] = $imageData;
        }
        
        return $output;
    }

    /**
     * Function that compares the given image to other images in the product object in order to determine of this image should be included in the media gallery
     * Made for subclassing
     */
    protected function _shouldExcludeImageFromMediaGallery($image, $product) {
        return false;
    }

    /**
     * For some vendors we have implemented custom attribute names, if such an attribute needs a specific other attribute to load, we can override this function and do this.
     * Implementation here is empty because we currently don't need it in the base class
     *
     * @param array $additionalAttributes, the array of additional attributes
     * @return array Array of additional attributes
     *
     */
    protected function _translateAdditionalAttributes($additionalAttributes) {
        return $additionalAttributes;
    }

    /**
     * Returns an array of all core attributes
     *
     * @return array Array of attributes
     */
    private function _getCoreAttributes () {
        return array("entity_id", "sku", "type", "created_at", "updated_at", 
                    "name", "news_from_date", "news_to_date", "price", 
                    "image", "small_image", "thumbnail",
                    "special_from_date", "special_to_date", "special_price", "is_salable");
    }

}
