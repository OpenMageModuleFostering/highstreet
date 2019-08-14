<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com)
 * @copyright   Copyright (c) 2014 Touchwonders (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_Images extends Mage_Core_Model_Abstract
{

	public function getImage($src, $size) {
		if (!$src) {
            return null;
        } else {
            $image = Mage::helper('timage')->init($src);

            if ($src[0] !== "/") {
                    $src = "/" . $src;
                }

            $imageUrl = Mage::getBaseDir() . $src;
            if(!file_exists($imageUrl) ) {
                return null;
            }


            $originalSize = $image->getOriginalSize();
            if ($size) {

                $explodedSize = explode('x', $size);
                if (count($explodedSize) > 1) {
                    list($width, $height) = $explodedSize;
                }

                if (!empty($width) && !empty($height)) { 
                    $image->resize($width, $height);
                } else {
                    if ($originalSize['width'] >= $originalSize['height']) {
                        $image->resize($size, NULL);
                    } else {
                        $image->resize(NULL, $size);
                    }
                }

                $imageUrl = $image->cachedImage;
            } 
        }

        return $imageUrl;
	}

}