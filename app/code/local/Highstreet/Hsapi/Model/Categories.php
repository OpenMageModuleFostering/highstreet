<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2015 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Model_Categories extends Mage_Core_Model_Abstract
{
    const CATEGORY_MEDIA_PATH = "/media/catalog/category/";

    /**
     * Gets the entire category tree. Can be filtered for a specific category with param categoryId
     *
     * @param integer categoryId, a categoryId which will filter the tree
     * @param integer maxDepth, Maxmimum depth of category tree to be gotten
     * @param integer currentDepth, Current depth
     * @return array Array of categories
     */
    public function getCategory($categoryId = null, $maxDepth = -1, $currentDepth = 0) {
        if ($categoryId === null || $categoryId === "tree") {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
        } 

        if (is_a($categoryId, "Mage_Catalog_Model_Category")) {
            $categoryObject = $categoryId;
        } else {
            $categoryObject = Mage::getModel('catalog/category')->load($categoryId);
        }

        $productCollection = $categoryObject->getProductCollection()
                                            ->addAttributeToFilter('visibility', array('in' => array(
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH))
                                                                );

        $name = $categoryObject->getData('name');
        if (!empty($name)) {
            $category = array();
            $category['id'] = $categoryObject->getData('entity_id');
            $category['title'] = $name;
            $category['position'] = $categoryObject->getData('position');

            if ($categoryObject->getImage()) {
                $imageUrl = self::CATEGORY_MEDIA_PATH . $categoryObject->getImage();
            } else {
                $imageUrl = '';
            }
            $category['image'] = $imageUrl;

            $category['include_in_menu'] = (bool)$categoryObject->getData('include_in_menu');

            $category['product_count'] = $productCollection->getSize();

            $category['default_sort_by'] = $categoryObject->getDefaultSortBy();

            // category children
            $category['children'] = array();
            if ($maxDepth < 0 || ($maxDepth > 0 && $maxDepth > $currentDepth)) {
                $children = $this->getChildrenCollectionForCategoryId($categoryObject->getData('entity_id'));
                if ($children->count() > 0) {
                    foreach ($children as $child) {
                        $childRepresentation = $this->getCategory($child, $maxDepth, $currentDepth+1);

                        if (is_array($childRepresentation)) {
                            array_push($category['children'], $childRepresentation);
                        }
                    }
                }
            }

            return $category;
        }
        else {
            return false;
        }
    }


    /**
     * Returns a category collection of children from the given category id
     *
     * @param integer categoryId, parent category ID for which children need to be get
     * @return Mage_Catalog_Model_Resource_Category_Collection Category Collection
     */
    private function getChildrenCollectionForCategoryId($categoryId = null) {
        if ($categoryId === null) {
            return null;
        }

        
        $children = Mage::getModel('catalog/category')->getCollection()->setStoreId(Mage::app()->getStore()->getId());
        $children->addAttributeToSelect(array('entity_id', 'name', 'image', 'level', 'include_in_menu','position')) // Only get nescecary attributes from the table
                 ->addAttributeToFilter('parent_id', $categoryId)
                 ->addAttributeToSort('position')
                 ->addAttributeToFilter('is_active', 1);
        

        return $children;
    }
}