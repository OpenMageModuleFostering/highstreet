<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Return single request param. This returns the key as we have rest url
     * @param $params
     *
     * @return bool|int|string
     */
    public function extractRequestParam($params)
    {
        if($this->requestHasParams($params)){
            foreach($params as $key => $value){
                return $key;
            }
        }
        return false;
    }

    /**
     * Check if request has a param && if param is an array bigger then 0
     * @param $params
     * @return bool
     */
    public function requestHasParams($params)
    {
        if(is_array($params) && count($params) > 0)
        {
            return true;
        }
        return false;
    }


    /**
     * Returns the header for an image content type. Via the given source string param given it determines what the content type should be. 
     * 
     * @param string src url of the image
     * @return string of the image type
     */
    public function imageHeaderStringForImage($src = false) {
        if (!$src) {
            return 'image';
        }

        $urlComponents = explode(".", $src);
        $extension = strtolower($urlComponents[count($urlComponents)-1]);

        if ($extension == 'jpeg' || $extension == 'jpg') {
            return 'image/jpeg';
        } else if ($extension == 'png') {
            return 'image/png';
        } else if ($extension == 'gif') {
            return 'image/gif';
        }
    }

    /**
     * Saves a search query to the search suggestion table
     * 
     * @param string searchString, the search query string
     * @param integer categoryId, the categoryId. When not filled in it will use the root category id
     */
    public function saveSearchSuggestion($searchString = false, $categoryId) {
        if (!$searchString) {
            return;
        }

        $catalogSearchSaveModel = Mage::getModel('catalogsearch/query');
        try {
            $catalogSearchSaveModel->setStoreId(Mage::app()->getStore()->getId());
            $catalogSearchSaveModel->loadByQueryText($searchString);
            
            $searchTermId = $catalogSearchSaveModel->getId();
            if ($searchTermId) { // Search term already existed, update it
                $popularity = $catalogSearchSaveModel->getData("popularity");
                $catalogSearchSaveModel->setData("popularity", $popularity+1);

                $catalogSearchSaveModel->save();
            } else { // Search term did not yet exists, make a new one
                
                // Check if there is an category ID set 
                if (empty($categoryId) || !is_numeric($categoryId)) {
                    $store = Mage::getModel('core/store')->load(Mage_Core_Model_App::DISTRO_STORE_ID);
                    $categoryId = $store->getRootCategoryId();
                }
                
                $data = array("query_text" => $searchString, "popularity" => 1);

                if ($this->searchSuggestionTableHasHSAPIColumn()) {
                    $data["hsapi_category_id"] = $categoryId;
                }

                $catalogSearchSaveModel->addData($data);
                $catalogSearchSaveModel->setIsProcessed(1);
                $catalogSearchSaveModel->save();
            }

        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getSession()->addException($e,
                Mage::helper('catalog')->__('An error occurred while saving the search query.')
            );
        }
    }

    /**
     * Returns a BOOL wether the "catalogsearch/query" table has the HSAPI category column
     * 
     * @return bool 
     */
    public function searchSuggestionTableHasHSAPIColumn() {
        try {
            $catalogSearchReadModel = Mage::getModel('catalogsearch/query')->getCollection();
            $catalogSearchReadModel->addFieldToSelect('hsapi_category_id');
            $catalogSearchReadModel->getSelect()->limit(1);
            $catalogSearchReadModel->getData();
            return TRUE;
        } catch (Exception $e) {
            return FALSE;
        }

        return FALSE;
    }

}



