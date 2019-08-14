<?php

class Highstreet_Hsapi_Model_System_Config_Stores {
    public function toOptionArray()
    {
        $stores = Mage::app()->getStores();
        $options = array();
        $options[] = array('value' => "-1",'label' => Mage::helper('highstreet_hsapi')->__('Current store'));
        foreach ($stores as $store) {
            $storeCode = $store->getCode();
            $storeId = $store->getId();

            $options[] = array('value' => $storeId,'label' => $storeCode);

        }

        return $options;
    }
}