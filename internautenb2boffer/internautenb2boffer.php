<?php

// Payment module that lets customers submit their cart as an offer request.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Internautenb2boffer extends PaymentModule
{
    public const DEBUG_CONFIG_KEY = 'INTERNAUTENB2BOFFER_DEBUG_LOGS';
    public const MODULE_MAIL_TPL_CONFIG_KEY = 'INTERNAUTENB2BOFFER_MODULE_MAIL_TPL';
    public const OFFER_VALIDITY_DAYS_CONFIG_KEY = 'INTERNAUTENB2BOFFER_OFFER_VALIDITY_DAYS';
    public const OFFER_VALIDITY_DAYS_DEFAULT = 30;
    public const OFFER_STATE_NONE = 'none';
    public const OFFER_STATE_ORDERABLE = 'orderable';
    public const OFFER_STATE_EXPIRED = 'expired';
    public const OS_OFFER_ORDERED_CONFIG_KEY = 'INTERNAUTENB2BOFFER_OS_OFFER_ORDERED';
    public const CHECKOUT_TABLE = 'internautenb2boffer_checkout';
    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] ';
    private const PREVIEW_TEMPLATES = ['offer_request', 'offer_accepted', 'offer_rejected', 'offer_ordered'];
    private const PREVIEW_FORMATS = ['html', 'txt'];
    private const PREVIEW_ORDER_LIMIT = 100;

    public function __construct()
    {
        $this->name = 'internautenb2boffer';
        $this->tab = 'payments_gateways';
        $this->version = '3.0.0';
        $this->author = 'die.internauten.ch';
        $this->controllers = ['validation', 'reorder'];
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
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionEmailSendBefore')
            && $this->moveEmailHookToFirstPosition()
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionPresentOrder')
            && $this->registerHook('actionOrderHistoryAddAfter')
            && $this->registerHook('actionValidateOrder')
            && Configuration::updateValue(self::DEBUG_CONFIG_KEY, 0)
            && Configuration::updateValue(self::MODULE_MAIL_TPL_CONFIG_KEY, 1)
            && Configuration::updateValue(self::OFFER_VALIDITY_DAYS_CONFIG_KEY, self::OFFER_VALIDITY_DAYS_DEFAULT)
            && $this->installCheckoutTable()
            && $this->installOrderStates();
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::DEBUG_CONFIG_KEY);
        Configuration::deleteByName(self::MODULE_MAIL_TPL_CONFIG_KEY);
        Configuration::deleteByName(self::OFFER_VALIDITY_DAYS_CONFIG_KEY);
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '`');

        return $this->keepOrderStates()
            && parent::uninstall();
    }

    // Links an offer to the checkout cart created from it and to the order placed with that cart.
    public function installCheckoutTable(): bool
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '` ('
            . '`id_order_offer` INT UNSIGNED NOT NULL,'
            . '`id_cart` INT UNSIGNED NOT NULL,'
            . '`id_order_new` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`date_add` DATETIME NOT NULL,'
            . '`date_upd` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_order_offer`),'
            . 'KEY `id_cart` (`id_cart`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitInternautenb2bofferConfig')) {
            $validityDays = trim((string) Tools::getValue(self::OFFER_VALIDITY_DAYS_CONFIG_KEY, ''));
            if (!ctype_digit($validityDays)) {
                $output .= $this->displayError($this->l('Offer validity must be a whole number of 0 or more.'));
            } else {
                $enabled = (int) Tools::getValue(self::DEBUG_CONFIG_KEY, 0);
                Configuration::updateValue(self::DEBUG_CONFIG_KEY, $enabled);
                $useModuleTpl = (int) Tools::getValue(self::MODULE_MAIL_TPL_CONFIG_KEY, 1);
                Configuration::updateValue(self::MODULE_MAIL_TPL_CONFIG_KEY, $useModuleTpl);
                Configuration::updateValue(self::OFFER_VALIDITY_DAYS_CONFIG_KEY, (int) $validityDays);
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        $isDebugEnabled = (bool) Configuration::get(self::DEBUG_CONFIG_KEY);
        $output .= $isDebugEnabled
            ? $this->displayWarning($this->l('Debug logs are currently enabled.'))
            : $this->displayConfirmation($this->l('Debug logs are currently disabled.'));

        $output .= $this->renderConfigForm() . $this->renderEmailPreviewForm();
        if (Tools::isSubmit('submitInternautenb2bofferPreview')) {
            $output .= $this->renderEmailPreview();
        }

        return $output;
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
            'total_to_pay' => $this->formatPrice((float) $order->total_paid, new Currency((int) $order->id_currency)),
            'reference' => $order->reference,
        ]);

        return $this->fetch('module:internautenb2boffer/views/templates/front/payment_return.tpl');
    }

    public function hookDisplayOrderDetail($params)
    {
        if (!$this->active || !$this->context->customer->isLogged()) {
            return '';
        }

        $orderId = 0;
        if (!empty($params['order']) && $params['order'] instanceof Order) {
            $orderId = (int) $params['order']->id;
        } elseif (!empty($params['id_order'])) {
            $orderId = (int) $params['id_order'];
        }

        if ($orderId <= 0) {
            return '';
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        if ((int) $order->id_customer !== (int) $this->context->customer->id) {
            return '';
        }

        $offerState = $this->getOfferState($order);
        $link = $this->getCheckoutLink((int) $order->id);
        $newOrderId = $link ? (int) $link['id_order_new'] : 0;
        if ($offerState === self::OFFER_STATE_NONE && $newOrderId <= 0) {
            return '';
        }

        $this->smarty->assign([
            'internautenb2boffer_place_order_url' => $offerState === self::OFFER_STATE_ORDERABLE ? $this->getPlaceOfferUrl($order) : '',
            'internautenb2boffer_reorder_status' => (string) Tools::getValue('offer_reorder', ''),
            'internautenb2boffer_offer_state' => $newOrderId > 0 ? 'ordered' : $offerState,
            'internautenb2boffer_new_order_url' => $newOrderId > 0
                ? $this->context->link->getPageLink('order-detail', true, null, ['id_order' => $newOrderId])
                : '',
        ]);

        return $this->fetch('module:internautenb2boffer/views/templates/hook/order_detail_place_order.tpl');
    }

    // Exposes $order.internautenb2boffer to theme templates such as customer/history.tpl.
    public function hookActionPresentOrder($params)
    {
        $presentedOrder = $params['presentedOrder'] ?? null;
        if (!$this->active || !is_object($presentedOrder) || !method_exists($presentedOrder, 'appendArray')) {
            return;
        }

        if ((string) ($presentedOrder['details']['module'] ?? '') !== $this->name) {
            return;
        }

        $order = new Order((int) $presentedOrder['details']['id']);
        if (
            !Validate::isLoadedObject($order)
            || !$this->context->customer
            || (int) $order->id_customer !== (int) $this->context->customer->id
        ) {
            return;
        }

        $offerState = $this->getOfferState($order);
        if ($offerState === self::OFFER_STATE_NONE) {
            return;
        }

        $isOrderable = $offerState === self::OFFER_STATE_ORDERABLE;
        $presentedOrder->appendArray([
            'internautenb2boffer' => [
                'place_offer_url' => $isOrderable ? $this->getPlaceOfferUrl($order) : '',
                'label' => $isOrderable ? $this->l('Order offer') : $this->l('Offer expired'),
            ],
        ]);
    }

    // Runs after the history row is saved; cancelling earlier would be overwritten by the core status change.
    public function hookActionOrderHistoryAddAfter($params)
    {
        $history = $params['order_history'] ?? null;
        $rejectedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_REJECTED');
        $canceledId = (int) Configuration::get('PS_OS_CANCELED');
        if (
            !$history instanceof OrderHistory || $rejectedId <= 0 || $canceledId <= 0
            || (int) $history->id_order_state !== $rejectedId
        ) {
            return;
        }

        $order = new Order((int) $history->id_order);
        if (Validate::isLoadedObject($order) && $order->module === $this->name) {
            $this->cancelOrderSilently($order);
        }
    }

    // Releases reserved stock: the core only excludes cancelled/error/shipped orders from reservations.
    public function cancelOrderSilently(Order $order): bool
    {
        $canceledId = (int) Configuration::get('PS_OS_CANCELED');
        if ($canceledId <= 0 || (int) $order->current_state === $canceledId) {
            return false;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = 0;
        $history->changeIdOrderState($canceledId, $order);
        $saved = (bool) $history->add();
        $this->debugLog('cancelOrderSilently order=' . (int) $order->id . ' success=' . (int) $saved);

        return $saved;
    }

    // Returns the checkout cart for an orderable offer: an unfinished one is reused, otherwise a new one is built.
    public function startOfferCheckout(Order $order): ?Cart
    {
        if ($this->getOfferState($order) !== self::OFFER_STATE_ORDERABLE) {
            return null;
        }

        $link = $this->getCheckoutLink((int) $order->id);
        if ($link && (int) $link['id_order_new'] === 0) {
            $cart = new Cart((int) $link['id_cart']);
            if (Validate::isLoadedObject($cart) && !$cart->orderExists()) {
                return $cart;
            }
        }

        $cart = $this->buildOfferCart($order);
        if ($cart === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '` (`id_order_offer`, `id_cart`, `id_order_new`, `date_add`, `date_upd`)'
            . ' VALUES (' . (int) $order->id . ', ' . (int) $cart->id . ', 0, \'' . pSQL($now) . '\', \'' . pSQL($now) . '\')'
            . ' ON DUPLICATE KEY UPDATE `id_cart` = VALUES(`id_cart`), `id_order_new` = 0, `date_upd` = VALUES(`date_upd`)'
        );

        // The checkout stock check ignores the offer's own reservation, so release it first.
        $this->cancelOrderSilently($order);
        $this->debugLog('startOfferCheckout order=' . (int) $order->id . ' cart=' . (int) $cart->id);

        return $cart;
    }

    public function hookActionValidateOrder($params)
    {
        $cart = $params['cart'] ?? null;
        $newOrder = $params['order'] ?? null;
        if (!$cart instanceof Cart || !$newOrder instanceof Order) {
            return;
        }

        $offerId = (int) Db::getInstance()->getValue(
            'SELECT `id_order_offer` FROM `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '`'
            . ' WHERE `id_cart` = ' . (int) $cart->id . ' AND `id_order_new` = 0'
        );
        if ($offerId <= 0) {
            return;
        }

        Db::getInstance()->update(
            self::CHECKOUT_TABLE,
            ['id_order_new' => (int) $newOrder->id, 'date_upd' => date('Y-m-d H:i:s')],
            '`id_order_offer` = ' . $offerId
        );
        $this->debugLog('hookActionValidateOrder offer=' . $offerId . ' new_order=' . (int) $newOrder->id);

        $this->sendOfferMail($newOrder, 'offer_ordered', $this->l('Offer ordered'));
    }

    private function getCheckoutLink(int $offerOrderId): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '` WHERE `id_order_offer` = ' . $offerOrderId
        );

        return is_array($row) ? $row : null;
    }

    private function buildOfferCart(Order $order): ?Cart
    {
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }

        $cart = new Cart();
        $cart->id_customer = (int) $order->id_customer;
        $cart->id_guest = (int) $this->context->cookie->id_guest;
        $cart->id_address_delivery = (int) $order->id_address_delivery;
        $cart->id_address_invoice = (int) $order->id_address_invoice;
        $cart->id_currency = (int) $order->id_currency;
        $cart->id_lang = (int) $order->id_lang;
        $cart->id_shop = (int) $order->id_shop;
        $cart->id_shop_group = (int) $order->id_shop_group;
        $cart->secure_key = (string) $customer->secure_key;
        $cart->recyclable = (int) $order->recyclable;
        $cart->gift = (int) $order->gift;
        $cart->gift_message = (string) $order->gift_message;
        if (!$cart->add()) {
            $this->debugLog('buildOfferCart:cart_add_failed order=' . (int) $order->id, 3);

            return null;
        }

        $shop = new Shop((int) $order->id_shop);
        foreach ($order->getProducts() as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $attributeId = (int) ($product['product_attribute_id'] ?? 0);
            $quantity = (int) ($product['product_quantity'] ?? 0);

            $pinned = $this->pinOfferPrice($cart, $order, $product);
            // Stock is checked in the checkout again, after the offer has released its reservation.
            $added = $pinned ? $cart->updateQty($quantity, $productId, $attributeId, false, 'up', 0, $shop, true, true) : false;
            if ($added !== true) {
                $this->debugLog('buildOfferCart:add_product_failed order=' . (int) $order->id . ' product=' . $productId . ' attribute=' . $attributeId, 3);
                SpecificPrice::deleteByIdCart((int) $cart->id);
                $cart->delete();

                return null;
            }
        }

        $this->reapplyCartRules($order, $cart);

        return $cart;
    }

    // Cart-bound specific prices take precedence over group and catalog prices for this cart only.
    private function pinOfferPrice(Cart $cart, Order $order, array $product): bool
    {
        $productId = (int) ($product['product_id'] ?? 0);
        $unitTaxExcl = (float) ($product['unit_price_tax_excl'] ?? 0);

        // Group reductions are applied on top of specific prices by the core, so compensate for them.
        $groupId = (int) Customer::getDefaultGroupId((int) $order->id_customer);
        $groupRate = GroupReduction::getValueForProduct($productId, $groupId);
        if ($groupRate === false) {
            $groupRate = (float) Group::getReductionByIdGroup($groupId) / 100;
        }
        $groupRate = (float) $groupRate;
        $price = $groupRate > 0 && $groupRate < 1 ? $unitTaxExcl / (1 - $groupRate) : $unitTaxExcl;

        $specificPrice = new SpecificPrice();
        $specificPrice->id_product = $productId;
        $specificPrice->id_product_attribute = (int) ($product['product_attribute_id'] ?? 0);
        $specificPrice->id_cart = (int) $cart->id;
        $specificPrice->id_shop = (int) $order->id_shop;
        $specificPrice->id_shop_group = 0;
        $specificPrice->id_currency = (int) $order->id_currency;
        $specificPrice->id_country = 0;
        $specificPrice->id_group = 0;
        $specificPrice->id_customer = (int) $order->id_customer;
        $specificPrice->id_specific_price_rule = 0;
        $specificPrice->price = round($price, 6);
        $specificPrice->from_quantity = 1;
        $specificPrice->reduction = 0;
        $specificPrice->reduction_tax = 1;
        $specificPrice->reduction_type = 'amount';
        $specificPrice->from = '0000-00-00 00:00:00';
        $specificPrice->to = '0000-00-00 00:00:00';

        return (bool) $specificPrice->add();
    }

    private function reapplyCartRules(Order $order, Cart $cart): void
    {
        $context = $this->context->cloneContext();
        $context->cart = $cart;

        foreach ($order->getCartRules() as $row) {
            $cartRule = new CartRule((int) $row['id_cart_rule']);
            if (Validate::isLoadedObject($cartRule) && $cartRule->checkValidity($context, false, false) === true) {
                $cart->addCartRule((int) $cartRule->id);
            } else {
                $this->debugLog('reapplyCartRules:skipped order=' . (int) $order->id . ' cart_rule=' . (int) $row['id_cart_rule']);
            }
        }
    }

    public function getOfferState(Order $order): string
    {
        $acceptedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_ACCEPTED');
        if ($order->module !== $this->name || $acceptedId <= 0) {
            return self::OFFER_STATE_NONE;
        }

        if ((int) $order->current_state !== $acceptedId) {
            // An offer cancelled at checkout start stays orderable until the checkout cart has been ordered.
            $link = $this->getCheckoutLink((int) $order->id);
            if (!$link
                || (int) $link['id_order_new'] > 0
                || (int) $order->current_state !== (int) Configuration::get('PS_OS_CANCELED')
            ) {
                return self::OFFER_STATE_NONE;
            }
        }

        $validityDays = (int) Configuration::get(self::OFFER_VALIDITY_DAYS_CONFIG_KEY);
        if ($validityDays <= 0) {
            return self::OFFER_STATE_ORDERABLE;
        }

        $acceptedAt = (string) Db::getInstance()->getValue(
            'SELECT MAX(`date_add`) FROM `' . _DB_PREFIX_ . 'order_history`'
            . ' WHERE `id_order` = ' . (int) $order->id . ' AND `id_order_state` = ' . $acceptedId
        );
        if ($acceptedAt === '') {
            $acceptedAt = (string) $order->date_upd;
        }

        $expiresAt = strtotime($acceptedAt . ' +' . $validityDays . ' days');

        return $expiresAt !== false && time() <= $expiresAt ? self::OFFER_STATE_ORDERABLE : self::OFFER_STATE_EXPIRED;
    }

    private function getPlaceOfferUrl(Order $order): string
    {
        return $this->context->link->getModuleLink(
            $this->name,
            'reorder',
            [
                'id_order' => (int) $order->id,
                'key' => (string) $order->secure_key,
            ],
            true
        );
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
        $offerRequestSubject = $this->l('Offer request');
        if (isset($params['subject']) && is_array($params['subject'])) {
            foreach ($params['subject'] as $langId => $subject) {
                $params['subject'][$langId] = $offerRequestSubject;
            }
        } else {
            $params['subject'] = $offerRequestSubject;
        }
        if ((bool) Configuration::get(self::MODULE_MAIL_TPL_CONFIG_KEY)) {
            $params['templatePath'] = _PS_MODULE_DIR_ . $this->name . '/mails/';
        }
        // Core order_conf prices depend on the customer group tax setting; offers always show prices excl. tax.
        if (isset($params['templateVars']) && is_array($params['templateVars'])) {
            $params['templateVars'] = array_merge(
                $params['templateVars'],
                $this->getPriceTemplateVars($order, new Currency((int) $order->id_currency))
            );
        }
        $this->debugLog('hookActionEmailSendBefore:template_switched order=' . (int) $orderId . ' new_template=offer_request path=' . ($params['templatePath'] ?? 'default'));

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

        $this->sendOfferMail($order, $template, $subject);
    }

    private function sendOfferMail(Order $order, string $template, string $subject): bool
    {
        $templateVars = $this->getOrderEmailTemplateVars($order, new OrderState((int) $order->current_state));

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->debugLog('sendOfferMail:customer_not_loaded order=' . (int) $order->id);

            return false;
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
            'sendOfferMail:mail_sent order=' . (int) $order->id
            . ' template=' . $template
            . ' to=' . $customer->email
            . ' success=' . (int) $sent
        );

        return (bool) $sent;
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
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Offer validity (days)'),
                        'name' => self::OFFER_VALIDITY_DAYS_CONFIG_KEY,
                        'desc' => $this->l('Number of days after acceptance during which the customer can order an accepted offer. 0 = no limit.'),
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Use module email templates'),
                        'name' => self::MODULE_MAIL_TPL_CONFIG_KEY,
                        'desc' => $this->l('When enabled, the offer_request email template is loaded from the module (mails/ folder). When disabled, PrestaShop looks for the template in the active theme or default mail directory.'),
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'module_tpl_on',
                                'value' => 1,
                                'label' => $this->l('Module'),
                            ],
                            [
                                'id' => 'module_tpl_off',
                                'value' => 0,
                                'label' => $this->l('Theme / Default'),
                            ],
                        ],
                    ],
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
        $helper->fields_value[self::MODULE_MAIL_TPL_CONFIG_KEY] = (int) Configuration::get(self::MODULE_MAIL_TPL_CONFIG_KEY);
        $helper->fields_value[self::DEBUG_CONFIG_KEY] = (int) Configuration::get(self::DEBUG_CONFIG_KEY);
        $helper->fields_value[self::OFFER_VALIDITY_DAYS_CONFIG_KEY] = (int) Configuration::get(self::OFFER_VALIDITY_DAYS_CONFIG_KEY);

        return $helper->generateForm([$fieldsForm]);
    }

    private function renderEmailPreviewForm(): string
    {
        $orders = $this->getPreviewOrderOptions();
        if (empty($orders)) {
            return $this->displayInformation($this->l('No offer orders found for the email preview.'));
        }

        $languages = [['id' => 0, 'name' => $this->l('Language of the order')]];
        foreach (Language::getLanguages(true) as $language) {
            $languages[] = ['id' => (int) $language['id_lang'], 'name' => $language['name']];
        }

        $fieldsForm = [
            'form' => [
                'id_form' => 'internautenb2boffer_preview_form',
                'legend' => [
                    'title' => $this->l('Email preview'),
                    'icon' => 'icon-envelope',
                ],
                'description' => $this->l('Renders an email with the data of an existing order. No email is sent.'),
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Order'),
                        'name' => 'preview_id_order',
                        'options' => ['query' => $orders, 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Template'),
                        'name' => 'preview_template',
                        'options' => [
                            'query' => [
                                ['id' => 'offer_request', 'name' => $this->l('Offer request')],
                                ['id' => 'offer_accepted', 'name' => $this->l('Offer accepted')],
                                ['id' => 'offer_rejected', 'name' => $this->l('Offer rejected')],
                                ['id' => 'offer_ordered', 'name' => $this->l('Offer ordered')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Format'),
                        'name' => 'preview_format',
                        'options' => [
                            'query' => [
                                ['id' => 'html', 'name' => $this->l('HTML')],
                                ['id' => 'txt', 'name' => $this->l('Text')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Language'),
                        'name' => 'preview_id_lang',
                        'options' => ['query' => $languages, 'id' => 'id', 'name' => 'name'],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Show preview'),
                    'name' => 'submitInternautenb2bofferPreview',
                    'id' => 'internautenb2boffer_preview_submit',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitInternautenb2bofferPreview';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->show_toolbar = false;
        $helper->fields_value = [
            'preview_id_order' => (int) Tools::getValue('preview_id_order', $orders[0]['id']),
            'preview_template' => (string) Tools::getValue('preview_template', 'offer_request'),
            'preview_format' => (string) Tools::getValue('preview_format', 'html'),
            'preview_id_lang' => (int) Tools::getValue('preview_id_lang', 0),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    // Orders placed from an offer use another payment module; 2.0.x ordered offers are recognised by their state.
    private function getOfferOrderSqlCondition(string $alias): string
    {
        $orderedId = (int) Configuration::get(self::OS_OFFER_ORDERED_CONFIG_KEY);

        return '(' . $alias . '.`module` = \'' . pSQL($this->name) . '\''
            . ' OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . self::CHECKOUT_TABLE . '` oc WHERE oc.`id_order_new` = ' . $alias . '.`id_order`)'
            . ($orderedId > 0
                ? ' OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'order_history` oh WHERE oh.`id_order` = ' . $alias . '.`id_order` AND oh.`id_order_state` = ' . $orderedId . ')'
                : '')
            . ')';
    }

    private function isOfferOrder(Order $order): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_order` = ' . (int) $order->id
            . ' AND ' . $this->getOfferOrderSqlCondition('o')
        );
    }

    private function getPreviewOrderOptions(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, c.`firstname`, c.`lastname`'
            . ' FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`'
            . ' WHERE ' . $this->getOfferOrderSqlCondition('o')
            . Shop::addSqlRestriction(false, 'o')
            . ' ORDER BY o.`id_order` DESC'
            . ' LIMIT ' . self::PREVIEW_ORDER_LIMIT
        );

        $options = [];
        foreach ($rows ?: [] as $row) {
            $options[] = [
                'id' => (int) $row['id_order'],
                'name' => sprintf(
                    '#%d – %s – %s %s – %s',
                    (int) $row['id_order'],
                    $row['reference'],
                    $row['firstname'],
                    $row['lastname'],
                    $row['date_add']
                ),
            ];
        }

        return $options;
    }

    private function renderEmailPreview(): string
    {
        $template = (string) Tools::getValue('preview_template');
        $format = (string) Tools::getValue('preview_format');
        if (!in_array($template, self::PREVIEW_TEMPLATES, true) || !in_array($format, self::PREVIEW_FORMATS, true)) {
            return $this->displayError($this->l('Invalid preview parameters.'));
        }

        $order = new Order((int) Tools::getValue('preview_id_order'));
        if (!Validate::isLoadedObject($order) || !$this->isOfferOrder($order)) {
            return $this->displayError($this->l('Please select a valid order.'));
        }

        $languageId = (int) Tools::getValue('preview_id_lang');
        if ($languageId <= 0) {
            $languageId = (int) $order->id_lang;
        }
        if (!Validate::isLoadedObject(new Language($languageId))) {
            return $this->displayError($this->l('Invalid preview parameters.'));
        }

        $templateFile = $this->findMailTemplateFile($template, $format, $languageId);
        if ($templateFile === null) {
            return $this->displayError($this->l('The email template could not be found.'));
        }

        $content = (string) file_get_contents($templateFile);
        if ($format === 'txt') {
            // Same preprocessing as Mail::send() applies to TXT templates.
            $content = strip_tags(html_entity_decode($content, ENT_COMPAT, 'utf-8'));
        }

        $templateVars = array_merge(
            $this->getOrderEmailTemplateVars($order, new OrderState((int) $order->current_state, $languageId), $languageId),
            $this->getPreviewShopTemplateVars($order, $languageId)
        );
        $rendered = htmlspecialchars(strtr($content, $templateVars), ENT_QUOTES, 'UTF-8');

        $body = $format === 'html'
            ? '<iframe sandbox="" srcdoc="' . $rendered . '" style="width:100%; height:1000px; border:1px solid #DFDFDF;"></iframe>'
            : '<pre style="white-space:pre-wrap;">' . $rendered . '</pre>';

        return $this->displayInformation(
            $this->l('Preview only. No email has been sent.') . ' ' . $this->l('Template file:') . ' '
            . htmlspecialchars(str_replace(_PS_ROOT_DIR_, '', $templateFile), ENT_QUOTES, 'UTF-8')
        )
            . '<div class="panel"><div class="panel-heading">' . $this->l('Email preview') . '</div>' . $body . '</div>';
    }

    // Mirrors the lookup order of Mail::getTemplateBasePath() and its language fallback.
    private function findMailTemplateFile(string $template, string $format, int $languageId): ?string
    {
        $theme = $this->context->shop->theme ?? null;
        $themeDirs = [];
        if ($theme) {
            $themeDirs[] = _PS_ALL_THEMES_DIR_ . $theme->getName() . '/';
            if ($theme->get('parent')) {
                $themeDirs[] = _PS_ALL_THEMES_DIR_ . $theme->get('parent') . '/';
            }
        }

        $baseDirs = [];
        if ($template === 'offer_request' && !(bool) Configuration::get(self::MODULE_MAIL_TPL_CONFIG_KEY)) {
            foreach ($themeDirs as $themeDir) {
                $baseDirs[] = $themeDir . 'mails/';
            }
            $baseDirs[] = _PS_MAIL_DIR_;
        }
        foreach ($themeDirs as $themeDir) {
            $baseDirs[] = $themeDir . 'modules/' . $this->name . '/mails/';
        }
        $baseDirs[] = $this->getLocalPath() . 'mails/';

        $isoCodes = array_unique(array_filter([
            (string) Language::getIsoById($languageId),
            (string) Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT')),
            'en',
        ]));

        foreach ($isoCodes as $iso) {
            foreach ($baseDirs as $baseDir) {
                $file = $baseDir . basename($iso) . '/' . $template . '.' . $format;
                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    // Variables that Mail::send() adds itself; the logo is a URL here instead of an embedded cid: image.
    private function getPreviewShopTemplateVars(Order $order, int $languageId): array
    {
        $shopId = (int) $order->id_shop;
        $logo = (string) Configuration::get('PS_LOGO_MAIL', null, null, $shopId);
        if ($logo === '' || !is_file(_PS_IMG_DIR_ . $logo)) {
            $logo = (string) Configuration::get('PS_LOGO', null, null, $shopId);
        }
        $link = $this->context->link;

        return [
            '{shop_logo}' => $logo !== '' && is_file(_PS_IMG_DIR_ . $logo) ? $link->getMediaLink(_PS_IMG_ . $logo) : '',
            '{shop_name}' => Tools::safeOutput((string) Configuration::get('PS_SHOP_NAME', null, null, $shopId)),
            '{shop_url}' => $link->getPageLink('index', null, $languageId, null, false, $shopId),
            '{history_url}' => $link->getPageLink('history', null, $languageId, null, false, $shopId),
        ];
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

    // Must run before mail transport modules that send the mail inside this hook and stop the core flow.
    public function moveEmailHookToFirstPosition(): bool
    {
        $hookId = (int) Hook::getIdByName('actionEmailSendBefore');
        if ($hookId <= 0 || !$this->id) {
            return false;
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'hook_module';
        $shopIds = $db->executeS(
            'SELECT `id_shop`, `position` FROM `' . $table . '`'
            . ' WHERE `id_hook` = ' . $hookId . ' AND `id_module` = ' . (int) $this->id
        );

        foreach ($shopIds ?: [] as $row) {
            $shopId = (int) $row['id_shop'];
            $position = (int) $row['position'];

            $shifted = $db->execute(
                'UPDATE `' . $table . '` SET `position` = `position` + 1'
                . ' WHERE `id_hook` = ' . $hookId . ' AND `id_shop` = ' . $shopId
                . ' AND `position` < ' . $position . ' AND `id_module` != ' . (int) $this->id
            );
            $moved = $db->execute(
                'UPDATE `' . $table . '` SET `position` = 1'
                . ' WHERE `id_hook` = ' . $hookId . ' AND `id_shop` = ' . $shopId
                . ' AND `id_module` = ' . (int) $this->id
            );

            if (!$shifted || !$moved) {
                return false;
            }
        }

        Cache::clean(Hook::MODULE_LIST_BY_HOOK_KEY . '*');

        return true;
    }

    public function installOrderStates(): bool
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
            )
            && $this->ensureOrderState(
                self::OS_OFFER_ORDERED_CONFIG_KEY,
                'ordered',
                '#8A2BE2',
                false,
                null,
                true
            );
    }

    private function keepOrderStates()
    {
        return true;
    }

    private function ensureOrderState($configKey, $stateKey, $color, $sendEmail, $template, bool $logable = false)
    {
        $existingId = (int) Configuration::get($configKey);
        if ($existingId > 0) {
            $existingState = new OrderState($existingId);
            if (Validate::isLoadedObject($existingState)) {
                $existingState->send_email = (bool) $sendEmail;
                $existingState->template = $template ?: '';
                $existingState->color = $color;
                $existingState->logable = $logable;
                $existingState->module_name = $this->name;
                $existingState->deleted = false;

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
        $orderState->logable = $logable;
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

    // $this->l() with a locale is unreliable in a loop: the core merges all language files into one cache.
    private function translateOrderStateLabel(string $stateKey, int $languageId): string
    {
        $source = match ($stateKey) {
            'accepted' => 'Offer accepted',
            'rejected' => 'Offer rejected',
            'ordered' => 'Offer ordered',
            default => 'Awaiting Get Offer',
        };

        $iso = (string) Language::getIsoById($languageId);
        $translations = $iso !== '' ? $this->loadTranslationFile($iso) : [];
        $key = '<{' . $this->name . '}prestashop>' . $this->name . '_' . md5($source);

        return !empty($translations[$key]) ? stripslashes($translations[$key]) : $source;
    }

    private function loadTranslationFile(string $iso): array
    {
        global $_MODULE;

        $file = $this->getLocalPath() . 'translations/' . basename($iso) . '.php';
        if (!is_file($file)) {
            return [];
        }

        $previous = $_MODULE;
        include $file;
        $translations = is_array($_MODULE) ? $_MODULE : [];
        $_MODULE = $previous;

        return $translations;
    }

    private function getOrderEmailTemplateVars(Order $order, OrderState $orderState, ?int $languageId = null)
    {
        $languageId = $languageId ?? (int) $order->id_lang;
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);
        $historyUrl = $this->context->link->getPageLink('history', true, $languageId);
        $carrier = new Carrier((int) $order->id_carrier);
        $deliveryAddress = new Address((int) $order->id_address_delivery);
        $invoiceAddress = new Address((int) $order->id_address_invoice);

        $deliveryBlockHtml = $this->formatAddressHtml($deliveryAddress, $languageId);
        $invoiceBlockHtml = $this->formatAddressHtml($invoiceAddress, $languageId);
        $deliveryBlockTxt = $this->formatAddressTxt($deliveryAddress, $languageId);
        $invoiceBlockTxt = $this->formatAddressTxt($invoiceAddress, $languageId);

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{shop_name}' => $this->context->shop->name,
            '{shop_url}' => $this->context->shop->getBaseURL(true),
            '{order_name}' => $order->getUniqReference(),
            '{date}' => Tools::displayDate($order->date_add, $languageId, true),
            '{history_url}' => $historyUrl,
            '{payment}' => $order->payment,
            '{carrier}' => Validate::isLoadedObject($carrier) ? $carrier->name : '',
            '{delivery_block_html}' => $deliveryBlockHtml,
            '{invoice_block_html}' => $invoiceBlockHtml,
            '{delivery_block_txt}' => $deliveryBlockTxt,
            '{invoice_block_txt}' => $invoiceBlockTxt,
        ] + $this->getPriceTemplateVars($order, $currency);

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

    // Line items and subtotals excl. tax; {total_tax_paid} and {total_paid} (incl. tax) complete the sum.
    private function getPriceTemplateVars(Order $order, Currency $currency): array
    {
        $products = $order->getProducts();
        $cartRules = $order->getCartRules();

        return [
            '{products}' => $this->buildProductsHtml($products, $currency),
            '{products_txt}' => $this->buildProductsTxt($products, $currency),
            '{discounts}' => $this->buildDiscountsHtml($cartRules, $currency),
            '{discounts_txt}' => $this->buildDiscountsTxt($cartRules, $currency),
            '{total_products}' => $this->formatPrice((float) $order->total_products, $currency),
            '{total_discounts}' => $this->formatPrice((float) $order->total_discounts_tax_excl, $currency),
            '{total_wrapping}' => $this->formatPrice((float) $order->total_wrapping_tax_excl, $currency),
            '{total_shipping}' => $this->formatPrice((float) $order->total_shipping_tax_excl, $currency),
            '{total_tax_paid}' => $this->formatPrice((float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl, $currency),
            '{total_paid}' => $this->formatPrice((float) $order->total_paid, $currency),
        ];
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
            $prices = $this->getProductLinePrices($product);
            $rrp = $this->formatPrice($prices['rrp_tax_incl'], $currency);
            $unitPrice = $this->formatPrice($prices['unit_tax_excl'], $currency);
            $margin = $this->formatPrice($prices['margin_tax_excl'], $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = $this->formatPrice((float) ($product['total_price_tax_excl'] ?? 0), $currency);

            $lines[] = '<tr>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($reference) . '</td>'
                . '<td style="padding: 10px 5px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($name) . '</td>'
                . '<td align="right" style="padding: 10px 5px; border: 1px solid #DFDFDF; text-align: right;">' . $rrp . '</td>'
                . '<td align="right" style="padding: 10px 5px; border: 1px solid #DFDFDF; text-align: right;">' . $unitPrice . '</td>'
                . '<td align="right" style="padding: 10px 5px; border: 1px solid #DFDFDF; text-align: right;">' . $margin . '</td>'
                . '<td align="right" style="padding: 10px 5px; border: 1px solid #DFDFDF; text-align: right;">' . $quantity . '</td>'
                . '<td align="right" style="padding: 10px 5px; border: 1px solid #DFDFDF; text-align: right;">' . $total . '</td>'
                . '</tr>';
        }

        return implode("\n", $lines);
    }

    // Uses the prices stored on the order line, so later catalog changes do not alter the email.
    private function getProductLinePrices(array $product): array
    {
        $regularTaxExcl = $this->getCatalogBasePriceTaxExcl($product);
        $unitTaxExcl = (float) ($product['unit_price_tax_excl'] ?? 0);
        $taxRate = (float) ($product['tax_rate'] ?? 0);

        return [
            'rrp_tax_incl' => $regularTaxExcl * (1 + $taxRate / 100),
            'unit_tax_excl' => $unitTaxExcl,
            'margin_tax_excl' => $regularTaxExcl - $unitTaxExcl,
        ];
    }

    // original_product_price already contains group/customer fixed-price specific prices, so read the catalog price.
    private function getCatalogBasePriceTaxExcl(array $product): float
    {
        $shopId = (int) ($product['id_shop'] ?? $this->context->shop->id);
        $catalogProduct = new Product((int) ($product['product_id'] ?? 0), false, null, $shopId);
        if (!Validate::isLoadedObject($catalogProduct)) {
            return (float) ($product['original_product_price'] ?? 0);
        }

        $price = (float) $catalogProduct->price;
        $attributeId = (int) ($product['product_attribute_id'] ?? 0);
        if ($attributeId > 0) {
            $combination = new Combination($attributeId, null, $shopId);
            if (Validate::isLoadedObject($combination)) {
                $price += (float) $combination->price;
            }
        }

        return $price;
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
            $prices = $this->getProductLinePrices($product);
            $rrp = $this->formatPrice($prices['rrp_tax_incl'], $currency);
            $unitPrice = $this->formatPrice($prices['unit_tax_excl'], $currency);
            $margin = $this->formatPrice($prices['margin_tax_excl'], $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = $this->formatPrice((float) ($product['total_price_tax_excl'] ?? 0), $currency);

            $lines[] = implode("\t", [$reference, $name, $rrp, $unitPrice, $margin, $quantity, $total]);
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
            $value = $this->formatPrice((float) ($rule['value_tax_excl'] ?? 0), $currency);

            $lines[] = '<tr class="order_summary">'
                . '<td colspan="6" style="padding: 10px; border: 1px solid #DFDFDF;">' . Tools::safeOutput($name) . '</td>'
                . '<td align="right" style="padding: 10px; border: 1px solid #DFDFDF; text-align: right;">-' . $value . '</td>'
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
            $value = $this->formatPrice((float) ($rule['value_tax_excl'] ?? 0), $currency);
            $lines[] = $name . "\t-" . $value;
        }

        return "\n" . implode("\n", $lines);
    }

    // Tools::displayPrice() was removed in PrestaShop 9.
    private function formatPrice(float $amount, Currency $currency): string
    {
        $locale = $this->context->getCurrentLocale();
        if ($locale === null) {
            return number_format($amount, 2, '.', "'") . ' ' . $currency->iso_code;
        }

        return $locale->formatPrice($amount, $currency->iso_code);
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
