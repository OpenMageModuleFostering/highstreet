<?php
/**
 * Highstreet_API_module
 *
 * @package     Highstreet_Api
 * @author      Tim Wachter (tim@touchwonders.com)
 * @copyright   Copyright (c) 2014 Touchwonders (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_CheckoutV2 extends Mage_Core_Model_Abstract
{
    private $_errorCodes = array();
    private $_errorMessages = array();
    private $_productIdsNotAdded = array();

	/**
	 * Fills the current session with cart data. This session automatically gets set trough the Magento models, this also inserts the current data in the database e.d.
	 * The array should have the following format:
	 * {"products":[{"sku":"product_sku_1", "qty":5}, {"sku":"product_sku_2", "qty":9}, {"sku":"product_sku_3", "qty":32}, {"sku":"product_sku_4", "qty":3}, {"sku":"product_sku_5", "qty":1}]}
	 * With this format we will lateron be able to extend it for configurable products
	 * 
	 * @param array An array of product SKU's to fill the cart
	 */
	public function fillCartWithProductsAndQuantities($products = false) {
		if (!$products) {
			return;
		}


        Mage::getSingleton('checkout/session')->setQuoteId(null);

    
        $cart = Mage::getModel('checkout/cart');
        $cart->init();
        $cart->truncate(); // Reset cart everytime this function is called
        $quote = $cart->getQuote();
        

        //add products
        $this->getProductsInQuote($quote,$products,false);

        $cart->save();
        $quote->save();
        Mage::getSingleton('checkout/session')->setCartWasUpdated(true);
	}


    /**
     * Can retrieve an existing quote, or create a new (temporary) quote with the given objects
     * Purpose of this method is to return all products that exist in the cart, all shipping information and the totals
     * 
     * @param array An array of product SKU's to fill the cart. Format identical to fillCartWithProductsAndQuantities
     * @param quote_id (optional) The quote_id for which you would like to return the information
     */
    public function getQuoteWithProductsAndQuantities($products = false, $quote_id = -1) {
        if ($products === false && $quote_id == -1) {
            return;
        }

        $response = array();

        Mage::getSingleton('checkout/session')->setQuoteId(null);


        $quote = null;

        if($quote_id == -1) {
            $cart = Mage::getModel('checkout/cart');
            $cart->init();
            $cart->truncate(); // Reset cart everytime this function is called
            $quote = $cart->getQuote();
        } else {
            $quote = Mage::getModel('sales/quote')->load($quote_id);
            if(!$quote->getId()) {
                return null;
            }
        }
        
        if($products) {    
            $this->getProductsInQuote($quote,$products);
        } 
        
        
        //Shipping carries
        $response['selected_shipping_method'] = $this->getSelectedShippingMethod($quote);



        $config = Mage::helper('highstreet_hsapi/config_api');

        //Some stores don't want to return the shipping methods in the cart (that is: when the selected_shipping_method is not set yet) 
        //e.g. PME doesn't want this for performance optimization, everytime the cart is openend the magento backend will connect to Paazl, which is quite expensive. 
        //Therefore they one return shipping info once the user acutally a has selected a shipping method.
        //For PME we also know that shipping is always free, so the rewriter add  'price: 0' to the response.
        if ($config->shippingInCartDisabled() && $response['selected_shipping_method'] === null) {
            $response['shipping'] = array();
        } else {
            $response['shipping'] = array_values($this->getShippingMethods($quote, $response['selected_shipping_method']));
        }



        $response["totals"] = $this->getQuoteTotals($quote);

        $quoteItems = $quote->getAllVisibleItems();

        $responseQuote = array();

        foreach($quoteItems as $quoteItem) {
            $product_hash = $this->_getQuoteItemHash($quoteItem);
            $responseQuote[] = array_merge($this->getProductInQuoteResponse($quoteItem),$this->_getErrorForProduct($product_hash));
        }

        foreach($this->_productIdsNotAdded as $product_hash) {
            $responseQuote[] = array_merge(array("quantity" => 0,"hash" => $product_hash),$this->_getErrorForProduct($product_hash));

        }

        $response["quote"] = $responseQuote;

 
        return $response;

        

    }

    /**
     * Retrieves order information
     * 
     * @param Object Order object
     * @param string Status, overwrites the status if needed
     * @param int Quote_id, fallback quote_id for error when the order object is empty
     * @param bool Overwrite total due, needed for the `sales_order_invoice_pay` because here the total due is still filled in but the order is actually paid fully
     * @return array Object with information about the order
     */

    public function getOrderInformationFromOrderObject ($order, $quote_id, $status = '') {
        // We use 'loadByIdWithoutStore' instead of 'load' because if this event gets triggered by a status update from the admin backend the 'admin storefront' gets set, which doesn't have any quotes. 
        $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($order->getQuoteId()); 
        
        if ($quote === false || $quote->getId() == false || $order === false || $order->getId() == false) {
            return array("error" => 1, "state" => "quote not found", "quote_id" => $quote_id);
        }

        if ($status == '') {
            if ($order->getData('total_due') < 1) {
                $status = 'PAYMENT_SUCCESS';
            } else {
                $status = 'PAYMENT_DUE';
            }
        }

        $response = array();
        
        $response['order_id'] = $quote->getData('reserved_order_id');
        $response['quote_id'] = $quote->getData('entity_id');
        $response['error'] = 0;
        $response['state'] = "success";
        $response['remote_ip'] = $quote->getData('remote_ip');
        $response['currency'] = $quote->getData('quote_currency_code');
        $response['invoice_state'] = $order->getData('status');
        $response['order_status'] = $status;


        // Get totals
        $totals = array();
        $totals['total_due'] = $order->getData('total_due');
        $totals['grand_total'] = $order->getData('grand_total');
        $totals['discount_amount'] = $order->getData('discount_amount');
        $totals['tax_amount'] = $order->getData('tax_amount');
        $totals['shipping_amount'] = $order->getData('shipping_amount');
        $response['totals'] = $totals;

        $response['products'] = array();
        foreach ($quote->getAllVisibleItems() as $product) {
            array_push($response['products'], $this->getProductInQuoteResponse($product));
        }

        return $response;
    }

    /**
     * Gives all the information to make the checkout work from the initial loading of the page.
     */

    public function getStartData() {
        $customerSession = Mage::getSingleton('customer/session');
        $customer = $customerSession->getCustomer();
        $isLoggedIn = $customerSession->isLoggedIn();

        $accountAddressFound = false;

        $billingAddressData = array();
        $shippingAddressData = array();

        if ($isLoggedIn) {
            $defaultBillingAddressId = $customer->getDefaultBilling();
            $defaultShippingAddressId = $customer->getDefaultShipping();

            if ($defaultBillingAddressId > 0) {
                $accountAddressFound = true;

                $billingAddress = $customer->getAddressById($defaultBillingAddressId);
                $billingAddressData = $billingAddress->getData();
                $billingAddressData["email"] = $customer->getEmail();

                if ($defaultBillingAddressId != $defaultShippingAddressId) {
                    $shippingAddress = $customer->getAddressById($defaultShippingAddressId);
                    $shippingAddressData = $shippingAddress->getData();
                }
            }
        }

        $session = Mage::getSingleton('checkout/session');
        $quote = $session->getQuote();

        if (!$isLoggedIn || !$accountAddressFound) {
            $billingAddress = $quote->getBillingAddress();
            $billingAddressData = $billingAddress->getData();
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddressData = $shippingAddress->getData();
        }

        $response = array();

        $response["is_logged_in"] = Mage::getSingleton('customer/session')->isLoggedIn();
        $response["quote_id"] = $quote->getEntityId();
        $response["coupon_code"] = $quote->getCouponCode();

        if ($quote->getItemsCount() > 0) {
            $response["error"] = 0;
        } else {
            $response["error"] = -1;
        }
        
        if ($billingAddressData["firstname"] !== null) {
            $billingAddressResponse = array();

            $billingAddressResponse["email"] = $billingAddressData["email"];
            $billingAddressResponse["firstname"] = $billingAddressData["firstname"];
            $billingAddressResponse["lastname"] = $billingAddressData["lastname"];
            $billingAddressResponse["telephone"] = (string) $billingAddressData["telephone"];
            $billingAddressResponse["street"] = $billingAddress->getStreet();
            $billingAddressResponse["postcode"] = $billingAddressData["postcode"];
            $billingAddressResponse["city"] = $billingAddressData["city"];
            $billingAddressResponse["country_id"] = $billingAddressData["country_id"];

            $response["billing_address"] = $billingAddressResponse;

            $shippingAddressResponse = array();
            if ($shippingAddressData["firstname"] !== null) {
                $shippingAddressResponse["firstname"] = $shippingAddressData["firstname"];
                $shippingAddressResponse["lastname"] = $shippingAddressData["lastname"];
                $shippingAddressResponse["telephone"] = (string) $shippingAddressData["telephone"];
                $shippingAddressResponse["street"] = $shippingAddress->getStreet();
                $shippingAddressResponse["postcode"] = $shippingAddressData["postcode"];
                $shippingAddressResponse["city"] = $shippingAddressData["city"];
                $shippingAddressResponse["country_id"] = $shippingAddressData["country_id"];

                if (!$this->_billingAndShippingAddressesAreTheSame($response["billing_address"], $shippingAddressResponse)) {
                    $response["shipping_address"] = $shippingAddressResponse;
                } else {
                    $response["shipping_address"] = array();
                }
            } else {
                $response["shipping_address"] = array();
            }
        } else {
            $response["billing_address"] = array();
            $response["shipping_address"] = array();
        }

        return $response;
    }



    //Helpers below

    private function getProductsInQuote($quote,$products = null,$capAmount = true) {
         $responseQuote = array();
        


      
        //loop through the requested products
        foreach ($products as $key => $value) {
            $product_id = $value["product_id"];
            $product_hash = $this->_getQuoteItemRequestHash($value);

            if (empty($product_id)) {
                continue;
            }


            $errorMessage = null;

            try {
                $product = $this->loadProduct($product_id);
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $product = null;
            }

            if ($product === null || !$product->getId()) {
                $this->_reportErrorForProduct($product_hash,400,$errorMessage);
                continue;
            }

            //input variables
            $requestedQuantity = ($product->getStatus() != Mage_Catalog_Model_Product_Status::STATUS_ENABLED) ? 0 : $value["quantity"];
            $configuration = array_key_exists("configuration", $value) ? $value["configuration"] : null;
            $bundle_selections = array_key_exists("bundle_selections", $value) ? $value["bundle_selections"] : null;
            $simple_product = null;
            $quoteItem = null;

            $quote_item_additional_info = null; //additional info that should be stored in the quote item

            if($product->getTypeId() == 'simple') {
                $simple_product = $product;
            } else if($product->getTypeId() == 'configurable') {
                $simple_product = $this->loadProduct($value['simple_product_id']);

                //if 'alwaysAddSimpleProductsToCart' is set, then we add the simple product of a configurable product to the cart
                $config = Mage::helper('highstreet_hsapi/config_api');
                if($config->alwaysAddSimpleProductsToCart()) {
                    $quote_item_additional_info = array("parent_product_id" => $product_id);
                    $product = $simple_product;
                }
            }

            if($simple_product !== null) {
                $quoteItem = $quote->getItemByProduct($simple_product);
            }

                
            //Check for inventory (only for simple and configurable)

            $actualQuantity = 0;
            if($simple_product !== null && $capAmount) {
                    
                    $itemInventory = Mage::getModel('cataloginventory/stock_item')->loadByProduct($simple_product);

                    
                    $actualQuantity = $requestedQuantity; //actual qty is what we are going to add

                    //adjust actual quantity if we are requesting more than in stock
                    $availableQuantity = $itemInventory->getQty();
                    $isInStock = $itemInventory->getIsInStock();
                    $isStockManaged = $itemInventory->getManageStock();
                    $backordersAllowed = $itemInventory->getBackorders();


                    if($isStockManaged) {

                        if(!$isInStock) {
                            $this->_reportErrorForProduct($product_hash,101,"Product is not in stock");
                            $actualQuantity = 0;
                        } else {
                            //in stock, but should we cap it?
                            if(!$backordersAllowed && $requestedQuantity > $availableQuantity) {
                                $actualQuantity = $availableQuantity; //cap
                                $this->_reportErrorForProduct($product_hash,102,"Requested quantity is not available, added ".(int)$actualQuantity." instead of ".$requestedQuantity ." products with id ".$value["id"]." to the cart",false);
                                //product can be added, but with a lower quantity     
                                //Note: even though the actualQuantity might be set to 0, we still do not return a 101, because a qty of 0 does not necessarily make a product out of stock
                                //"Qty for Item's Status to Become Out of Stock" might be a negative integer
                            }

                        }

                    }

            } else {
                $actualQuantity = $requestedQuantity;

            }




            //add to cart


            try {


                if($quoteItem) {   //adjust existing entry

                    $quoteItem->setQty($actualQuantity);
                } else { //or add new entry (but of course only when qty > 0)
                    
                    if($actualQuantity > 0) { //do this check because the app might request a quantity of 0 for a product. If you call the function below with $actualQuantity = 0, it will still add one product to the cart


                        //simple
                        if($product->getTypeId() == 'simple') {
                            $quoteItem = $quote->addProduct($product,new Varien_Object(array("qty" => $actualQuantity)));    
                        }

                        if($product->getTypeId() == 'configurable') {

                            $requestConfiguration = array();
                            foreach($configuration as $configuration_option) {
                                $attributeCode = $configuration_option['attribute_code'];
                                $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', $attributeCode);

                                $requestConfiguration[$attribute->getId()] = $configuration_option['attribute_value'];
                            }

                            $configurations = array('super_attribute' => $requestConfiguration);
                            $options = array_merge(array("qty" => $actualQuantity),$configurations);
                            $quoteItem = $quote->addProduct($product,new Varien_Object($options));    

                        }

                        if($product->getTypeId() == 'bundle') {
                            $bundle_option = array();
                            $bundle_option_qty = array();

                            foreach($bundle_selections as $bundle_selection) {
                                $option_id = $bundle_selection["option_id"];
                                $bundle_option[$option_id] = $bundle_selection["selection_ids"];
                                if($bundle_selection["quantity"])
                                    $bundle_option_qty[$option_id] = $bundle_selection["quantity"];

                            }

                            $options = array("qty" => $actualQuantity, "bundle_option" => $bundle_option,"bundle_option_qty" => $bundle_option_qty );


                            $quoteItem = $quote->addProduct($product,new Varien_Object($options));    
                        }

                        $parentQuoteItem = $quoteItem->getParentItem();
                        if($parentQuoteItem) {
                            $quoteItem = $parentQuoteItem;
                        }

                        if($quote_item_additional_info) {
                            $quoteItem->setAdditionalData(json_encode($quote_item_additional_info));
                        }


                    } else {
                        if (!in_array($product_hash,$this->_productIdsNotAdded)) {
                            $this->_productIdsNotAdded[] = $product_hash;
                        }
                    } 
                }

                

            
            } catch (Exception $e) {
                $this->_reportErrorForProduct($product_hash,400,$e->getMessage());
                

            }

        

        }
        

    }

    private function _reportErrorForProduct($product_hash,$errorCode,$errorMessage,$notAdded = true) {
        $this->_errorCodes[$product_hash] = $errorCode;
        $this->_errorMessages[$product_hash] = $errorMessage;

        if($notAdded) { 
            $this->_productIdsNotAdded[] = $product_hash;
        }
    }

    private function _getErrorForProduct($product_hash) {
        $response = array();
        $response["errorCode"] = 0;
        $response["errorMessage"] = null;

        if (array_key_exists($product_hash, $this->_errorCodes)) {
            $response["errorCode"] = $this->_errorCodes[$product_hash];
        }

        if (array_key_exists($product_hash, $this->_errorMessages)) {
            $response["errorMessage"] = $this->_errorMessages[$product_hash];
        }

        return $response;
    }



    private function getProductInQuoteResponse($quoteItem = null) {

        $product = $quoteItem->getProduct();
        $parent_product_id = $this->_getQuoteItemParentProductId($quoteItem);


        $productInQuote = array();
        $productInQuote["product_id"] = $parent_product_id ? $parent_product_id : $product->getId();

        $productInQuote["name"] = $product->getName();
        $productInQuote["sku"] = $product->getSku();

        

        $quantity = $quoteItem ? $quoteItem->getQty() : 0;

        $productInQuote["finalPrice"] = $quoteItem->getPriceInclTax();
        $productInQuote["tax"] = $quoteItem->getPriceInclTax() - $quoteItem->getPrice();

        //The custom price is set by the Amasty - Auto Add Promo Items  extension. The extra free product has a custom price, and if it is set we should use that price.
        //This price is already used (automatically) in the total calculations
        if($quoteItem->getCustomPrice() !== null) {
            $productInQuote["finalPrice"] = $quoteItem->getCustomPrice();
        }


        $productInQuote["quantity"] = $quantity;
        

        $productInQuote["hash"] = $this->_getQuoteItemHash($quoteItem);

        
        return $productInQuote;
    }

    private function _getQuoteItemHash($quoteItem) {
        $product = $quoteItem->getProduct();

        $hash = null;

        $parent_product_id = $this->_getQuoteItemParentProductId($quoteItem);
        

        $type = $product->getTypeId();

        if($type == 'simple') {
            if($parent_product_id == null) {
                $hash = $product->getId();
            } else {
                //actually a configurable product, but the child was added instead of the parent
                $hash = $parent_product_id . ":" . $product->getId();
            }
        }

        if($type == 'configurable') {
            $children = $quoteItem->getChildren();
            $childQuoteItem = current($children);
            if ($childQuoteItem)
                $hash = $product->getId() . ":" . $childQuoteItem->getProduct()->getId();
            else
                $hash = $product->getId();

        }

        if($type == 'bundle') {
            $hash = $product->getId().":";

            $options = $product->getTypeInstance(true)->getOrderOptions($product);

            $bundle_option = $options["info_buyRequest"]["bundle_option"];
            ksort($bundle_option);

            
            $hashElements = array();
        
            foreach($bundle_option as $option_id => $selection_ids) {

                if (!is_array($selection_ids))
                    $selection_ids = array($selection_ids);

        
                $quantity = round($options["info_buyRequest"]["bundle_option_qty"][$option_id]);

                sort($selection_ids);
                $selection_ids_string = implode(",",$selection_ids);
                $hashElements[] = "($option_id,[$selection_ids_string],$quantity)";

            }

            $hash .= '['.implode(",",$hashElements).']';
            


        }

        if($parent_product_id) {
            $type = 'configurable'; //the product is actually a configurable product
        }

        return $type."-".$hash;


    }

    private function _compareBundleSelection($a, $b) {
               if ($a["option_id"] == $b["option_id"]) {
                    return 0;
                }
                return ($a["option_id"] < $b["option_id"]) ? -1 : 1;
    }

    private function _getQuoteItemRequestHash($quoteItemRequest) {

        $hash = null;
        $type = 'simple';
        if(array_key_exists('simple_product_id', $quoteItemRequest)) {
            $type = 'configurable';
        }
        if(array_key_exists('bundle_selections', $quoteItemRequest)) {
            $type = 'bundle';
        }

        if($type == 'simple') {
            $hash = $quoteItemRequest['product_id'];
        }

        if($type == 'configurable') {
            $hash = $quoteItemRequest['product_id'] . ":" . $quoteItemRequest['simple_product_id'];
        }

        if($type == 'bundle') {
            $hash = $quoteItemRequest['product_id'].":";

            
            $hashElements = array();

            $selections = $quoteItemRequest["bundle_selections"];


            usort($selections,array($this,"_compareBundleSelection"));


            foreach($selections as $bundle_selection) {
                $option_id = $bundle_selection["option_id"];
                $selection_ids = $bundle_selection["selection_ids"];
                sort($selection_ids);
                $quantity = $bundle_selection["quantity"];

                $selection_ids_string = implode(",",$selection_ids);
                $hashElements[] = "($option_id,[$selection_ids_string],$quantity)";
            }


            $hash .= '['.implode(",",$hashElements).']';
            


        }
        
        return $type."-".$hash;


    }

    private function _getQuoteItemParentProductId($quoteItem) {
        $additionalInfo = $quoteItem->getAdditionalData();
        if($additionalInfo) {
            $json = json_decode($additionalInfo,true);
            return $json['parent_product_id'];
        }
        return null;
    }

    private function getQuoteTotals($quote) {


        $quote->save()->collectTotals(); //required to fetch the totals
        
        //Totals
        $totals = $quote->getTotals(); //Total object
        $subtotal = $totals["subtotal"]->getValue(); //Subtotal value
        $grandtotal = $totals["grand_total"]->getValue(); //Grandtotal value
        
        $discount = 0;
        if(isset($totals['discount']) && $totals['discount']->getValue()) {
            $discount = $totals['discount']->getValue(); //Discount value if applied
        } 
        $tax = 0;
        if(isset($totals['tax']) && $totals['tax']->getValue()) {
            $tax = $totals['tax']->getValue(); //Tax value if present
        } 
        
        $totalItemsInCart = 0;
        foreach($quote->getAllVisibleItems() as $quoteItem) {
             $totalItemsInCart++;
        }


        $responseTotals = array();
        $responseTotals["totalItemsInCart"] = $totalItemsInCart;
        $responseTotals["subtotal"] = $subtotal;
        $responseTotals["grandtotal"] = $grandtotal;
        $responseTotals["discount"] = $discount;
        $responseTotals["tax"] = $tax;

        return $responseTotals;
    }

    

    private function getSelectedShippingMethod($quote) {
        $quoteShippingAddress = $quote->getShippingAddress();
        $quoteShippingAddress->collectTotals(); //to make sure all available shipping methods are listed

        $quoteShippingAddress->collectShippingRates()->save(); //collect the rates

        $chosenShippingMethod = $quoteShippingAddress->getShippingMethod();

        if ($chosenShippingMethod === "") {
            $chosenShippingMethod = null;
        }

        return $chosenShippingMethod;
    }

    private function getShippingMethods($quote, $selectedShippingMethod) { 
        $responseCarriers = array();
        
        $quoteShippingAddress = $quote->getShippingAddress();
        $quoteShippingAddress->collectTotals(); //to make sure all available shipping methods are listed

        $quoteShippingAddress->collectShippingRates()->save(); //collect the rates
        $groupedRates = $quoteShippingAddress->getGroupedAllShippingRates();

        foreach ($groupedRates as $carrierCode => $rates ) {
            foreach ($rates as $rate) {
                $price = $rate->getPrice();
                if ($rate->getCode() == $selectedShippingMethod) {
                    $quoteShippingAddress->setShippingMethod($selectedShippingMethod);
                    $quote->collectTotals()->save();

                    $price = $quoteShippingAddress->getShippingInclTax();
                }

                $responseRate = array();
                $responseRate["carrier"] =  $rate->getCarrier(); 
                $responseRate["carrierTitle"] = $rate->getCarrierTitle(); 
                $responseRate["carrierCode"] = $rate->getCode(); 
            
                $responseRate["method"] = $rate->getMethod();
                $responseRate["methodTitle"] = $rate->getMethodTitle();
                $responseRate["methodDescription"] = $rate->getMethodDescription();
                $responseRate["price"] = $price;
                $responseCarriers[] = $responseRate;
            }
        }

        return $responseCarriers;
    }

    private function loadProduct($productId = null) {
        if(!$productId)
            return null;

        $productModel = Mage::getModel('catalog/product');
        $product = $productModel->load($productId);
        if (!$product->getId()) 
            return null; //product does not exist

        return $product;
                
    } 

    private function _getParentProduct($product) {
        $config = Mage::helper('highstreet_hsapi/config_api');
        if ($config->alwaysAddSimpleProductsToCart()) {
            return null;
        }
        
        $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
        $parent = null;
        if(isset($parentIds[0])){
            $parent = Mage::getModel('catalog/product')->load($parentIds[0]);
        }

        if($parent->getId() === null)
            return null;

        return $parent;
    }

    /**
     * Convenience method, compares 2 formatted address arrays
     */
    private function _billingAndShippingAddressesAreTheSame($billingAddressArray = array(), $shippingAddressArray = array()) {
        if (count($billingAddressArray) == 0 || count($shippingAddressArray) == 0) {
            return true;
        }

        return (($billingAddressArray["firstname"] == $shippingAddressArray["firstname"]) &&
            ($billingAddressArray["lastname"] == $shippingAddressArray["lastname"]) &&
            ($billingAddressArray["telephone"] == $shippingAddressArray["telephone"]) &&
            ($billingAddressArray["street"] == $shippingAddressArray["street"]) &&
            ($billingAddressArray["postcode"] == $shippingAddressArray["postcode"]) &&
            ($billingAddressArray["city"] == $shippingAddressArray["city"]));
    }


}
