<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Model_Attributes extends Mage_Core_Model_Abstract
{
    /**
     * Product entity type id
     * We need to have it as a variable!
     * As of mage_1.8.0 we have a different typeId
     *
     * @var int
     */
    protected $_entityTypeId;

    /**
     * Class constructor
     * We are setting the entityTypeId on construct. In case we want
     * attributes for other entityTypes in the future we have it prepared.
     */
    public function __construct()
    {
        $this->_entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
    }


    /**
     * @return Mage_Catalog_Model_Product
     */
    public function getAttributes()
    {
        $response = $this->_extractResponse($this->_getAttributes());
        return $response;
    }


    /**
     * Return single attribute if found. else false
     * @param null $code
     * @return bool
     */
    public function getAttribute($code=null)
    {
        if(null != $code){
            $response = $this->_extractResponse($this->_getAttribute($code));
            return $response['attributes'][0];
        }
        return false;
    }


    /**
     * Load attribute collection object
     * @return Mage_Eav_Model_Resource_Entity_Attribute_Collection
     */
    private function _getAttributes()
    {
        $attributes = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($this->_entityTypeId)
            ->addStoreLabel(Mage::app()->getStore()->getId())
            ->addSetInfo(false) //no set data needed
            ->getData();

        return $attributes;
    }


    /**
     * load single attribute by code
     * @param null $code
     * @return Mage_Eav_Model_Entity_Attribute
     */
    private function _getAttribute($code=null)
    {
        $attributes = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($this->_entityTypeId)
            ->addStoreLabel(Mage::app()->getStore()->getId())
            ->setCodeFilter($code)
            ->addSetInfo(false) //no set data needed
            ->getData();
        return $attributes;
    }


    /**
     * Extract the correct formatted array from the attribute data
     * @param $attributes
     * @return array|bool
     */
    private function _extractResponse($attributes)
    {
        
        if(!is_array($attributes)){
            //throw Mage::exception('', '');
            return false;
        }

        $response = array('attributes' => array());
        foreach($attributes as $attribute)
        {
            $result = array();
            $result['id'] = (int)$attribute['attribute_id'];
            $result['code'] = $attribute['attribute_code'];
            $result['title'] = strval($attribute['store_label']);
            $result['type'] = $attribute['frontend_input'];

            //Get the optionValues for this attribute
            $result['options'] = $this->_getAttributeOptionValues(
                    $attribute['attribute_id'],
                    $attribute['default_value']
                );

            //we need to push to respond as a json array without index
            array_push($response['attributes'], $result);
        }
        return $response;
    }


    /**
     * Get the option values for the attribute
     * @param null $attributeId
     * @param int  $defaultValue
     *
     * @return array
     */
    private function _getAttributeOptionValues($attributeId=null, $defaultValue = null)
    {
        $optionValues = array();
        //We need to load the attribute to be able to use it get the options
        $attribute = Mage::getModel('eav/entity_attribute')->load($attributeId);
        $options = Mage::getModel('eav/entity_attribute_source_table')
            ->setAttribute($attribute)
            ->getAllOptions(false, false); //getAllOptions($withEmpty = true, $defaultValues = false)

        foreach($options as $key => $option)
        {
            $opt = array();
            $opt['title'] = strval($option['label']);
            $opt['value'] = (int)$option['value'];
            $opt['sort_hint'] = (int)$key;
            $opt['is_default'] = (int)($option['value'] == $defaultValue) ? 1 : 0;

            $optionValues[] = (object)$opt;
        }
        return $optionValues;
    }


}