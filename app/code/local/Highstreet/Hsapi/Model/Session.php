<?php

class Highstreet_Hsapi_Model_Session extends Mage_Core_Model_Session {

    protected $_externalRoute = array("hsapi/checkoutV3/external", "hsapi/redirect/setCookie");

    /**
     * Configure and start session
     *
     * @param string $sessionName
     * @return Mage_Core_Model_Session_Abstract_Varien
     */
    public function start($sessionName = null) {
        if ($this->strstr_array($this->_externalRoute, $_SERVER['REQUEST_URI']) !== false) {
            $_SESSION['frontend'] = array();
        }
        parent::start($sessionName);
        return $this;
    }

    /**
     * strstr function for searching arrays
     *
     * @param array || string $routes
     * @param string $url
     * @return strstr
     */
    public function strstr_array($routes, $url) {
        if (!is_array($routes)) {
            return strstr($url, $routes);
        }
        foreach ($routes as $route) {
            if (strstr($url, $route)) {
                return $route;
            }
        }
        return false;
    }

}
