<?php

class Highstreet_Hsapi_Model_Observer {
   
    protected $_orderCommentTest = "Order made via the Highstreet app. Data identifier: ";

    /**
     * Listener for the sales_quote_merge_before event
     * Makes sure nothing strange happens when one of out customers logs in
     */
    public function mergeQuote($observer) {
        $event = $observer->getEvent();
		$quote = $event->getQuote();

		foreach ($quote->getAllItems() as $item) {
     	   $quote->removeItem($item->getId());
     	}
    }

    /**
     * Listens for the sales_order_invoice_pay event
     * Communicates the invoice to the Highstreet Middleware if needed
     */
	public function salesOrderInvoicePay(Varien_Event_Observer $observer) {
    	$order = $observer->getEvent()->getInvoice()->getOrder();
		$this->_communicateOrderEvent($order, '');
	}

	/**
     * Listens for the sales_order_invoice_cancel event
     * Communicates the invoice to the Highstreet Middleware with status PAYMENT_CANCELED if needed
     */
	public function salesOrderInvoiceCancel(Varien_Event_Observer $observer) {
        $order = $observer->getEvent()->getInvoice()->getOrder();
        $this->_communicateOrderEvent($order, 'PAYMENT_CANCELED');
	}

    /**
     * Listens for the sales_order_place_after event
     * This event gets triggered before a user enters the off-site payment
     * In this event we, if needed, add a comment to the order so we can later identify the order as ours
     */
    public function salesOrderPlaceAfter($data) {
        if (Mage::getSingleton('checkout/session')->getHsTid() !== null) { 
            try {
                $order = $data['order'];

                $order->addStatusHistoryComment($this->_orderCommentTest . Mage::getSingleton('checkout/session')->getHsTid())
                        ->setIsVisibleOnFront(false)
                        ->setIsCustomerNotified(false);

                //Override the store (if filled in)
                $configHelper = Mage::helper('highstreet_hsapi/config_api');
                $storeId = $configHelper->storeOverride();
                if($storeId && $storeId != -1) {
                    $order->setStoreId($storeId);
                }
                $order->save();
            } catch (Exception $e) {}


        }
    }
	
    /**
     * Private function used to communicate orders to the Highstreet middleware
     */
	protected function _communicateOrderEvent($order, $status = '') {	
        if ($order->getQuoteId() > 0) {

            // Check if this order identifies as a HS order trough the earlier added comment
            $relevantComment = null;
            foreach ($order->getStatusHistoryCollection(true) as $comment) {
                if (strstr($comment->getData('comment'), $this->_orderCommentTest) !== false) {
                    $relevantComment = $comment->getData('comment');
                    break;
                }
            }

            $configHelper = Mage::helper('highstreet_hsapi/config_api');
            $middleWareUrl = $configHelper->middlewareUrl();

            if ($relevantComment !== null && $middleWareUrl !== null) {
                $tid = str_replace($this->_orderCommentTest, "", $relevantComment); // Extract tracking ID
                
                $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
                $data = $checkoutModel->getOrderInformationFromOrderObject($order, $order->getQuoteId(), $status); // Get relevant information from the order

                // Communicate it with the middleware
                $ch = curl_init($middleWareUrl . "/orders/" . $tid);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Time CURL takes to wait for a connection to our server, 0 is indefinitely
                curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Maximum time CURL takes to execute 
                if (version_compare(PHP_VERSION, '5.3.3', '<')) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK));
                }
                $output = curl_exec($ch);
            }
        }
    }
}
