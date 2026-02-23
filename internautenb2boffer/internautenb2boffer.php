<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Internautenb2boffer extends PaymentModule
{
    public const DEBUG_CONFIG_KEY = 'INTERNAUTENB2BOFFER_DEBUG_LOGS';
    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] ';

    public function __construct()
    {
        $this->name = 'internautenb2boffer';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->author = 'die.internauten.ch';
        $this->controllers = ['validation'];
        $this->is_eu_compatible = 1;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Get Offer');
        $this->description = $this->l('Let customers place orders and request an offer.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && Configuration::updateValue(self::DEBUG_CONFIG_KEY, 0)
            && $this->installOrderStates();
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::DEBUG_CONFIG_KEY);

        return $this->keepOrderStates()
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitInternautenb2bofferConfig')) {
            $enabled = (int) Tools::getValue(self::DEBUG_CONFIG_KEY, 0);
            Configuration::updateValue(self::DEBUG_CONFIG_KEY, $enabled);
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        $isDebugEnabled = (bool) Configuration::get(self::DEBUG_CONFIG_KEY);
        $output .= $isDebugEnabled
            ? $this->displayWarning($this->l('Debug logs are currently enabled.'))
            : $this->displayConfirmation($this->l('Debug logs are currently disabled.'));

        return $output . $this->renderConfigForm();
    }

    public function hookPaymentOptions($params)
    {
        $cartId = isset($params['cart']) ? (int) $params['cart']->id : 0;
        $this->debugLog('hookPaymentOptions:start cart=' . $cartId);

        if (!$this->active) {
            $this->debugLog('hookPaymentOptions:inactive');
            return;
        }

        $cart = $params['cart'];
        if (!$this->checkCurrency($cart)) {
            $this->debugLog('hookPaymentOptions:currency_not_available cart=' . (int) $cart->id . ' currency=' . (int) $cart->id_currency);
            return;
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Get Offer'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:internautenb2boffer/views/templates/front/payment_option.tpl'));

        $this->debugLog('hookPaymentOptions:ready cart=' . (int) $cart->id);

        return [$option];
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = $params['order'];

        $this->smarty->assign([
            'status' => 'ok',
            'shop_name' => $this->context->shop->name,
            'total_to_pay' => Tools::displayPrice($order->total_paid, new Currency($order->id_currency)),
            'reference' => $order->reference,
        ]);

        return $this->fetch('module:internautenb2boffer/views/templates/front/payment_return.tpl');
    }

    public function hookActionEmailSendBefore($params)
    {
        $template = isset($params['template']) ? (string) $params['template'] : '';
        $this->debugLog('hookActionEmailSendBefore:start template=' . $template);

        if (empty($params['template']) || $params['template'] !== 'order_conf') {
            return true;
        }

        if (empty($params['templateVars']['{id_order}'])) {
            return true;
        }

        $orderId = (int) $params['templateVars']['{id_order}'];
        if ($orderId <= 0) {
            return true;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return true;
        }

        $params['template'] = 'offer_request';
        $this->debugLog('hookActionEmailSendBefore:template_switched order=' . (int) $orderId . ' new_template=offer_request');

        return true;
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->debugLog('hookActionOrderStatusPostUpdate:start order=' . (int) ($params['id_order'] ?? 0) . ' status=' . (int) (($params['newOrderStatus']->id ?? 0)));

        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            $this->debugLog('hookActionOrderStatusPostUpdate:skip_non_module_order order=' . (int) $order->id);
            return;
        }

        $newStatusId = (int) $params['newOrderStatus']->id;
        $acceptedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_ACCEPTED');
        $rejectedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_REJECTED');

        if ($newStatusId !== $acceptedId && $newStatusId !== $rejectedId) {
            $this->debugLog('hookActionOrderStatusPostUpdate:skip_unrelated_status order=' . (int) $order->id . ' status=' . $newStatusId);
            return;
        }

        $template = $newStatusId === $acceptedId ? 'offer_accepted' : 'offer_rejected';
        $subject = $newStatusId === $acceptedId
            ? $this->l('Offer accepted')
            : $this->l('Offer rejected');

        $orderState = new OrderState($newStatusId);
        $templateVars = $this->getOrderEmailTemplateVars($order, $orderState);

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->debugLog('hookActionOrderStatusPostUpdate:customer_not_loaded order=' . (int) $order->id);
            return;
        }

        $sent = Mail::Send(
            (int) $order->id_lang,
            $template,
            $subject,
            $templateVars,
            $customer->email,
            trim($customer->firstname . ' ' . $customer->lastname),
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/',
            false,
            (int) $order->id_shop
        );

        $this->debugLog(
            'hookActionOrderStatusPostUpdate:mail_sent order=' . (int) $order->id
                . ' template=' . $template
                . ' to=' . $customer->email
                . ' success=' . (int) $sent
        );
    }

    public function checkCurrency($cart)
    {
        $currencyOrder = new Currency($cart->id_currency);
        $currencies = $this->getCurrency($cart->id_currency);

        foreach ($currencies as $currency) {
            if ((int) $currency['id_currency'] === (int) $currencyOrder->id) {
                return true;
            }
        }

        return false;
    }

    private function renderConfigForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Debug settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable debug logs'),
                        'name' => self::DEBUG_CONFIG_KEY,
                        'desc' => $this->l('Write module debug traces to Back Office logs.'),
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'submitInternautenb2bofferConfig',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitInternautenb2bofferConfig';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->fields_value[self::DEBUG_CONFIG_KEY] = (int) Configuration::get(self::DEBUG_CONFIG_KEY);

        return $helper->generateForm([$fieldsForm]);
    }

    private function debugLog($message, $severity = 1)
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        PrestaShopLogger::addLog(
            self::DEBUG_LOG_PREFIX . $message,
            (int) $severity,
            null,
            'module',
            (int) $this->id,
            true
        );
    }

    private function isDebugEnabled()
    {
        return (bool) Configuration::get(self::DEBUG_CONFIG_KEY);
    }

    private function installOrderStates()
    {
        return $this->ensureOrderState(
            'INTERNAUTENB2BOFFER_OS_GETOFFER',
            'getoffer',
            '#4169E1',
            false,
            null
        )
            && $this->ensureOrderState(
                'INTERNAUTENB2BOFFER_OS_OFFER_ACCEPTED',
                'accepted',
                '#3D8B37',
                false,
                null
            )
            && $this->ensureOrderState(
                'INTERNAUTENB2BOFFER_OS_OFFER_REJECTED',
                'rejected',
                '#B1001A',
                false,
                null
            );
    }

    private function keepOrderStates()
    {
        return true;
    }

    private function ensureOrderState($configKey, $stateKey, $color, $sendEmail, $template)
    {
        $existingId = (int) Configuration::get($configKey);
        if ($existingId > 0) {
            $existingState = new OrderState($existingId);
            if (Validate::isLoadedObject($existingState)) {
                $existingState->send_email = (bool) $sendEmail;
                $existingState->template = $template ?: '';
                $existingState->color = $color;
                $existingState->module_name = $this->name;

                $languages = Language::getLanguages(false);
                foreach ($languages as $language) {
                    $existingState->name[(int) $language['id_lang']] = $this->translateOrderStateLabel($stateKey, (int) $language['id_lang']);
                }

                $existingState->save();
            }

            return true;
        }

        $orderState = new OrderState();
        $orderState->send_email = (bool) $sendEmail;
        if (!empty($template)) {
            $orderState->template = $template;
        }
        $orderState->color = $color;
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = false;
        $orderState->invoice = false;
        $orderState->paid = false;
        $orderState->module_name = $this->name;

        $languages = Language::getLanguages(false);
        foreach ($languages as $language) {
            $orderState->name[(int) $language['id_lang']] = $this->translateOrderStateLabel($stateKey, (int) $language['id_lang']);
        }

        if (!$orderState->add()) {
            return false;
        }

        Configuration::updateValue($configKey, (int) $orderState->id);

        return true;
    }

    private function deleteOrderState($configKey)
    {
        $orderStateId = (int) Configuration::get($configKey);
        if ($orderStateId > 0) {
            $orderState = new OrderState($orderStateId);
            $orderState->delete();
        }

        Configuration::deleteByName($configKey);
    }

    private function translateOrderStateLabel($stateKey, $languageId)
    {
        $language = new Language((int) $languageId);
        $locale = Validate::isLoadedObject($language) ? $language->locale : null;

        switch ($stateKey) {
            case 'getoffer':
                return $this->l('Awaiting Get Offer', false, $locale);
            case 'accepted':
                return $this->l('Offer accepted', false, $locale);
            case 'rejected':
                return $this->l('Offer rejected', false, $locale);
            default:
                return $this->l('Awaiting Get Offer', false, $locale);
        }
    }

    private function getOrderEmailTemplateVars(Order $order, OrderState $orderState)
    {
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);
        $historyUrl = $this->context->link->getPageLink('history', true, (int) $order->id_lang);
        $carrier = new Carrier((int) $order->id_carrier);
        $products = $order->getProducts();
        $cartRules = $order->getCartRules();
        $deliveryAddress = new Address((int) $order->id_address_delivery);
        $invoiceAddress = new Address((int) $order->id_address_invoice);

        $productsHtml = $this->buildProductsHtml($products, $currency);
        $productsTxt = $this->buildProductsTxt($products, $currency);
        $discountsHtml = $this->buildDiscountsHtml($cartRules, $currency);
        $discountsTxt = $this->buildDiscountsTxt($cartRules, $currency);
        $deliveryBlockHtml = $this->formatAddressHtml($deliveryAddress, (int) $order->id_lang);
        $invoiceBlockHtml = $this->formatAddressHtml($invoiceAddress, (int) $order->id_lang);
        $deliveryBlockTxt = $this->formatAddressTxt($deliveryAddress, (int) $order->id_lang);
        $invoiceBlockTxt = $this->formatAddressTxt($invoiceAddress, (int) $order->id_lang);

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{shop_name}' => $this->context->shop->name,
            '{shop_url}' => $this->context->shop->getBaseURL(true),
            '{order_name}' => $order->getUniqReference(),
            '{date}' => Tools::displayDate($order->date_add, (int) $order->id_lang, true),
            '{total_paid}' => Tools::displayPrice($order->total_paid, $currency),
            '{history_url}' => $historyUrl,
            '{payment}' => $order->payment,
            '{carrier}' => Validate::isLoadedObject($carrier) ? $carrier->name : '',
            '{products}' => $productsHtml,
            '{products_txt}' => $productsTxt,
            '{discounts}' => $discountsHtml,
            '{discounts_txt}' => $discountsTxt,
            '{delivery_block_html}' => $deliveryBlockHtml,
            '{invoice_block_html}' => $invoiceBlockHtml,
            '{delivery_block_txt}' => $deliveryBlockTxt,
            '{invoice_block_txt}' => $invoiceBlockTxt,
            '{total_products}' => Tools::displayPrice($order->total_products_wt, $currency),
            '{total_discounts}' => Tools::displayPrice($order->total_discounts_tax_incl, $currency),
            '{total_wrapping}' => Tools::displayPrice($order->total_wrapping_tax_incl, $currency),
            '{total_shipping}' => Tools::displayPrice($order->total_shipping_tax_incl, $currency),
            '{total_tax_paid}' => Tools::displayPrice($order->total_paid_tax_incl - $order->total_paid_tax_excl, $currency),
        ];

        $orderHistory = new OrderHistory();
        if (method_exists($orderHistory, 'getEmailTemplateVars')) {
            $candidates = [
                [$order, $orderState],
                [$order, $orderState, $this->context],
                [$order, $orderState, $this->context->shop],
                [$order, $orderState, $this->context->shop, $this->context->language],
                [(int) $order->id, (int) $orderState->id],
                [(int) $order->id, (int) $orderState->id, $this->context->shop],
            ];

            foreach ($candidates as $args) {
                try {
                    $vars = $orderHistory->getEmailTemplateVars(...$args);
                    if (is_array($vars)) {
                        $templateVars = array_merge($vars, $templateVars);
                        break;
                    }
                } catch (Throwable $exception) {
                    continue;
                }
            }
        }

        return $templateVars;
    }

    private function buildProductsHtml(array $products, Currency $currency)
    {
        if (empty($products)) {
            return '';
        }

        $lines = [];
        foreach ($products as $product) {
            $reference = !empty($product['product_reference']) ? $product['product_reference'] : '';
            $name = $product['product_name'] ?? '';
            $unitPrice = Tools::displayPrice((float) ($product['unit_price_tax_incl'] ?? 0), $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = Tools::displayPrice((float) ($product['total_price_tax_incl'] ?? 0), $currency);

            $lines[] = '<tr>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($reference) . '</td>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($name) . '</td>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . $unitPrice . '</td>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . $quantity . '</td>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . $total . '</td>'
                . '</tr>';
        }

        return implode("\n", $lines);
    }

    private function buildProductsTxt(array $products, Currency $currency)
    {
        if (empty($products)) {
            return '';
        }

        $lines = [];
        foreach ($products as $product) {
            $reference = !empty($product['product_reference']) ? $product['product_reference'] : '';
            $name = $product['product_name'] ?? '';
            $unitPrice = Tools::displayPrice((float) ($product['unit_price_tax_incl'] ?? 0), $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = Tools::displayPrice((float) ($product['total_price_tax_incl'] ?? 0), $currency);

            $lines[] = $reference . "\t" . $name . "\t" . $unitPrice . "\t" . $quantity . "\t" . $total;
        }

        return "\n" . implode("\n", $lines);
    }

    private function buildDiscountsHtml(array $cartRules, Currency $currency)
    {
        if (empty($cartRules)) {
            return '';
        }

        $lines = [];
        foreach ($cartRules as $rule) {
            $name = $rule['name'] ?? '';
            $value = Tools::displayPrice((float) ($rule['value'] ?? 0), $currency);

            $lines[] = '<tr class="order_summary">'
                . '<td colspan="4" style="padding: 10px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($name) . '</td>'
                . '<td style="padding: 10px; border: 1px solid #DFDFDF;">-' . $value . '</td>'
                . '</tr>';
        }

        return implode("\n", $lines);
    }

    private function buildDiscountsTxt(array $cartRules, Currency $currency)
    {
        if (empty($cartRules)) {
            return '';
        }

        $lines = [];
        foreach ($cartRules as $rule) {
            $name = $rule['name'] ?? '';
            $value = Tools::displayPrice((float) ($rule['value'] ?? 0), $currency);
            $lines[] = $name . "\t-" . $value;
        }

        return "\n" . implode("\n", $lines);
    }

    private function formatAddressHtml(Address $address, $languageId)
    {
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        return AddressFormat::generateAddress($address, [], '<br />', ' ', (int) $languageId);
    }

    private function formatAddressTxt(Address $address, $languageId)
    {
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        return AddressFormat::generateAddress($address, [], "\n", ' ', (int) $languageId);
    }
}
