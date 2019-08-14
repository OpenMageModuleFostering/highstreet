<?php
/**
 * Highstreet_API_module
 *
 * @package     Highstreet_Api
 * @author      Tim Wachter (tim@touchwonders.com)
 * @copyright   Copyright (c) 2014 Touchwonders (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_Checkout extends Mage_Core_Model_Abstract
{
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


		$cart = Mage::getModel('checkout/cart');
        $cart->init();
        $cart->truncate(); // Reset cart everytime this function is called

        foreach ($products as $key => $value) {
        	if (empty($value["id"])) {
        		continue;
        	}

            try {
                $product = $this->loadProduct($value["id"]);
            } catch (Exception $e) {
                continue;
            }


        	if (empty($value["quantity"]) || !is_numeric($value["quantity"]) || $value["quantity"] === 0) {
                continue; //skip this product
        	}

            $quantity = $value["quantity"];
            
            try {

                $parent = $this->_getParentProduct($product);
                if($parent) {
                    $configurations = $this->_getConfiguration($product,$parent);
                    $configurations = array('super_attribute' => $configurations);
                    $options = array_merge(array("qty" => $quantity),$configurations);
                    $cart->addProduct($parent,$options);    
                } else {
                    $cart->addProduct($product, array("qty" => $quantity));
                }


                




            } catch (Exception $e) {
                continue;
            }
        }
        
        $cart->save();
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
            
        $response["quote"] = array_values($this->getProductsInQuote($quote,$products));

        $this->addAddressToQuoteIfNeeded($quote);
        //Shipping carries
        $response['selected_shipping_method'] = $this->getSelectedShippingMethod($quote);
        $response['shipping'] = array_values($this->getShippingMethods($quote, $response['selected_shipping_method']));
        $response["totals"] = $this->getQuoteTotals($quote);

 
        return $response;

        

    }


    //Helpers below

    private function getProductsInQuote($quote,$products = null) {
         $responseQuote = array();
        


        $quoteItems = array();
        foreach($quote->getAllItems() as $quoteItem) {
            $quoteItems[$quoteItem->getId()] = $quoteItem;
        }



        //loop through the requested products
        foreach ($products as $key => $value) {
            if (empty($value["id"])) {
                continue;
            }

            try {
                $product = $this->loadProduct($value["id"]);
            } catch (Exception $e) {
                $productInQuote["errorMessage"] = $e->getMessage();
                $productInQuote["errorCode"] = 400; //something else went wrong
                $productInQuote["quantity"] = 0;
            }

            $parent = $this->_getParentProduct($product);
            if($parent)
                $configurations = $this->_getConfiguration($product,$parent);



            $requestedQuantity = $value["quantity"];

            


            //Set up default response
            $productInQuote = array();
            $productInQuote["product_id"] = $value["id"];
            $productInQuote["errorCode"] = 0;
            $productInQuote["errorMessage"] = null;
            


            try {

                if (!$product) {
                    //product does not exist
                    
                    $productInQuote["errorCode"] = 400;
                    $productInQuote["errorMessage"] = "The requested product does not exist";
                    $productInQuote["quantity"] = 0;


                } else {

                    
                    $itemInventory = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                    $quoteItem = $quote->getItemByProduct($product);


                    $actualQuantity = $requestedQuantity; //actual qty is what we are going to add

                    //adjust actual quantity if we are requesting more than in stock
                    $availableQuantity = $itemInventory->getQty();
                    $isInStock = $itemInventory->getIsInStock();
                    $isStockManaged = $itemInventory->getManageStock();
                    $backordersAllowed = $itemInventory->getBackorders();


                    if($isStockManaged) {

                        if(!$isInStock) {
                            $productInQuote["errorMessage"] = "Product is not in stock";
                            $productInQuote["errorCode"] = 101; //product cannot be added
                            $actualQuantity = 0;
                        } else {
                            //in stock, but should we cap it?
                            if(!$backordersAllowed && $requestedQuantity > $availableQuantity) {
                                $actualQuantity = $availableQuantity; //cap
                                $productInQuote["errorMessage"] = "Requested quantity is not available, added ".(int)$actualQuantity." instead of ".$requestedQuantity ." products with id ".$value["id"]." to the cart";
                                $productInQuote["errorCode"] = 102; //product can be added, but with a lower quantity     
                                //Note: even though the actualQuantity might be set to 0, we still do not return a 101, because a qty of 0 does not necessarily make a product out of stock
                                //"Qty for Item's Status to Become Out of Stock" might be a negative integer
                            }

                        }

                    }

                    if($quoteItem) {   //adjust existing entry
                        $quoteItem->setQty($actualQuantity);
                    } else { //or add new entry (but of course only when qty > 0)
                        if($actualQuantity > 0) { //do this check because the app might request a quantity of 0 for a product. If you call the function below with $actualQuantity = 0, it will still add one product to the cart
                            if($parent) { 
                                $configurations = array('super_attribute' => $configurations);
                                $options = array_merge(array("qty" => $actualQuantity),$configurations);


                                $quoteItem = $quote->addProduct($parent,new Varien_Object($options));    
                            } else {
                                $quoteItem = $quote->addProduct($product,new Varien_Object(array("qty" => $actualQuantity)));    
                            }

                            //response output
                            $productInQuote = array_merge($this->getProductInQuoteResponse($quoteItem,$product),$productInQuote);
                        } else {
                            $productInQuote["quantity"] = 0;
                        }
                    }
                    

                    if($quoteItem)
                        unset($quoteItems[$quoteItem->getId()]);



                }
          
            
            } catch (Exception $e) {
                $productInQuote["errorMessage"] = $e->getMessage();
                $productInQuote["errorCode"] = 400; //something else went wrong
                $productInQuote["quantity"] = 0;

            }



            $responseQuote[] = $productInQuote;


        }
        
        foreach($quoteItems as $quoteItem) {
            if(count($quoteItem->getChildren()) > 0)
                 continue;

            $productInQuote = $this->getProductInQuoteResponse($quoteItem);
            $responseQuote[] = $productInQuote;                        
        }


        return $responseQuote;
    }

    private function getProductInQuoteResponse($quoteItem = null, $product = null) {
        if(!$quoteItem && !$product)
            return null;
        if(!$product)
            $product = $quoteItem->getProduct();


        $productInQuote = array();
        $productInQuote["product_id"] = $product->getId();

        if($quoteItem->getParentItem()) {
            $quoteItem = $quoteItem->getParentItem();
            $product = $quoteItem->getProduct(); 
        }

        $quantity = $quoteItem ? $quoteItem->getQty() : 0;

        $productInQuote["finalPrice"] = $quantity > 0 ? $product->getFinalPrice($quantity) : $product->getFinalPrice();
        $productInQuote["quantity"] = $quantity;
        
        return $productInQuote;
    }

    private function getQuoteTotals($quote) {


        $quote->collectTotals()->save(); //required to fetch the totals
        
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
        foreach($quote->getAllItems() as $quoteItem) {
            if(count($quoteItem->getChildren()) > 0)
                 continue;
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

    private function addAddressToQuoteIfNeeded(&$quote) {
        $address = $quote->getShippingAddress();

        if(!$address->getCountryId()) {
            $address->setCity("Utrecht") 
                    ->setCountryId("NL") 
                    ->setPostcode("3512NT") 
                    ->setCollectShippingRates(true); 
            $quote->setShippingAddress($address);
        }
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
        $config = Mage::helper('highstreet_hsapi/config');
        if ($config->alwaysAddSimpleProductsToCart()) {
            return null;
        }
        
        $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
        $parent = null;
        if(isset($parentIds[0])){
            $parent = Mage::getModel('catalog/product')->load($parentIds[0]);
        }
        return $parent;
    }

    private function _getConfiguration($product,$parent) {
        $configurations = array();
        $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($parent);

                    //build the configuration_attributes array
        $configurableAttributes = $conf->getConfigurableAttributesAsArray($parent);

        foreach($configurableAttributes as $attribute) {

            $method = 'get' . uc_words($attribute['attribute_code'], '');
            $attribute_value = $product->$method();
            $attribute_id = $attribute['attribute_id'];
            $configurations[$attribute_id] = $attribute_value;

        }

        return $configurations;
    }


}