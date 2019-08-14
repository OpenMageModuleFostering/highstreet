<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_Products extends Mage_Core_Model_Abstract
{
    const PRODUCTS_MEDIA_PATH = '/media/catalog/product';
    const NO_IMAGE_PATH = 'no_selection';
    const RANGE_FALLBACK_RANGE = 100;
    const SPECIAL_PRICE_FROM_DATE_FALLBACK = "1970-01-01 00:00:00";

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

        $attributesArray = array();
        // get attributes
        if (!empty($additional_attributes)) {
            $attributesArray = explode(',', $additional_attributes);
        }

        $attributesArray = array_merge($attributesArray, $this->_getCoreAttributes());

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
            $collection->addStoreFilter();
            Mage::getSingleton('catalog/product_status')->addVisibleFilterToCollection($collection);
            Mage::getSingleton('catalog/product_visibility')->addVisibleInCatalogFilterToCollection($collection);

        }



        
        $categoryNotSet = false;
        
        if (empty($categoryId)) {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
            $categoryNotSet = true;
        }
        
        $category = Mage::getModel('catalog/category')->load($categoryId);
        if ($category->getId() === NULL) {
            return array();
        }

        // apply search
        if ($categoryId && !$categoryNotSet) {
           $collection->addCategoryFilter($category);
        } 

        if (!empty($range)) {
            $range = explode(',', $range);
        }

        if (!is_array($range)) {
            $range = array(0, self::RANGE_FALLBACK_RANGE);
        }

        $collection->getSelect()->limit($range[1], $range[0]);
    
        // apply attributes        
        $collection->addAttributeToSelect($attributesArray);
        
        //apply filters
        if(!empty($filters)) {
            foreach ($filters as $filter) {
                if (array_key_exists('attribute', $filter)) {
                    foreach ($filter as $operator => $condition) {
                        if ($operator != 'attribute') {
                            $collection->addAttributeToFilter(array(array('attribute' => $filter['attribute'], $operator => $condition)));
                        }
                    }
                }
            }
        }
        
        // Apply type filter, we only want Simple and Configurable and Bundle products in our API
        $collection->addAttributeToFilter('type_id', array('simple', 'configurable', 'bundle'));

        // apply order
        if (!empty($order)) {
            foreach ($order as $orderCondition) {
                $orderBy = explode(':', $orderCondition);
                $collection->setOrder($orderBy[0], $orderBy[1]);
            }
        } else {
            if($searching) {
                $collection->setOrder('relevance', 'desc');
            } else {

                $sortKey = $category->getDefaultSortBy();
                if (!$sortKey) {
                    $sortKey = Mage::getStoreConfig('catalog/frontend/default_sort_by');
                }
                $collection->setOrder($sortKey, 'asc');
            }   
        }

        // Add 'out of stock' filter, if preffered 
        if (!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock')) {
            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection);

            // Make a better fix for this. At this time this seems impossible, this doesn't work: 
            // $collection->addAttributeToFilter('is_salable', array('eq' => 1));
            // Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection); // Another popular suggestion around the web, crashes the app for now and 'under water' does the same as l:276
            // For now we look trough all the configurable products in the collection of a certain category and filter out the unneded products
            $collectionConfigurable = Mage::getResourceModel('catalog/product_collection')->addAttributeToFilter('type_id', array('eq' => 'configurable'));
            $collectionConfigurable->addCategoryFilter($category);

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

        if (!isset($productCount)) {
            // get total product count
            $productCount = $collection->getSize();
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
                $products['products'] = $collection->getAllIds($range[1], $range[0]);
            }
        }

        $products['filters'] = array();
        if (!$hideFilters) {
            $products['filters'] = $this->getFilters($categoryId);
        }
        
        $products['product_count'] = $productCount;

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

        $products['product_count'] = $collection->getSize();

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



    /***********************************/
    /**
     * PRIVATE/protected FUNCTIONS
     */
    /***********************************/
    /**
     * Returns filters
     *
     * @param int $categoryId
     * @return array
     * @author Andrey Posudevsky
     *
     */
    protected function getFilters($categoryId = false) {

        if (!$categoryId) {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
        }

        $layer = Mage::getModel('catalog/layer');

        $category = Mage::getModel('catalog/category')->load($categoryId);
        $layer->setCurrentCategory($category);
        $controller = @Mage_Core_Controller_Front_Action::getLayout();
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
                $title = str_replace('<span class="price">', "", $option->getLabel());
                $title = str_replace('</span>', "", $title);

                $count = $option->getData('count');
                array_push($options, array('value' => $option->getValue(), 'title' => $title, 'product_count' => $count));
            }

            if (count($options) > 0) {
                array_push($resultFilters, 
                    array(
                        'title' => $attribute->getData('frontend_label'), 
                        'type' => $attribute->getFrontendInput(), 
                        'code' => $attribute->getAttributeCode(), 
                        'options' => $options
                        )
                    );
            }
        }

        return $resultFilters;
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

    private function _getProductAttributes($resProduct = false, $additional_attributes = nil, $include_configuration_details = false, $include_media_gallery = false) {
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
            $attributesModel = Mage::getModel('highstreet_hsapi/attributes');
            
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
                    $product['attribute_values'][] = $additionalAttributeData;

                    continue;
                }

                $attributeObject = $resProduct->getResource()->getAttribute($attribute);

                if ($attributeObject !== false) {
                    $readableAttributeValue = $attributeObject->getFrontend()->getValue($resProduct); // 'frontend' value, human readable value

                    $attributesData = $attributesModel->getAttribute($attribute);

                    if ($attributesData['title'] == null ||
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

                    $product['attribute_values'][] = $additionalAttributeData;
                }
            }
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

            foreach($simple_collection as $simple_product){
                if(!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock') 
                    && !$simple_product->isSaleable())
                    continue;

                $resProduct = Mage::getModel('catalog/product')->load($simple_product->getId());
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

    private function _getStockInformationForProduct($product) {
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
     * @param integer Product ID, ID of a product to get media gallery images for
     * @return array Array of media gallery items
     */

    public function _getMediaGalleryImagesForProductID($productId = null) {
        if (!$productId) {
            return null;
        }

        $output = array();

        foreach (Mage::getModel('catalog/product')->load($productId)->getMediaGalleryImages()->getItems() as $key => $value) {
            $imageData = $value->getData();
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