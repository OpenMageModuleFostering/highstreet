<?php

/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Radovan Dodic (radovan.dodic@atessoft.rs) ~ AtesSoft
 * @copyright   Copyright (c) 2016 Highstreet
 */
class Highstreet_Hsapi_Helper_Config_Cart extends Highstreet_Hsapi_Helper_Config_Account {

    // Magento <= 1.7 does not have this const set up in Mage_Checkout_Helper_Cart
    const COUPON_CODE_MAX_LENGTH = 255;

    /**
     * Constructor class
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Retrieve shopping cart model object
     *
     * @return Mage_Checkout_Model_Cart
     */
    public function _getCart() {
        return Mage::getSingleton('checkout/cart');
    }

    /**
     * Get Cart helper object
     *
     * @return Mage_Checkout_Helper_Cart
     */
    public function _getCartHelper() {
        return Mage::helper('checkout/cart');
    }

    /**
     * Get checkout session model instance
     *
     * @return Mage_Checkout_Model_Session
     */
    public function _getSession() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current active quote instance
     *
     * @return Mage_Sales_Model_Quote
     */
    public function _getQuote() {
        return $this->_getCart()->getQuote();
    }

    /**
     * @return Mage_Checkout_Model_Type_Onepage
     */
    public function _getOnepage() {
        return Mage::getSingleton('checkout/type_onepage');
    }

    /**
     * Initialize cart
     */
    public function cartInit() {
        $this->_getCart()->init();
        return;
    }

    /**
     * Initialize product instance from request data
     *
     * @param string $pid
     * @return Mage_Catalog_Model_Product || false
     */
    public function _initProduct($pid) {
        if ($pid) {
            $product = Mage::getModel('catalog/product')
                    ->setStoreId(Mage::app()->getStore()->getId())
                    ->load($pid);
            if ($product->getId()) {
                return $product;
            }
        }
        return false;
    }

    /**
     * returns etag
     *
     * @return string
     */
    public function getCartEtag() {
        if (!Mage::getSingleton('core/session')->getCartEtag())
            $this->updateCartEtag();
        return Mage::getSingleton('core/session')->getCartEtag();
    }

    /**
     * generate etag and save to session
     */
    public function updateCartEtag() {
        $hash = bin2hex(mcrypt_create_iv(22, MCRYPT_DEV_URANDOM));
        Mage::getSingleton('core/session')->setCartEtag($hash);
        return;
    }

    /**
     * Add or Update product to Cart object (Quote)
     *
     * @param array $productData
     * @param array $errors
     * @return array $errors
     */
    public function addOrUpdateProductToCart($productData, $errors) {

        $params = array(
            'qty' => $productData['quantity'],
            'product' => $productData['product_id'],
            'related_product' => '',
        );
        $cart = $this->_getCart();
        if (!isset($productData['product_id']))
            return $errors;

        // init product by product_id
        $product = $this->_initProduct($productData['product_id']);
        // if product can not be loaded add to errors and exit function
        if (!$product) {
            $errors[] = $this->getErrorArrayForProduct($productData, "product_unavailable");
            return $errors;
        }


        // only simple, simple from configurable are checked for qty, if needed qty is corrected
        // bundles are only check if requested configuration can be added or not
        $productAvailibiltyAndQty = $this->getProductAvailabilityAndQty($product, $productData);
        if ($productAvailibiltyAndQty['qty'] == -1) { // out of stock, add error & exit function
            $errors[] = $this->getErrorArrayForProduct($productData, "quantity_changed", $productAvailibiltyAndQty['error']);
            return $errors;
        } elseif ($productAvailibiltyAndQty['qty'] < $productData['quantity']) { // requested qty not available, add error & change qty
            $errors[] = $this->getErrorArrayForProduct($productData, "quantity_changed", $productAvailibiltyAndQty['error']);
            $params['qty'] = $productAvailibiltyAndQty['qty'];
        }


        if (isset($productData['configuration'])) { // add configurable options
            $attributes = $this->getArrayOfAttributesForConfigProduct($productData['configuration']['attributes']);
            $params['super_attribute'] = $attributes;
        } else if (isset($productData['bundle_configuration'])) { // add bundle options
            $bundle = array();
            $bundleQty = array();
            foreach ($productData['bundle_configuration'] as $b) {
                $bundle[$b['option']] = $b['selection'];
                $bundleQty[$b['option']] = $b['quantity'];
            }
            $params['bundle_option'] = $bundle;
            $params['bundle_option_qty'] = $bundleQty;
        }

        // add or update product
        try {
            // set quote to be ready for changes
            $cart->getQuote()->setTotalsCollectedFlag(false);
            // check if quote item id is set and product exists in quote (update)
            if (isset($productData['id']) && $item = $this->_getQuote()->getItemById($productData['id'])) {
                // check if product has specific configuration, if its configurable or bundle
                if ($product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
                    // get simple ids
                    $oldSimpleId = $item->getOptionByCode('simple_product')->getProductId();
                    $newSimpleId = $productData['configuration']['child_product_id'];

                    // if there is no change in child product, update qty only
                    if ($oldSimpleId != $newSimpleId) {
                        // update buy_request data
                        if ($buyRequest = $item->getProduct()->getCustomOption('info_buyRequest')) {
                            $buyRequestArr = unserialize($buyRequest->getValue());
                            unset($buyRequestArr['super_attribute']);
                            foreach ($params['super_attribute'] as $key => $value) {
                                $buyRequestArr['super_attribute'][$key] = $value;
                            }
                            $buyRequest->setValue(serialize($buyRequestArr))->save();
                        }

                        // update simple product, id of new simple product
                        $item->getOptionByCode('simple_product')->setValue($newSimpleId)->setProductId($newSimpleId)->save();

                        // update attributes
                        if ($attr = $item->getOptionByCode('attributes')) {
                            foreach ($params['super_attribute'] as $key => $value) {
                                $attrArr[$key] = $value;
                            }
                            if (count($attrArr))
                                $attr->setValue(serialize($attrArr))->save();
                        }

                        // update product qty by old and new simple id
                        $item->getOptionByCode('product_qty_' . $oldSimpleId)->setProductId($newSimpleId)->setCode('product_qty_' . $newSimpleId)->setValue($params['qty'])->save();
                    } else {
                        $item->setQty($params['qty'])->save();
                    }
                } else {
                    // simple product or bundle product or any other product
                    $item->setQty($params['qty'])->save();
                }
                $this->updateCartEtag();
            } else {
                // if adding new product, add temp_id to $params
                if (isset($productData['temp_id']))
                    $params['temp_id'] = $productData['temp_id'];
                $cart->addProduct($product, $params);
            }
        } catch (Exception $e) {
            $this->logException($e, 'Adding / updating product to cart - Exception');
            $this->log(array(
                "Exception message" => $e->getMessage(),
                "Product data" => $productData), 'Adding / updating product to cart');
        }
        return $errors;
    }

    /**
     * checks product availability and qty
     *
     * @param Mage_Catalog_Model_Product
     * @param array $productData
     * @return array
     */
    public function getProductAvailabilityAndQty($product, $productData) {
        if ($product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_SIMPLE) {
            return $this->getSimpleProductAvailabilityAndQty($product, $productData['quantity']);
        } elseif ($product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            $simple_product = $this->_initProduct($productData['configuration']['child_product_id']);
            if ($simple_product) {
                return $this->getSimpleProductAvailabilityAndQty($simple_product, $productData['quantity']);
            }
        } elseif ($product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
            return $this->getBundleProductAvailabilityAndQty($product, $productData['quantity']);
        }

        // any other product return same input data
        return array(
            'error' => '',
            'qty' => $productData['quantity']
        );
    }

    /**
     * checks salability of child products inside bundle product
     *
     * @param Mage_Catalog_Model_Product
     * @param string $qty
     * @return array
     */
    public function getBundleProductAvailabilityAndQty($product, $qty) {
        $returnArray = array(
            'error' => '',
            'qty' => $qty
        );
        $childrenIds = $product->getTypeInstance(true)->getChildrenIds($product->getId(), true);
        $childBundleCollection = Mage::getModel('catalog/product')->getCollection()->addFieldToFilter('entity_id', $childrenIds);
        foreach ($childBundleCollection as $childBundle) {
            if (!$childBundle->isSalable()) {
                $returnArray['error'] = "Product is not in stock";
                $returnArray['qty'] = -1;
            }
        }
        return $returnArray;
    }

    /**
     * checks inventory of simple product
     * checks if there is enough qty of product to be added
     *
     * @param Mage_Catalog_Model_Product
     * @param string $qty
     * @return array
     */
    public function getSimpleProductAvailabilityAndQty($simple_product, $qty) {
        $returnArray = array(
            'error' => '',
            'qty' => $qty
        );
        $itemInventory = Mage::getModel('cataloginventory/stock_item')->loadByProduct($simple_product);
        $availableQuantity = $itemInventory->getQty() - $itemInventory->getMinQty();
        $isInStock = (bool) $itemInventory->getIsInStock();
        $isStockManaged = $itemInventory->getManageStock();
        $backordersAllowed = $itemInventory->getBackorders();
        if ($isStockManaged) {
            if (!$isInStock) {
                $returnArray['error'] = "Product is not in stock";
                $returnArray['qty'] = -1;
            } elseif (!$backordersAllowed && $qty > $availableQuantity) {
                $returnArray['error'] = "Requested quantity is not available";
                $returnArray['qty'] = $availableQuantity;
            }
        }
        return $returnArray;
    }

    /**
     * error array
     *
     * @param array $productData
     * @param string $errorCode
     * @return array
     */
    public function getErrorArrayForProduct($productData = array(), $errorCode = "product_unavailable", $message = "Product is unavailable") {
        return array(
            "type" => "cart_item_error",
            "code" => $errorCode,
            "id" => (isset($productData['id'])) ? $productData['id'] : null,
            "temp_id" => isset($productData['temp_id']) ? $productData['temp_id'] : null,
            "message" => $message
        );
    }

    /**
     * Converts config values to attribute ID's
     *
     * @param array $attributes
     * @return array $attArray
     */
    public function getArrayOfAttributesForConfigProduct($attributes) {
        $attArray = array();
        foreach ($attributes as $name => $value) {
            $attribute = $this->getAttributeByCode($name);
            $attributeId = $attribute['attribute_id'];
            $attributeValueId = $value;
            $attArray[$attributeId] = $attributeValueId;
        }
        return $attArray;
    }

    /**
     * returns attribute filtered by frontend name
     *
     * @param string $name
     * @return array $a
     */
    public function getAttributeByName($name) {
        $attr = Mage::getModel('eav/entity_attribute')->getCollection()->addFieldToFilter('frontend_label', $name);
        $a = $attr->getData();
        return $a[0];
    }

    /**
     * returns attribute filtered by attribute code
     *
     * @param string $name
     * @return array $a
     */
    public function getAttributeByCode($name) {
        $attr = Mage::getModel('eav/entity_attribute')->getCollection()->addFieldToFilter('attribute_code', $name);
        $a = $attr->getData();
        return $a[0];
    }

    /**
     * returns id of specific attribute value
     *
     * @param string $attributeCode
     * @param string $value
     * @return string
     */
    public function getAttributeValueId($attributeCode, $value) {
        $attribute = Mage::getModel('eav/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
        return $attribute->getSource()->getOptionId($value);
    }

    /**
     * Empty customer's shopping cart
     */
    public function _emptyShoppingCart() {
        foreach ($this->_getQuote()->getAllItems() as $item) {
            $this->_getQuote()->removeItem($item->getId());
        }
    }

    /**
     * remove items from cart that are not specified in JSON body
     */
    public function _removeUspecifiedItemsFromCart($params) {
        // quote item ID array setup
        $q_ids = array();
        if (isset($params['items'])) {
            foreach ($params['items'] as $pItem) {
                if (isset($pItem['id']))
                    $q_ids[] = $pItem['id'];
            }
        }
        $this->log($q_ids, 'Item IDs NOT to be removed from cart');
        foreach ($this->_getQuote()->getAllVisibleItems() as $item) {
            // remove by quote id
            if (!in_array($item->getId(), $q_ids)) {
                $this->log('Removing Item ID: ' . $item->getId(), '_removeUspecifiedItemsFromCart');
                $this->_getQuote()->removeItem($item->getId())->save();
            }
        }
    }

    /**
     * Add Coupon code to cart object
     *
     * @param string $code
     * @param array $errors
     * @return array $errors
     */
    public function addCouponCode($code, $errors) {
        if (isset($code[0]['code']) && isset($code[0]['id'])) {
            $couponCode = $code[0]['code'];

            // generate error array if needed
            $error = array(
                "type" => "coupon_code_error",
                "code" => "invalid_coupon",
                "id" => $code[0]['id'],
                "temp_id" => isset($code[0]['temp_id']) ? $code[0]['temp_id'] : 0,
                "message" => "Invalid coupon code"
            );
            try {
                $codeLength = strlen($couponCode);
                $isCodeLengthValid = $codeLength && $codeLength <= Mage_Checkout_Helper_Cart::COUPON_CODE_MAX_LENGTH;

                $this->_getQuote()->getShippingAddress()->setCollectShippingRates(true);
                $this->_getQuote()->setCouponCode($isCodeLengthValid ? $couponCode : '');
                if ($codeLength) {
                    if (!$isCodeLengthValid || $couponCode != $this->_getQuote()->getCouponCode()) {
                        $errors[] = $error;
                    } else {
                        $this->updateCartEtag();
                    }
                }
            } catch (Exception $e) {
                $this->log(array(
                    "Exception message" => $e->getMessage(),
                    "Coupon code" => $code), 'Adding / updating coupon code');
                $errors[] = $error;
            }
        }
        return $errors;
    }

    /**
     * Add ETag to header of response
     */
    public function _setETagJSONencodeAndRespondonse($data, $responseCode, $numeric = true) {
        $this->_response->setHeader('ETag', $this->getCartEtag());
        $this->_JSONencodeAndRespond($data, $responseCode, $numeric);
    }

    /**
     * Returns products from Cart
     * If Configurable - finds relation attributes, and values
     * if Bundle - finds child products, bundle option ID, option selection ID, as well as QTY of non visible cart product
     *
     * @param array $params
     * @return array $productItems
     */
    public function getProductsFromCart($params = array()) {
        $productItems = array();
        $quote = $this->_getQuote();
        $items = $quote->getAllVisibleItems();

        foreach ($items as $item) {
            $tempArray = array(
                'id' => $item->getItemId(), // product id in quote table
                'temp_id' => $this->getProductTempId($item->getItemId(), $item->getProductId()),
                'product_id' => $item->getProductId(),
                'prices' => array(
                    'original' => $this->getItemPrice('original', $item),
                    'effective' => $this->getItemPrice('effective', $item),
                    'effective_tax_free' => $this->getItemPrice('effective_tax_free', $item),
                    'total' => $this->getItemPrice('total', $item),
                ),
                'quantity' => $item->getQty()
            );

            if ($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
                $configurableAttributeCollection = $item->getProduct()->getTypeInstance()->getConfigurableAttributes();
                $relationAttributes = array();
                foreach ($configurableAttributeCollection as $attribute) {
                    $relationAttributes[] = $attribute->getProductAttribute()->getAttributeCode();
                }

                $simpleId = $item->getOptionByCode('simple_product')->getProduct()->getId();
                $simple = Mage::getModel('catalog/product')->load($simpleId);
                $confArray = array();
                foreach ($relationAttributes as $att) {
                    $confArray[$att] = $simple->getResource()->getAttribute($att)->getFrontend()->getValue($simple);
                }

                $tempArray['configuration'] = array(
                    "child_product_id" => $simpleId
                );
            } else if ($item->getProductType() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
                $bundled_product = new Mage_Catalog_Model_Product();
                $bundled_product->load($item->getProductId());

                $selectionCollection = $bundled_product->getTypeInstance(true)->getSelectionsCollection(
                        $bundled_product->getTypeInstance(true)->getOptionsIds($bundled_product), $bundled_product
                );

                $bundled_items = array();
                foreach ($selectionCollection as $option) {
                    $bundled_items[$option->product_id] = array($option->option_id, $option->selection_id);
                }

                foreach ($item->getChildren() as $child) {
                    $product_id = $child->getData('product_id');
                    if (isset($bundled_items[$product_id])) {
                        $bundleItemsArray[] = array(
                            "option" => $bundled_items[$product_id][0],
                            "selection" => $bundled_items[$product_id][1],
                            "quantity" => $this->getQtyForChildItemInCart($product_id, $item->getItemId(), $this->_getQuote()),
                        );
                    }
                }
                $tempArray['bundle_configuration'] = $bundleItemsArray;
            }

            $productItems[] = $tempArray;
        }
        return $productItems;
    }

    /**
     * get Quote Item price
     *
     * @param string $priceType
     * @param Mage_Sales_Model_Quote_Item $_item
     * @return float
     */
    public function getItemPrice($priceType, $_item) {
        $checkoutHelper = Mage::helper('checkout');
        $taxHelper = Mage::helper('tax');
        switch ($priceType) {
            case 'original':
                return $this->getOriginalProductPrice($_item);
                break;
            case 'effective':
                return $checkoutHelper->getPriceInclTax($_item);
                break;
            case 'effective_tax_free':
                return $_item->getCalculationPrice();
                break;
            case 'total':
                // read setting from admin to show same as on desktop cart
                if ($taxHelper->displayCartPriceExclTax()) {
                    return $_item->getRowTotal();
                } else {
                    return $checkoutHelper->getSubtotalInclTax($_item);
                };
                break;
        }
    }

    /**
     * get product price from simple product
     * this does not apply to bundle products
     *
     * @param Mage_Sales_Model_Quote_Item $item
     * @return float
     */
    public function getOriginalProductPrice($_item) {
        $productId = $_item->getProduct()->getId();
        // get simple product from configurable
        if ($_item->getProduct()->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE && $simpleFromConfig = $_item->getOptionByCode('simple_product')->getProduct())
            $productId = $simpleFromConfig->getId();
        // reload product to get price without rules
        $product = Mage::getModel('catalog/product')->load($productId);

        if ($product->getTypeId() == 'bundle') {
            return Mage::getModel('bundle/product_price')->getTotalPrices($product,'min',1);
        }

        return $product->getPrice();
    }

    /**
     * get temp_id saved into Mage_Sales_Model_Quote_Item info_buyRequest
     *
     * @param int $item_id
     * @param int $productId
     * @return string
     */
    public function getProductTempId($item_id, $productId) {
        $temp_id = null;
        try {
            // using direct sql query to get custom Quote Item Options
            // options are not available using getModel imidietely after saving quote
            $itemOptionTableName = $this->getCoreResource()->getTableName('sales/quote_item_option');
            $sql = "SELECT `value` FROM `" . $itemOptionTableName . "` WHERE `item_id` = :item_id AND `product_id` = :product_id";
            // bind data to prevent SQL injections
            $dataBind = array(
                'item_id' => $item_id,
                'product_id' => $productId
            );
            $buyRequest = $this->directDBRead()->fetchOne($sql, $dataBind);
        } catch (Exception $e) {
            $this->logException($e, 'getProductTempId');
            return null;
        }
        if (isset($buyRequest)) {
            $buyRequestArr = unserialize($buyRequest);
            if (isset($buyRequestArr['temp_id'])) {
                $temp_id = $buyRequestArr['temp_id'];
            };
        };
        return $temp_id;
    }

    /**
     * get coupon code from quote
     *
     * @return string
     */
    public function getCouponCodes() {
        $couponCodes = array();
        if ($cc = $this->_getQuote()->getCouponCode()) {
            $coupon = Mage::getModel('salesrule/coupon')->load($cc, 'code');
            $couponCodes[] = array(
                'id' => $coupon->getRuleId(),
                'code' => $cc,
                'temp_id' => "0"
            );
        }
        return $couponCodes;
    }

    /**
     * Get tax from quote
     *
     * @return bool true || false
     */
    public function isTaxIncluded() {
        $totals = $this->_getQuote()->getTotals();
        return (isset($totals['tax']) && $totals['tax']->getValue()) ? true : false;
    }

    /**
     * Get totals from quote
     *
     * @return array
     */
    public function getTotals() {
        $totals = $this->_getQuote()->getTotals();

        // get tax calculation based on shipping address
        $shipAddress = $this->_getQuote()->getShippingAddress();
        $shippingFromTotals = (isset($totals['shipping']) && $totals['shipping']->getValue()) ? $totals['shipping']->getValue() : 0;
        $shipping = ($shipAddress) ? $this->getShippingPrice($shipAddress) : $shippingFromTotals;
        return array(
            'discount' => (isset($totals['discount']) && $totals['discount']->getValue()) ? $totals['discount']->getValue() : 0,
            'sub_total' => (isset($totals['subtotal']) && $totals['subtotal']->getValue()) ? $totals['subtotal']->getValue() : 0,
            'tax' => (isset($totals['tax']) && $totals['tax']->getValue()) ? $totals['tax']->getValue() : 0,
            'shipping' => ($shipping == 0 && !$shipAddress->getShippingMethod()) ? null : $shipping,
            'grand_total' => (isset($totals['grand_total']) && $totals['grand_total']->getValue()) ? $totals['grand_total']->getValue() : 0,
        );
    }

    /**
     * Function finds difference between product id's in input json, and added products in cart, error products are excluded
     *
     * @param array $params
     * @param array $errors
     * @return array
     */
    public function getMessages($params, $errors) {
        $messages = array();
        if (isset($params['items'])) {
            $itemsIds = array();
            foreach ($params['items'] as $item) {
                array_push($itemsIds, $item['product_id']);
            }
            $countItems = count($params['items']);
            $countCartItems = count($this->_getQuote()->getAllVisibleItems());
            $countErrrorItems = 0;
            foreach ($errors as $e) {
                if ($e['type'] == 'cart_item_error')
                    $countErrrorItems++;
                foreach ($itemsIds as $key => $value) {
                    if ($value == $e['id'])
                        unset($itemsIds[$key]);
                }
            }
            $itemsInCartIds = array();
            if ($countItems - $countErrrorItems < $countCartItems) {
                $items = $this->_getQuote()->getAllVisibleItems();
                foreach ($items as $item) {
                    array_push($itemsInCartIds, $item->getProductId());
                }
                $arrayDiff = array_merge(array_diff($itemsIds, $itemsInCartIds), array_diff($itemsInCartIds, $itemsIds));
                if (count($arrayDiff)) {
                    foreach ($arrayDiff as $arrayDiffID) {
                        $productName = Mage::getModel('catalog/product')->load($arrayDiffID)->getName();
                        $messages[] = array(
                            "type" => "cart_message",
                            "code" => "bonus_product",
                            "message" => "Product '" . $productName . "' added to cart"
                        );
                    }
                }
            }
        }
        return $messages;
    }

    /**
     * get Qty for child Item in cart / quote
     * filter by product ID, Quote object, and parent Quote Item ID
     *
     * @param integer $productId
     * @param integer $quoteItemId
     * @param Mage_Sales_Model_Quote $quote
     * @return integer
     */
    public function getQtyForChildItemInCart($product_id, $quoteParentItemId, $quote) {
        $salesQuoteItem = Mage::getModel('sales/quote_item')->getCollection()
                ->setQuote($quote)
                ->addFieldToFilter('quote_id', $this->_getQuote()->getId())
                ->addFieldToFilter('product_id', $product_id)
                ->addFieldToFilter('parent_item_id', $quoteParentItemId)
                ->getFirstItem();
        return $salesQuoteItem->getQty();
    }

    /**
     * save cart, update session - for create and update Cart
     */
    public function saveCartAndQuote() {
        $this->_getSession()->setCartWasUpdated(true);
        $this->_getCart()->save();
        $this->_getQuote()->setTotalsCollectedFlag(false);
        $this->_getQuote()->collectTotals();
        $this->_getQuote()->save();
    }

    /**
     * get Coupon max length size
     */
    public function getCouponMaxLenght() {
        return self::COUPON_CODE_MAX_LENGTH;
    }

    /**
     * assign quote item id to error
     * used in case where new item has QTY error but quote item id is not known at time of error was generated
     */
    public function assignQuoteItemIdToErrors($data, $params) {
        foreach ($data['_errors'] as $key => $error) {
            if ($error['id'] == null) {
                foreach ($data['items'] as $item) {
                    if ($item['temp_id'] == $error['temp_id'])
                        $data['_errors'][$key]['id'] = $item['id'];
                }
            }
        }
        return $data;
    }

    /**
     * clear unspecified items in input JSON from cart
     * add products to quote
     * add coupon code to quote
     *
     * @param array $params
     * @param array $_errors
     * @return array $_errors
     */
    public function addProductsAndCouponsToQuote($params = array(), $_errors = array()) {
        // remove items from cart that are not specified in input JSON
        $this->_removeUspecifiedItemsFromCart($params);

        // add procucts to cart
        if (isset($params['items'])) {
            foreach ($params['items'] as $item) {
                $_errors = $this->addOrUpdateProductToCart($item, $_errors);
            }
        }

        // add coupon codes
        if (isset($params['coupon_codes'])) {
            $_errors = $this->addCouponCode($params['coupon_codes'], $_errors);
        }

        return $_errors;
    }

    /**
     * Check if we need to display shipping include tax
     *
     * @return bool
     */
    public function displayShippingIncludeTax() {
        return Mage::getSingleton('tax/config')->displayCartShippingInclTax();
    }

    /**
     * Get shipping price from shipping address
     *
     * @param Mage_Sales_Model_Quote_Address $shipping
     * @return float
     */
    public function getShippingPrice($shipAddress) {
        if ($this->displayShippingIncludeTax() && $shipAddress->getShippingInclTax()) {
            return $shipAddress->getShippingInclTax();
        } elseif ($shipAddress->getShippingAmount()) {
            return $shipAddress->getShippingAmount();
        }
        return 0;
    }

    /**
     * Checks item messages added by Mage_CatalogInventory_Model_Observer::checkQuoteItemQty()
     * if item has same message as added by observer, error is added to error array, and item is removed from cart
     * 
     * @param array $errors
     * @return array $errors
     */
    public function checkAndUpdateCartInventory($errors) {
        // get original message text via cataloginventory helper in case it is translated or changed
        $outOfStockMessage = Mage::helper('cataloginventory')->__('This product is currently out of stock.');
        foreach ($this->_getQuote()->getAllVisibleItems() as $item) {
            $itemMessage = $item->getMessage();
            if ($itemMessage == $outOfStockMessage) {
                $errors[] = $this->getErrorArrayForProduct(array('id' => $item->getId()));
                $this->log('Removing Item ID: ' . $item->getId(), 'checkAndUpdateCartInventory');
                $this->_getQuote()->removeItem($item->getId())->save();
            } elseif ($itemMessage) { // if any other message is set, qty not available
                // for all products except bundle we can check available qty
                if ($item->getProduct()->getTypeId() != Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
                    $productAvailableQty = $this->getProductAvailableQtyByQuoteItem($item);
                    $errors[] = $this->getErrorArrayForProduct(array('id' => $item->getId()), 'quantity_changed', "Requested quantity is not available");
                    $this->log('QTY Changed Item ID: ' . $item->getId() . ', old' . $item->getQty() . '/new' . $productAvailableQty, 'checkAndUpdateCartInventory');
                    $item->setQty($productAvailableQty)->save();
                } else {
                    // for budle product we display error and keep product in cart
                    $errors[] = $this->getErrorArrayForProduct(array('id' => $item->getId()), 'product_unavailable', "Requested quantity is not available");
                    $this->log('QTY Unavailable Item ID: ' . $item->getId() . ', qty' . $item->getQty(), 'checkAndUpdateCartInventory');
                }
            }
        }
        return $errors;
    }

    public function getProductAvailableQtyByQuoteItem($item) {
        if ($item->getProduct()->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            $simple_id = $item->getOptionByCode('simple_product')->getProduct()->getId();
            $simple_product = $this->_initProduct($simple_id);
            return Mage::getModel('cataloginventory/stock_item')->loadByProduct($simple_product)->getQty();
        } else {
            $product = $this->_initProduct($item->getProduct()->getId());
            return Mage::getModel('cataloginventory/stock_item')->loadByProduct($simple_product)->getQty();
        }
        return $item->getQty();
    }

}
