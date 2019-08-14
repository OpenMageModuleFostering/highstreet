<?php
class Highstreet_Hsapi_Model_Observer extends Varien_Event_Observer
{
   public function __construct()
   {
   }
   public function mergeQuote($observer)
   {
        $event = $observer->getEvent();
		$quote = $event->getQuote();
		exec("echo Quote".$quote->getId()." > /tmp/bla");

		foreach ($quote->getAllItems() as $item) {
     	   $quote->removeItem($item->getId());
     	}

    }
		

}

?>