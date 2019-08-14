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
    /**
     * @var bool
     */
    private $_addConfigurableAttributes = false;
    private $_addConfigurations = false;

    const PRODUCTS_MEDIA_PATH = '/media/catalog/product';
    const NO_IMAGE_PATH = 'no_selection';
    const RANGE_FALLBACK_RANGE = 100;
    const SPECIAL_PRICE_FROM_DATE_FALLBACK = "1970-01-01 00:00:00";

    /**
     * Gets a single product for a given productId and attributes
     * 
     * @param integer ProductId, product id of the product to be gotten
     * @param string Attributes, string of attributes straight from the URL
     * @return array Product
     */
    public function getSingleProduct($productId = false, $attributes, $child_product_attributes)
    {
        if (!$productId) {
            return nil;
        } 

        $product = Mage::getModel('catalog/product')->load($productId);
        
        return $this->_getProductAttributes($product, $attributes, $child_product_attributes);
    }

    /**
     * Gets products with attributes for given order, range, filters, search and categoryId
     * 
     * @param string Attributes, string of attributes straight from the URL
     * @param string Child product attributes, string of attributes for the child products, comma sepperated
     * @param string Order, order for the products
     * @param string Range of products. Must formatted like "0,10" where 0 is the offset and 10 is the count
     * @param string Search string for filtering on keywords
     * @param integer CategoryId, category id of the category which will be used to filter
     * @return array Product
     */
    public function getProductsForResponse($attributes, $child_product_attributes, $order, $range, $filters, $search, $categoryId)
    {
        $addGalleryImages = false;
        $searching = !empty($search);

        // get attributes
        if (!empty($attributes)) {
            $attributesArray = explode(',', $attributes);

            if(in_array('configurable_attributes',$attributesArray)){
                $this->_addConfigurableAttributes = true;
            }
            if(in_array('configurations',$attributesArray)){
                $this->_addConfigurations = true;
            }
        }

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
        // apply search
        if ($categoryId && !$categoryNotSet) {
           $collection->addCategoryFilter($category);
        } 

        if (!empty($range)) {
            $range = explode(',', $range);
        }

        if (!empty($range)) {
            $collection->getSelect()->limit($range[1], $range[0]);
        } else {
            $collection->getSelect()->limit(self::RANGE_FALLBACK_RANGE);
        }
    
        // apply attributes
        if (!empty($attributesArray)) {
            foreach ($attributesArray as $attribute) {
                $collection->addAttributeToSelect($attribute);
                if ($attribute == 'media_gallery') {
                    $addGalleryImages = true;
                }
            }
        }
        else {

            // select all attributes
            $collection->addAttributeToSelect('*');
            $addGalleryImages = true;
        }
        
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

        
        // Apply type filter, we only want Simple and Configurable products in our API
        $collection->addAttributeToFilter('type_id', array('simple', 'configurable'));

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

        
        // Add media gallery to collection we cant do this in an earlier stage because it gives really strange results (filter not working, range not working!)
        if ($addGalleryImages) {
            $this->_addMediaGalleryAttributeToCollection($collection);
        }

        if (!isset($productCount)) {
            // get total product count
            $productCount = $collection->getSize();
        }
        /**
         * Format result array
         */
        $products = array('products' => array());

        foreach($collection as $product) {
            array_push($products['products'], $this->_getProductAttributes($product, $attributes, $child_product_attributes));
        }

        $resultFilters = $this->getFilters($categoryId);

        $products['filters'] = $resultFilters;
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
     * @param string Attributes, comma seperated string of attributes
     * @param string Child products attributes, comma seperated string of attributes for the child products
     * @param string range, formatted range string
     * @return array Array of products
     *
     */

    public function getProductsFilteredByProductIds($productIds = false, $attributes, $child_product_attributes, $range) {

        $products = array('products' => array());

        if (!$productIds) {
            $products['product_count'] = 0;
            $products['range'] = array("location" => 0, "length" => 0);
            return $products;
        }

        $collection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('entity_id', array('in' => $productIds)); 

        // get attributes
        if (!empty($attributes)) {
            $attributesArray = explode(',', $attributes);
        }

        // apply attributes
        if (!empty($attributes)) {
            foreach ($attributesArray as $attribute) {
                $collection->addAttributeToSelect($attribute);
            }
        } else {
            // select all attributes
            $collection->addAttributeToSelect('*');
            $this->_addMediaGalleryAttributeToCollection($collection);
        }

        if (!empty($range)) {
            $range = explode(',', $range);
            $collection->getSelect()->limit($range[1], $range[0]);
        }

        // Add 'out of stock' filter, if preffered 
        if (!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock')) {
            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection);

            // For comments, see :285
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
        foreach($collection as $product) {
            array_push($products['products'], $this->_getProductAttributes($product, $attributes, $child_product_attributes));
        }

        $products['product_count'] = $collection->getSize();;

        $rangeLength = $range[1];
        if ($rangeLength > count($products["products"])) {
            $rangeLength = count($products["products"]);
        }

        $products['range'] = array("location" => $range[0], "length" => $rangeLength);

        return $products;
    }

    /**
     * Gets a batch of products for a given comma sepperated productIds, attributes and child product attributes
     * 
     * @param string Ids, product ids, comma sepperated
     * @param string Attributes, string of attributes, comma sepperated
     * @param string Child product attributes, string of attributes for the child products, comma sepperated
     * @return array Product
     */

    public function getBatchProducts($ids = false, $attributes, $child_product_attributes) {
        $idsArray = explode(',', $ids);

        $products = array();
        foreach ($idsArray as $value) {
            $products[] = $this->_getProductAttributes(Mage::getModel('catalog/product')->load($value), $attributes, $child_product_attributes);
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
            $simple_collection = $conf->getUsedProductCollection()->addFilterByRequiredOptions();
            $products = $simple_collection;
        } else if($product->getTypeId() == 'simple'){
            $products[] = $product;
        } else {
            return; //Other product types not supported yet
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
     * @param string Attributes, comma seperated string of attributes
     * @param string Child products attributes, comma seperated string of attributes for the child products
     * @param string range, formatted range string
     * @return array Array of product ids
     *
     */

    public function getRelatedProducts($type, $productId = false, $attributes, $child_product_attributes, $range) {
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

        return $this->getProductsFilteredByProductIds($productIds, $attributes, $child_product_attributes, $range);

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

        //$layered_nav = $this->getLayout()->createBlock('catalog/layer_view');
        $layered_nav = Highstreet_Hsapi_IndexController::getLayout()->createBlock('catalog/layer_view');
        $filters = $layered_nav->getFilters();
        $category = Mage::getModel('catalog/category')->load($categoryId);
        $layer->setCurrentCategory($category);
        $attributes = $layer->getFilterableAttributes('price');
        $resultFilters = array();
        foreach ($attributes as $attribute) {

            if ($attribute->getAttributeCode() == 'price') {
                $filterBlockName = 'catalog/layer_filter_price';
            } else {
                $filterBlockName = 'catalog/layer_filter_attribute';
            }
            //$result = $this->getLayout()->createBlock($filterBlockName)->setLayer($layer)->setAttributeModel($attribute)->init();
            $result = Highstreet_Hsapi_IndexController::getLayout()->createBlock($filterBlockName)->setLayer($layer)->setAttributeModel($attribute)->init();
            $options = array();
            foreach($result->getItems() as $option) {
                $label = str_replace('<span class="price">', "", $option->getLabel());
                $label = str_replace('</span>', "", $label);

                $count = $option->getData('count');
                array_push($options, array('filter' => $option->getValue(), 'label' => $label, 'product_count' => $count));
            }
            array_push($resultFilters, array($attribute->getAttributeCode() => $options));
        }

        return $resultFilters;
    }

    /**
     *
     * Gets attributes of a given product object. 
     *
     * @param Mage_Catalog_Model_Product ResProduct, a product object
     * @param string Attributes, an string of attributes to get for the product, comma delimited
     * @param string Child_product_attributes, attributes for the child products, comma delimited 
     * @return array Array with information about the product, according to the Attributes array param
     *
     */

    private function _getProductAttributes($resProduct = false, $attributes = nil, $child_product_attributes) {
        if (!$resProduct) {
            return null;
        }


        if(empty($attributes)) {
          $attributes = $this->_getSystemAttributes();
        } else {
          $attributes = explode(',', $attributes);
        }
            

        // if attributes specified
        if (!empty($attributes) && count($attributes) > 0) {
            foreach ($attributes as $attribute) {

                //always set final price to the special price field
                if ($attribute === "special_price" || $attribute === "final_price") {
                    $product[$attribute] = $resProduct->getFinalPrice(1);

                    if ($product[$attribute] === false) {
                        $product[$attribute] = null;
                    }

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

        } 


        $product['id'] = $resProduct->getId();


        //We will deprecate special_from_date and special_to_date soon, but for now we make sure that the special price is always applicable
        //this is correct because special_price always has the value of the finalprice
        $product["special_from_date"] = self::SPECIAL_PRICE_FROM_DATE_FALLBACK;
        $product["special_to_date"] = null;


        if (array_key_exists("special_price", $product) && array_key_exists("price", $product) && $product["special_price"] >= $product["price"]) {
            $product["special_price"] = null;
        }




        if (in_array("is_salable", $attributes)) {
            $product["is_salable"] = $resProduct->getData('is_salable');
        }



        if(in_array('configurable_attributes',$attributes)){
            $this->_addConfigurableAttributes = true;
        }

        if(in_array('child_products',$attributes)){
            $this->_addConfigurations = true;
        }

        if($resProduct->getTypeId() == 'configurable'){
            $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($resProduct);

            //build the configuration_attributes array
            $configurableAttributes = $conf->getConfigurableAttributesAsArray($resProduct);

            $tmpConfigurableAttributes = array();
            foreach($configurableAttributes as $attribute)
            {
                array_push($tmpConfigurableAttributes,$attribute['attribute_code']);
                //array_push($simpleCollectionAttributes,$attribute['attribute_code']);
            }
            if($this->_addConfigurableAttributes == true){
                $product['configurable_attributes'] = $tmpConfigurableAttributes;
            }

            //build the configuration_attributes array if we want to display these
            if($this->_addConfigurations == true){

                $product['child_products'] = array();
                $simple_collection = $conf->getUsedProductCollection()
                    ->addAttributeToSelect('*')
                    ->addFilterByRequiredOptions();

                foreach($simple_collection as $simple_product){
                    if(!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock') 
                        && !$simple_product->isSaleable())
                        continue;
                    
                    if(!$child_product_attributes)
                        $child_product_attributes = "entity_id,created_at,description,special_price,updated_at,image,sku,short_description,price,manufacturer";

                    $simpleProductRepresentation = $this->_getProductAttributes(Mage::getModel('catalog/product')->load($simple_product->getId()), $child_product_attributes);
                    $configuration = array();


                    foreach($tmpConfigurableAttributes as $attribute)
                    {
                        $method = 'get' . uc_words($attribute, '');
                        $configuration[$attribute] = $simple_product->$method();
                    }

                    $simpleProductRepresentation['configuration'] = $configuration;
                    array_push($product['child_products'],(object)$simpleProductRepresentation);
                }
            }

            unset($tmpConfigurableAttributes);
        }
        
        $this->_convertProductDates($product);

        $product = $this->_setImagePaths($product);

        //media gallery
        if(array_key_exists("media_gallery", $product)) {
            $product["media_gallery"] = $this->_getMediaGalleryImagesForProductID($product["id"]);
        }

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
     * Load media gallery in collection
     */
    private function _addMediaGalleryAttributeToCollection(Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Collection $_productCollection) {

        if (count($_productCollection == 0))
            return $_productCollection;

        $_mediaGalleryAttributeId = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'media_gallery')->getAttributeId();
        $_read = Mage::getSingleton('core/resource')->getConnection('catalog_read');

        $_mediaGalleryData = $_read->fetchAll('
            SELECT
                main.entity_id, `main`.`value_id`, `main`.`value` AS `file`,
                `value`.`label`, `value`.`position`, `value`.`disabled`, `default_value`.`label` AS `label_default`,
                `default_value`.`position` AS `position_default`,
                `default_value`.`disabled` AS `disabled_default`
            FROM `catalog_product_entity_media_gallery` AS `main`
                LEFT JOIN `catalog_product_entity_media_gallery_value` AS `value`
                    ON main.value_id=value.value_id AND value.store_id=' . Mage::app()->getStore()->getId() . '
                LEFT JOIN `catalog_product_entity_media_gallery_value` AS `default_value`
                    ON main.value_id=default_value.value_id AND default_value.store_id=0
            WHERE (
                main.attribute_id = ' . $_read->quote($_mediaGalleryAttributeId) . ')
                AND (main.entity_id IN (' . $_read->quote($_productCollection->getAllIds()) . '))
            ORDER BY IF(value.position IS NULL, default_value.position, value.position) ASC
        ');


        $_mediaGalleryByProductId = array();
        foreach ($_mediaGalleryData as $_galleryImage) {
            $k = $_galleryImage['entity_id'];
            unset($_galleryImage['entity_id']);
            if (!isset($_mediaGalleryByProductId[$k])) {
                $_mediaGalleryByProductId[$k] = array();
            }
            $_galleryImage['file'] = self::PRODUCTS_MEDIA_PATH . $_galleryImage['file'];
            $_mediaGalleryByProductId[$k][] = $_galleryImage;
        }
        unset($_mediaGalleryData);

        foreach ($_productCollection as &$_product) {
            $_productId = $_product->getData('entity_id');
            if (isset($_mediaGalleryByProductId[$_productId])) {
                $_product->setData('media_gallery', array('images' => $_mediaGalleryByProductId[$_productId]));
            }
        }
        unset($_mediaGalleryByProductId);

        return $_productCollection;
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
     * Gets system attributes. This function is used when there are no attributes give as a param in the URL of the API call
     * 
     * @return array Array with attribute names
     */

    private function _getSystemAttributes() {
                
        $entityTypeId = Mage::getModel('catalog/product')->getResource()->getEntityType()->getId();
    
        // get only system attributes
        $attributes = Mage::getResourceModel('eav/entity_attribute_collection')
        ->setEntityTypeFilter($entityTypeId)
        ->addFieldToFilter('main_table.is_user_defined', 0)
        ->addFieldToFilter('additional_table.is_visible', 1);

        $attributeNames = array();

        foreach($attributes as $attribute) {
            $attributeNames[] = $attribute->getName();
        }

        return $attributeNames;    
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


        if (array_key_exists('image', $product) && !strstr($product['image'], self::PRODUCTS_MEDIA_PATH)) {
            if($product['image'] != self::NO_IMAGE_PATH)
                $product['image'] = self::PRODUCTS_MEDIA_PATH . $product['image'];
            else
                $product['image'] = null;
        }

        if (array_key_exists('thumbnail', $product) && !strstr($product['thumbnail'], self::PRODUCTS_MEDIA_PATH)) {
            if($product['thumbnail'] != self::NO_IMAGE_PATH)
                $product['thumbnail'] = self::PRODUCTS_MEDIA_PATH . $product['thumbnail'];
            else
                $product['thumbnail'] = null;
        }

        if (array_key_exists('small_image', $product) && !strstr($product['small_image'], self::PRODUCTS_MEDIA_PATH)) {
            if($product['small_image'] != self::NO_IMAGE_PATH)
                $product['small_image'] = self::PRODUCTS_MEDIA_PATH . $product['small_image'];
            else
                $product['small_image'] = null;
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
        
        return array("images" => $output);
    }



}