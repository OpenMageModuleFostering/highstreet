<?php

class Highstreet_Hsapi_Model_Observer {

   public function __construct()
   {
   }
   
   public function mergeQuote($observer)
   {
        $event = $observer->getEvent();
		$quote = $event->getQuote();

		foreach ($quote->getAllItems() as $item) {
     	   $quote->removeItem($item->getId());
     	}

    }


	public function salesOrderInvoicePay(Varien_Event_Observer $observer) {
                $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
		$order = $observer->getEvent()->getInvoice()->getOrder();
		$this->_communicateOrderEvent($order, '');
	}

	
	public function salesOrderInvoiceCancel(Varien_Event_Observer $observer) {
		$checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
                $order = $observer->getEvent()->getInvoice()->getOrder();
                $this->_communicateOrderEvent($order, 'PAYMENT_CANCELED');	
	}

	
	private function _communicateOrderEvent($order, $status = '') {	
                if ($order->getQuoteId() > 0) {
                        $encryptionHelper = Mage::helper('highstreet_hsapi/encryption');
                        $quoteIdHash = $encryptionHelper->hashQuoteId($order->getQuoteId());

                        $isHighstreetOrder = false;
                        foreach ($order->getStatusHistoryCollection(true) as $comment) {
                            if (strstr($comment->getData('comment'), $quoteIdHash) !== false) {
                                $isHighstreetOrder = true;
                                break;
                            }
                        }

                        $configHelper = Mage::helper('highstreet_hsapi/config_api');
                        $middleWareUrl = $configHelper->middlewareUrl();

                        if ($isHighstreetOrder && $middleWareUrl !== NULL) {
                                $checkoutModel = Mage::getModel('highstreet_hsapi/checkoutV2');
                                $data = $checkoutModel->getOrderInformationFromOrderObject($order, $order->getQuoteId(), $status);

                                $ch = curl_init($middleWareUrl . "/orders/" . $order->getQuoteId());
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Time CURL takes to wait for a connection to our server, 0 is indefinitely
                                curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Maximum time CURL takes to execute 
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK));
                                $output = curl_exec($ch);
                        }
                }
        }
}
