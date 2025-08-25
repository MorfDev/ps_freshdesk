<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Morfdev_Freshdesk extends Module
{
    /** @var string */
	const CONFIG_API_KEY = 'MORFDEV_FRESHDESK_APIKEY';
	const CONFIG_FRESHDESK_DESTINATION_URL = 'MORFDEV_FRESHDESK_DESTINATION_URL';
	const CONFIG_FRESHSALES_DESTINATION_URL = 'MORFDEV_FRESHSALES_DESTINATION_URL';
    const ADMIN_ROUTE = 'MORFDEV_FRESHDESK_ADMIN_ROUTE';
    /** @var string */
    const SUBMIT_NAME = 'update-configuration';

    public function __construct()
    {
        $this->name = 'morfdev_freshdesk';
        $this->tab = 'administration';
        $this->version = '1.0.3';
        $this->author = 'MorfDev';

        $this->controllers = array('info');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('MorfDev Freshworks Connector');
        $this->description = $this->l('Connect your Prestashop to Freshdesk/Freshsales');

        $this->ps_versions_compliancy = array('min' => '1.5.0.1', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        Configuration::updateValue(self::CONFIG_API_KEY, Tools::strtoupper(Tools::passwdGen(16)));
        Configuration::updateValue(self::ADMIN_ROUTE, basename(_PS_ADMIN_DIR_));
		$this->registerHook('actionValidateOrder');
		$this->registerHook('actionCustomerAccountAdd');
		$this->registerHook('actionCustomerAccountUpdate');
		$this->registerHook('actionObjectAddressAddAfter');

        return true;
    }

    public function hookActionValidateOrder(array $params)
	{
		$order = $params['order'];
		$customer = $params['customer'];
		$data = [
			'scope' => "order.created",
			'email' => $customer->email,
			'number' => $order->reference,
			'amount' => $order->total_paid_tax_incl
		];
		$this->sendHook($data);
	}

	public function hookActionObjectAddressAddAfter(array $params)
	{
		$address = $params['object'];
		$customer = new Customer($address->id_customer);

		$billingAddress = array(
			'address_1' => $address->address1,
			'address_2' => $address->address2,
			'city' => $address->city,
			'state' => State::getNameById($address->id_state),
			'country' => Country::getNameById(Context::getContext()->language->id,$address->id_country),
			'postcode' => $address->postcode
		);

		$data = [
			'scope' => "customer.updated",
			'email' => Tools::safeOutput($customer->email),
			'first_name' => Tools::safeOutput($customer->firstname),
			'last_name' => Tools::safeOutput($customer->lastname),
			'phone' => $address->phone,
			'addressFormatted' => join(', ', $billingAddress),
			'address' => $billingAddress,
			'company' => $address->company
		];
		$this->sendHook($data);
	}

	public function hookActionCustomerAccountUpdate(array $params)
	{
		$customer = $params['customer'];
		$billingAddress = $this->getCustomerAddresses($customer);

		$data = [
			'scope' => "customer.updated",
			'email' => Tools::safeOutput($customer->email),
			'first_name' => Tools::safeOutput($customer->firstname),
			'last_name' => Tools::safeOutput($customer->lastname),
			'phone' => isset($billingAddress['phone']) ? $billingAddress['phone'] :'',
			'addressFormatted' => join(', ', $billingAddress),
			'address' => $billingAddress,
			'company' => isset($billingAddress['company']) ? $billingAddress['company'] :''
		];
		$this->sendHook($data);
	}

	public function hookActionCustomerAccountAdd(array $params)
	{
		$customer = $params['newCustomer'];
		$billingAddress = $this->getCustomerAddresses($customer);
		$data = [
			'scope' => "customer.created",
			'email' => Tools::safeOutput($customer->email),
			'first_name' => Tools::safeOutput($customer->firstname),
			'last_name' => Tools::safeOutput($customer->lastname),
			'phone' => isset($billingAddress['phone']) ? $billingAddress['phone'] :'',
			'addressFormatted' => join(', ', $billingAddress),
			'address' => $billingAddress,
			'company' => isset($billingAddress['company']) ? $billingAddress['company'] :''
		];
		$this->sendHook($data);
	}

	private function getCustomerAddresses($customer)
	{
		$addressList = $customer->getAddresses(Context::getContext()->language->id);
		if (!$addressList) {
			return [];
		}
		$address = array_pop($addressList);
		return [
			'address_1' => $address['address1'],
			'address_2' => $address['address2'],
			'city' => $address['city'],
			'state' => $address['state'],
			'country' => $address['country'],
			'phone' => $address['phone'],
			'company' => $address['company'],
			'postcode' => $address['postcode']
		];
	}

	private function sendHook($data)
	{
		$destinationUrlList = [];
		if ($freshdeskUrl = Configuration::get(Morfdev_Freshdesk::CONFIG_FRESHDESK_DESTINATION_URL)) {
			$destinationUrlList[] = $freshdeskUrl;
		}
		if ($freshsalesUrl = Configuration::get(Morfdev_Freshdesk::CONFIG_FRESHSALES_DESTINATION_URL)) {
			$destinationUrlList[] = $freshsalesUrl;
		}
		foreach ($destinationUrlList as $destinationUrl) {
			$ch = curl_init($destinationUrl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
			curl_exec($ch);
			curl_close($ch);
		}
	}

    public function uninstall()
    {
        Configuration::updateValue(self::CONFIG_API_KEY, '');
		Configuration::updateValue(self::CONFIG_FRESHDESK_DESTINATION_URL, '');
		Configuration::updateValue(self::CONFIG_FRESHSALES_DESTINATION_URL, '');
        Configuration::updateValue(self::ADMIN_ROUTE, '');

		$this->unregisterHook('actionValidateOrder');
		$this->unregisterHook('actionCustomerAccountAdd');
		$this->unregisterHook('actionCustomerAccountUpdate');
		$this->unregisterHook('actionSubmitCustomerAddressForm');

        if (!parent::uninstall()) {
            return false;
        }
        return true;
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getContent()
    {
        if (Tools::getValue(self::SUBMIT_NAME)) {
            Configuration::updateValue(self::ADMIN_ROUTE, basename(_PS_ADMIN_DIR_));
            Configuration::updateValue(
                self::CONFIG_API_KEY,
                Tools::getValue(self::CONFIG_API_KEY)
            );
        }
        return $this->renderForm();
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function renderForm()
    {
        $fieldsValue = array(
            self::CONFIG_API_KEY => Tools::getValue(
                self::CONFIG_API_KEY,
                Configuration::get(self::CONFIG_API_KEY)
            ),
        );
        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Freshworks Connector'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'desc' => $this->l('Used to auth connections from Freshdesk/Freshsales'),
                        'name' => self::CONFIG_API_KEY,
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'name' => self::SUBMIT_NAME,
                    'title' => $this->l('Save'),
                )
            ),
        );

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));

        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->default_form_language = $lang->id;
        $helper->submit_action = 'update-configuration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).
            '&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $fieldsValue,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );
        return $helper->generateForm(array($form));
    }
}