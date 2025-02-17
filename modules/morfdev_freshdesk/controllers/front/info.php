<?php

class Morfdev_FreshdeskInfoModuleFrontController extends ModuleFrontController
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
        $customerEmail = $postData['email'];
        $orderId = $postData['order_id'];

        if ($token !== Configuration::get(Morfdev_Freshdesk::CONFIG_API_KEY)) {
            return $this->_error('Incorrect token', 401);
        }

        $result = array(
            'customer_list' => array(),
            'order_list' => array()
        );
        try {
            $customerEmailFromOrder = null;
            if ($orderId && FALSE !== $orderId && '' !== $orderId) {
                $customerEmailFromOrder = $this->getCustomerEmailByOrderId($orderId);
            }
            if ($customerEmailFromOrder) {
                $customerEmail = $customerEmailFromOrder;
            }
            if (!$customerEmail) {
                echo json_encode($result);
                exit;
            }
            $customerInfo = $this->getCustomerDataByEmail($customerEmail);
            if ($customerInfo) {
                $result['customer_list'] = array($this->getCustomerDataByEmail($customerEmail));
            }
            $result['order_list'] = $this->getOrderListByCustomerEmail($customerEmail);
        } catch (\Exception $e) {
            $this->_error($e->getMessage(), 500, $e->getTraceAsString());
        }

        echo json_encode($result);
        exit;
    }

    /**
     * @param string $email
     *
     * @return array
     * @throws Exception
     */
    protected function getCustomerDataByEmail($email)
    {
        $customer = new Customer();
        $customer->getByEmail($email, null, false);

        if (!Validate::isLoadedObject($customer)) {
            return array();
        }
        return array(
            'url' => $this->_getAdminLink('AdminCustomers', array('id_customer' => $customer->id, 'viewcustomer' => 1)),
            'customer_id' => $customer->id,
            'name' => Tools::safeOutput($customer->firstname . ' ' . $customer->lastname),
            'email' => Tools::safeOutput($customer->email),
            'group' => $this->_getCustomerGroupAsString($customer),
            'country' => $this->_getCustomerCountry($customer),
            'total_sales' => $this->_getCustomerTotalSales($customer),
            'created_at' => $this->_formatDatetime($customer->date_add, false),
            'address_list' => $this->_getCustomerAddressList($customer),
        );
    }

    /**
     * @param string $customerEmail
     *
     * @return array
     * @throws Exception
     */
    protected function getOrderListByCustomerEmail($customerEmail)
    {
        $customer = new Customer();
        $customer->getByEmail($customerEmail, null, false);

        if (!Validate::isLoadedObject($customer)) {
            return array();
        }
        $result = array();
        $orderList = Order::getCustomerOrders($customer->id);
        foreach ($orderList as $order) {
			$addressBilling = new Address(intval($order['id_address_invoice']));
			$billing = [
				'first_name' => '',
				'last_name' => '',
				'email' => '',
				'country' => '',
				'city' => '',
				'state' => '',
				'street' => '',
				'postcode' => '',
				'phone' => '',
			];
			if (Validate::isLoadedObject($addressBilling)) {
				$addressFields = AddressFormat::getOrderedAddressFields($addressBilling->id_country);
				$addressFormatedValues = AddressFormat::getFormattedAddressFieldsValues($addressBilling, $addressFields);
				$billing = [
					'first_name' => $addressFormatedValues['firstname'],
					'last_name' => $addressFormatedValues['lastname'],
					'email' => $customerEmail,
					'country' => $addressFormatedValues['Country:name'],
					'city' => $addressFormatedValues['city'],
					'state' => $addressFormatedValues['State:name'],
					'street' => $addressFormatedValues['address1'] . ' ' . $addressFormatedValues['address2'],
					'postcode' => $addressFormatedValues['postcode'],
					'phone' => $addressFormatedValues['phone'],
				];
			}

			$addressShipping = new Address(intval($order['id_address_invoice']));
			$shipping = [
				'first_name' => '',
				'last_name' => '',
				'country' => '',
				'city' => '',
				'state' => '',
				'street' => '',
				'postcode' => '',
				'phone' => '',
			];
			if (Validate::isLoadedObject($addressShipping)) {
				$addressFields = AddressFormat::getOrderedAddressFields($addressShipping->id_country);
				$addressFormatedValues = AddressFormat::getFormattedAddressFieldsValues($addressShipping, $addressFields);
				$shipping = [
					'first_name' => $addressFormatedValues['firstname'],
					'last_name' => $addressFormatedValues['lastname'],
					'country' => $addressFormatedValues['Country:name'],
					'city' => $addressFormatedValues['city'],
					'state' => $addressFormatedValues['State:name'],
					'street' => $addressFormatedValues['address1'] . ' ' . $addressFormatedValues['address2'],
					'postcode' => $addressFormatedValues['postcode'],
					'phone' => $addressFormatedValues['phone'],
				];
			}

			$status = $order['order_state'];
			$color = array_key_exists('order_state_color', $order)?$order['order_state_color']:$this->_getOrderColor($order['id_order_state']);
			//support hidden statuses
			$context = Context::getContext();
			$orderStates = OrderState::getOrderStates((int) $context->language->id, false);
			foreach ($orderStates as $state) {
				if ($order['current_state'] === $state["id_order_state"]) {
					$status = $state['name'];
					$color = $state['color'];
				}
			}

			$result[] = array(
				"url" => $this->_getAdminLink('AdminOrders', array('id_order' => $order['id_order'], 'vieworder' => 1)),//TODO: + redirect for token
				"order_id" => $order['id_order'],
				"increment_id" => $order['reference'],
				"created_at" => $this->_formatDatetime($order['date_add']),
				"status" => $status,
				"color" => $color,
				"billing_address" => Tools::safeOutput($this->_getOrderAddressById(intval($order['id_address_invoice']))),
				"billing" => $billing,
				"shipping_address" => Tools::safeOutput($this->_getOrderAddressById(intval($order['id_address_delivery']))),
				"shipping" => $shipping,
				"payment_method" => $this->_getOrderPaymentListByOrderId(intval(($order['id_order']))),
				"shipping_method" => $this->_getOrderShippingListByOrderId(intval($order['id_order'])),
				"items" => $this->_getOrderItemsByOrderId($order['id_order']),
				"totals" => array(
					"subtotal" => $this->_formatPrice($order['total_products']),
					"shipping" => $this->_formatPrice($order['total_shipping']),
					"discount" => $this->_formatPrice($order['total_discounts']),
					"tax" => $this->_formatPrice($order['total_paid_tax_incl'] - $order['total_paid_tax_excl']),
					"grand_total" => $this->_formatPrice($order['total_paid_tax_incl'])
				)
			);
        }

        return $result;
    }

    /**
     * @param string $orderId
     *
     * @return string
     */
    protected function getCustomerEmailByOrderId($orderId)
    {
        $orderCollection = Order::getByReference($orderId);
        /** @var Order $order */
        $order = $orderCollection->getFirst();
        if (!Validate::isLoadedObject($order)) {
            return null;
        }
        $customer = new Customer($order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }
        return $customer->email;
    }

    /**
     * @param Customer $customer
     *
     * @return string
     * @throws Exception
     */
    private function _getCustomerGroupAsString($customer)
    {
        $result = array();
        foreach ($customer->getGroups() as $groupId) {
            $group = new Group($groupId);
            $result[] = $group->name[Context::getContext()->language->id];
        }

        return join(', ', $result);
    }

    /**
     * @param Customer $customer
     *
     * @return array
     */
    private function _getCustomerAddressList($customer)
    {
        $result = array();
        $addressList = $customer->getAddresses(Context::getContext()->language->id);
        foreach ($addressList as $address) {
            $shortAddress = array(
                $address['firstname'] . ' ' . $address['lastname'],
                $address['city'],
                $address['country'],
                $address['postcode']
            );
            $longAddress = array(
                $address['firstname'] . ' ' . $address['lastname'],
                $address['company'],
                $address['address1'],
                $address['address2'],
                $address['city'],
				$address['state'],
                $address['country'],
                $address['postcode'],
                $address['phone'],
            );
            $longAddress = array_filter($longAddress);
            array_map("Tools::safeOutput", $shortAddress);
            array_map("Tools::safeOutput", $longAddress);
            $result[] = array(
                'address_in_row' => join(', ', $shortAddress),
                'address' => join('<br>', $longAddress),
				'meta' => [
					'firstname' => $address['firstname'],
					'lastname' => $address['lastname'],
					'company' => $address['company'],
					'address1' => $address['address1'],
					'address2' => $address['address2'],
					'city' => $address['city'],
					'state' => $address['state'],
					'country' => $address['country'],
					'postcode' => $address['postcode'],
					'phone' => $address['phone'],
				]
            );
        }
        return $result;
    }

    /**
     * @param Customer $customer
     *
     * @return string
     */
    private function _getCustomerCountry($customer)
    {
        $result = array();
        $addressList = $customer->getAddresses(Context::getContext()->language->id);
        foreach ($addressList as $address) {
            $result[] = Tools::safeOutput(trim($address['country']));
        }
        $result = array_unique($result);
        return join(', ', $result);
    }

    /**
     * @param Customer $customer
     *
     * @return string
     * @throws Exception
     */
    private function _getCustomerTotalSales($customer)
    {
        $result = 0;
        $orderList = Order::getCustomerOrders($customer->id);
        foreach ($orderList as $order) {
            if ($order['current_state'] >= OrderState::FLAG_LOGABLE) {
                continue;
            }
            $result += $order['total_paid'];
        }
        return $this->_formatPrice($result);
    }

    /**
     * @param int $addressId
     *
     * @return string
     */
    protected function _getOrderAddressById($addressId)
    {
        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address)) {
            return '';
        }
        return AddressFormat::generateAddress($address, array(), ', ');
    }

    /**
     * @param int $orderId
     *
     * @return array
     * @throws Exception
     */
    protected function _getOrderPaymentListByOrderId($orderId)
    {
        $result = array();
        $order = new Order($orderId);
        $paymentList = $order->getOrderPayments();
        foreach ($paymentList as $payment) {
            /** @var OrderPayment $payment */
            $item = array(
                "date" => $this->_formatDatetime($payment->date_add),
                "method" => $payment->payment_method,
                "transaction_id" => $payment->transaction_id,
                "amount" => $this->_formatPrice($payment->amount, intval($payment->id_currency)),
                "invoice" => ''
            );
            $invoice = $payment->getOrderInvoice($orderId);
            if (FALSE !== $invoice) {
                $item["invoice"] = $invoice->getInvoiceNumberFormatted(Context::getContext()->language->id, $order->id_shop);
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param int $orderId
     *
     * @return array
     * @throws Exception
     */
    protected function _getOrderShippingListByOrderId($orderId)
    {
        $result = array();
        $order = new Order($orderId);
        $shippingList = $order->getShipping();
        foreach ($shippingList as $shipping) {
            $result[] = array(
                "date" => $this->_formatDatetime($shipping['date_add']),
                "carrier" => $shipping['carrier_name'],
                "weight" => sprintf('%.3f', $shipping['weight']) . ' ' . Configuration::get('PS_WEIGHT_UNIT'),
                "cost" => $this->_formatPrice($shipping['shipping_cost_tax_incl']),
                "tracking_number" => $shipping['tracking_number']
            );
        }
        return $result;
    }

    /**
     * @param int $orderId
     *
     * @return array
     * @throws Exception
     */
    protected function _getOrderItemsByOrderId($orderId)
    {
        $result = array();
        $order = new Order($orderId);
        $itemList = $order->getProducts();
        foreach ($itemList as $item) {
            $productName = $item['product_name'];
            $productOptionList = $item['product_name'];
            $product = new Product($item['product_id'], false, $order->id_lang);
            if (Validate::isLoadedObject($product)) {
                $productName = $product->name;
                $attributeList = $product->getAttributeCombinationsById($item['product_attribute_id'], $order->id_lang);
                if (count($attributeList) > 0) {
                    $optionList = array();
                    foreach ($attributeList as $attribute) {
                        $optionList[] = array(
                            'label' => $attribute['group_name'],
                            'value' => $attribute['attribute_name']
                        );
                    }
                    $productOptionList = $this->_renderItemOptions($optionList);
                }
            }
            $result[] = array(
                "url" => $this->_getAdminLink('AdminProducts', array('id_product' => $item['product_id'], 'updateproduct' => 1)),
                "product_id" => $item['product_id'],
                "name" => $productName,
                "product_html" => $productOptionList,
                "sku" => empty($item['product_reference'])?$item['reference']:$item['product_reference'],
                "price" => $this->_formatPrice($item['unit_price_tax_excl']),
                "ordered_qty" => $item['product_quantity'],
                "refunded_qty" => $item['product_quantity_refunded'],
                "returned_qty" => $item['product_quantity_return'],
                "row_total" => $this->_formatPrice($item['total_price_tax_excl'])
            );
        }
        return $result;
    }

    /**
     * @param string $message
     * @param int $status
     * @param string|null $trace
     */
    private function _error($message, $status, $trace = null)
    {
        echo json_encode(array(
            'message' => $message,
            'status' => $status,
            'trace' => $trace
        ));
        exit;
    }

    /**
     * @param string $controller
     * @param array $params
     *
     * @return string
     * @throws Exception
     */
    private function _getAdminLink($controller, $params = array())
    {
        $link = Context::getContext()->link;
        $idLang = Context::getContext()->language->id;

        if (is_callable(array($link, 'getBaseLink'))) {
            return $link->getBaseLink() . Configuration::get(Morfdev_Freshdesk::ADMIN_ROUTE) . '/'
                . Dispatcher::getInstance()->createUrl($controller, $idLang, $params);
        }
        $shop = Context::getContext()->shop;
        $ssl = Configuration::get('PS_SSL_ENABLED') && Configuration::get('PS_SSL_ENABLED_EVERYWHERE');
        $base = ($ssl ? 'https://'.$shop->domain_ssl : 'http://'.$shop->domain);
        return $base.$shop->getBaseURI();
    }

    /**
     * @param string $date
     * @param bool $withTime = true
     *
     * @return string
     * @throws Exception
     */
    private function _formatDatetime($date, $withTime = true)
    {
        $timeFormat = \IntlDateFormatter::SHORT;
        if (!$withTime) {
            $timeFormat = \IntlDateFormatter::NONE;
        }
        $formatter = \IntlDateFormatter::create('en', \IntlDateFormatter::MEDIUM, $timeFormat);
        $datetime = new \DateTime($date);
        return $formatter->format($datetime);
    }

    /**
     * @param int|string $amount
     * @param null|int|string $currency
     *
     * @return string
     * @throws Exception
     */
    private function _formatPrice($amount, $currency = null)
    {
        return Tools::displayPrice($amount, $currency);
    }

    /**
     * @param array $optionList
     *
     * @return string
     */
    private function _renderItemOptions($optionList)
    {
        $result = '';
        foreach ($optionList as $option) {
            $result .= '<div>'
                . '<span class="row-label mr-5">' . $option['label'] . ':</span>' . ' '
                . '<span class="row-value">' . $option['value'] . '</span>'
                . '</div>';
        }
        return '<div class="u-epsilon item-options">' . $result . '</div>';
    }

    /**
     * @param int $orderStateId
     *
     * @return string
     * @throws Exception
     */
    private function _getOrderColor($orderStateId)
    {
        $orderState = new OrderState($orderStateId);
        return $orderState->color;
    }
}
