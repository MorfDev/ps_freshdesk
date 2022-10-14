<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Morfdev_Freshdesk extends Module
{
    /** @var string */
    const CONFIG_API_KEY = 'MORFDEV_FRESHDESK_APIKEY';
    const ADMIN_ROUTE = 'MORFDEV_FRESHDESK_ADMIN_ROUTE';
    /** @var string */
    const SUBMIT_NAME = 'update-configuration';

    public function __construct()
    {
        $this->name = 'morfdev_freshdesk';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'MorfDev';

        $this->controllers = array('info');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('MorfDev Freshdesk Connector');
        $this->description = $this->l('Connect your Prestashop to Freshdesk');

        $this->ps_versions_compliancy = array('min' => '1.5.0.1', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        Configuration::updateValue(self::CONFIG_API_KEY, Tools::strtoupper(Tools::passwdGen(16)));
        Configuration::updateValue(self::ADMIN_ROUTE, basename(_PS_ADMIN_DIR_));

        return true;
    }

    public function uninstall()
    {
        Configuration::updateValue(self::CONFIG_API_KEY, '');
        Configuration::updateValue(self::ADMIN_ROUTE, '');
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
                    'title' => $this->l('Freshdesk Connector'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'desc' => $this->l('description'),
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