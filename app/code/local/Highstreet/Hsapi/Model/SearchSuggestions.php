<?php
/**
 * Highstreet_HSAPI_module
 *
 * @package     Highstreet_Hsapi
 * @author      Tim Wachter (tim@touchwonders.com)
 * @copyright   Copyright (c) 2015 Touchwonders (http://www.touchwonders.com/)
 */
class Highstreet_Hsapi_Model_SearchSuggestions extends Mage_Core_Model_Abstract
{
	/**
	 * Can take params for category, limit and search query. It manually takes those params from the URL
	 *
	 * @param integer Limit, limit for the results
	 * @param string Search, search query
	 * @param integer Category, limit results to category
	 * @return array Search suggestions
	 */
	public function getSearchSuggestions($paramLimit, $paramSearch, $paramCategory) {
		// Initialize search object
		$searchModel= Mage::getModel('catalogsearch/query');

		$searchCollection = $searchModel->getCollection();
		$searchCollection->addOrder('popularity', 'DESC'); // Order on popularity

		// Set limit
		$limit = 10;
		$maxLimit = 50;
		if (!empty($paramLimit)) {
			$limit = $paramLimit;
		}

		if ($limit > $maxLimit) { // Limit the limit otherwise attackers could abuse this API
			$limit = $maxLimit;
		}

		// Limit data to search term
		if (!empty($paramSearch)) {
			$paramSearch = str_replace('%', '', $paramSearch);
			$searchCollection->addFieldToFilter('query_text', array("like" => $paramSearch . "%"));
		}

		$searchCollection->addFieldToFilter('store_id', Mage::app()->getStore()->getId());


		$searchCollection->addFieldToFilter('display_in_terms', 1);

		// Get data
		$searchCollection->getSelect()->limit($limit);

		$searchSuggestionData = array();

		foreach ($searchCollection as $item) {
		    $searchSuggestionData["search_suggestions"][]["search_term"] = (string)$item->getData("query_text");
		}

		if (count($searchSuggestionData["search_suggestions"]) < 1) {
			$searchSuggestionData["search_suggestions"][] = "";
		}

		return $searchSuggestionData;
	}

}