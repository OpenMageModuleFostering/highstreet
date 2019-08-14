<?php

class Highstreet_Hsapi_Model_CartObserver {

    /**
     * Listener for the cart or quote changes
     * updates session etag with hashed timestamp
     */
    public function cartEtagUpdate($observer) {
        Mage::helper('highstreet_hsapi/config_cart')->updateCartEtag();
        return;
    }

}
