<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com) ~ Touchwonders
 * @copyright   Copyright (c) 2013 Touchwonders b.v. (http://www.touchwonders.com/)
 */

class Highstreet_Hsapi_Model_Categories extends Mage_Core_Model_Abstract
{
    const CATEGORY_MEDIA_PATH = "/media/catalog/category/";

    /**
     * Get category and children from id
     * @param $categoryId
     *
     * @return bool
     */
    public function getCategories($categoryId)
    {
        $categoryObject = Mage::getModel('catalog/category')->load($categoryId);

        $productCollection = $categoryObject->getProductCollection()
                                            ->addAttributeToFilter('visibility', array('in' => array(
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH))
                                                                );

        $name = $categoryObject->getData('name');
        if (!empty($name)) {
            $category = array();
            $category['id'] = $categoryId;
            $category['title'] = $categoryObject->getData('name');

            if ($categoryObject->getImage()) {
                $imageUrl = self::CATEGORY_MEDIA_PATH . $categoryObject->getImage();
            } else {
                $imageUrl = '';
            }
            $category['image'] = $imageUrl;

            $category['product_count'] = $productCollection->count();

            // category children
            $children = $this->getChildrenCollectionForCategoryId($categoryId);

            $config = Mage::helper('highstreet_hsapi/config');
            $filtersCategories = $config->filtersCategories();

            if ($children->count() > 0) {
                $category['children'] = array();
                
                foreach ($children as $child) {
                    if ($filtersCategories) {
                        if ($child->getData('level') == 2 && // Top Category Level
                            $child->getData('include_in_menu') == 0) {
                            continue;
                        }
                    }

                    if ($child->getImage()) {
                        $childImageUrl = self::CATEGORY_MEDIA_PATH . $child->getImage();
                    } else {
                        $childImageUrl = '';
                    }
                    array_push($category['children'], array(
                        'id'            => $child->getData('entity_id'),
                        'title'         => $child->getData('name'),
                        'image'         => $childImageUrl,
                    ));
                }
            }

            return $category;
        }
        else {
            return false;
        }
    }

    /**
     * Gets the entire category tree. Can be filtered for a specific category with param categoryId
     *
     * @param integer categoryId, a categoryId which will filter the tree
     * @return array Array of categories
     */
    public function getCategoryTree($categoryId = null) {
        if ($categoryId == null) {
            $categoryId = Mage::app()->getStore()->getRootCategoryId();
        }

        $categoryObject = Mage::getModel('catalog/category')->load($categoryId);
        $children = $this->getChildrenCollectionForCategoryId($categoryId);

        $productCollection = $categoryObject->getProductCollection()
                                            ->addAttributeToFilter('visibility', array('in' => array(
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                                                                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH))
                                                                );

        $name = $categoryObject->getData('name');
        if (!empty($name)) {
            $category = array();
            $category['id'] = $categoryId;
            $category['title'] = $name;
            $category['position'] = $categoryObject->getData('position');

            if ($categoryObject->getImage()) {
                $imageUrl = self::CATEGORY_MEDIA_PATH . $categoryObject->getImage();
            } else {
                $imageUrl = '';
            }
            $category['image'] = $imageUrl;

            $category['product_count'] = $productCollection->count();

            $config = Mage::helper('highstreet_hsapi/config');
            $filtersCategories = $config->filtersCategories();

            // category children
            $category['children'] = array();
            if ($children->count() > 0) {
                foreach ($children as $child) {
                    if ($filtersCategories) {
                        if ($child->getData('level') == 2 && // Top Category Level
                            $child->getData('include_in_menu') == 0) {
                            continue;
                        }
                    }
                    
                    $childRepresentation = $this->getCategoryTree($child->getData('entity_id'));

                    if (is_array($childRepresentation)) {
                        array_push($category['children'], $childRepresentation);
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