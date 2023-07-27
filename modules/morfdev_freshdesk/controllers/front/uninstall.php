<?php

class Morfdev_FreshdeskUninstallModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $postData = file_get_contents('php://input');
        if (false === $postData) {
            return $this->_error('Invalid POST data', 401);
        }
        $postData = json_decode($postData, true);
        if (null === $postData) {
            return $this->_error('Invalid JSON in POST', 401);
        }

        $token = $postData['token'];
        $type = $postData['type'];

        if ($token !== Configuration::get(Morfdev_Freshdesk::CONFIG_API_KEY)) {
            return $this->_error('Incorrect token', 401);
        }
        if ($type === 'freshdesk') {
			Configuration::updateValue(Morfdev_Freshdesk::CONFIG_FRESHDESK_DESTINATION_URL, '');
		}
		if ($type === 'freshsales') {
			Configuration::updateValue(Morfdev_Freshdesk::CONFIG_FRESHSALES_DESTINATION_URL, '');
		}
        exit;
    }
}
