<?php

class Highstreet_Hsapi_Model_System_Config_Environment {
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 'staging',
                'label' => 'Staging',
            ),
            array(
                'value' => 'production',
                'label' => 'Production',
            ),
        );
    }
}