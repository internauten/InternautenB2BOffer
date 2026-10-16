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
    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] ';
    private const PREVIEW_TEMPLATES = ['offer_request', 'offer_accepted', 'offer_rejected'];
    private const PREVIEW_FORMATS = ['html', 'txt'];
    private const PREVIEW_ORDER_LIMIT = 100;

    public function __construct()
    {
        $this->name = 'internautenb2boffer';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.0';
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
            && Configuration::updateValue(self::DEBUG_CONFIG_KEY, 0)
            && Configuration::updateValue(self::MODULE_MAIL_TPL_CONFIG_KEY, 1)
            && $this->installOrderStates();
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::DEBUG_CONFIG_KEY);
        Configuration::deleteByName(self::MODULE_MAIL_TPL_CONFIG_KEY);

        return $this->keepOrderStates()
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitInternautenb2bofferConfig')) {
            $enabled = (int) Tools::getValue(self::DEBUG_CONFIG_KEY, 0);
            Configuration::updateValue(self::DEBUG_CONFIG_KEY, $enabled);
            $useModuleTpl = (int) Tools::getValue(self::MODULE_MAIL_TPL_CONFIG_KEY, 1);
            Configuration::updateValue(self::MODULE_MAIL_TPL_CONFIG_KEY, $useModuleTpl);
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
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

        if ($order->module !== $this->name) {
            return '';
        }

        $acceptedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_ACCEPTED');
        if ($acceptedId <= 0 || (int) $order->current_state !== $acceptedId) {
            return '';
        }

        $this->smarty->assign([
            'internautenb2boffer_place_order_url' => $this->context->link->getModuleLink(
                $this->name,
                'reorder',
                [
                    'id_order' => (int) $order->id,
                    'key' => (string) $order->secure_key,
                ],
                true
            ),
            'internautenb2boffer_reorder_status' => (string) Tools::getValue('offer_reorder', ''),
        ]);

        return $this->fetch('module:internautenb2boffer/views/templates/hook/order_detail_place_order.tpl');
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

    private function getPreviewOrderOptions(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, c.`firstname`, c.`lastname`'
            . ' FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`'
            . ' WHERE o.`module` = \'' . pSQL($this->name) . '\''
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
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
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

    // $this->l() with a locale is unreliable in a loop: the core merges all language files into one cache.
    private function translateOrderStateLabel(string $stateKey, int $languageId): string
    {
        $source = match ($stateKey) {
            'accepted' => 'Offer accepted',
            'rejected' => 'Offer rejected',
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
        $products = $order->getProducts();
        $cartRules = $order->getCartRules();
        $deliveryAddress = new Address((int) $order->id_address_delivery);
        $invoiceAddress = new Address((int) $order->id_address_invoice);

        $productsHtml = $this->buildProductsHtml($products, $currency);
        $productsTxt = $this->buildProductsTxt($products, $currency);
        $discountsHtml = $this->buildDiscountsHtml($cartRules, $currency);
        $discountsTxt = $this->buildDiscountsTxt($cartRules, $currency);
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
            '{total_paid}' => $this->formatPrice((float) $order->total_paid, $currency),
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
            '{total_products}' => $this->formatPrice((float) $order->total_products_wt, $currency),
            '{total_discounts}' => $this->formatPrice((float) $order->total_discounts_tax_incl, $currency),
            '{total_wrapping}' => $this->formatPrice((float) $order->total_wrapping_tax_incl, $currency),
            '{total_shipping}' => $this->formatPrice((float) $order->total_shipping_tax_incl, $currency),
            '{total_tax_paid}' => $this->formatPrice((float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl, $currency),
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
            $unitPrice = $this->formatPrice((float) ($product['unit_price_tax_incl'] ?? 0), $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = $this->formatPrice((float) ($product['total_price_tax_incl'] ?? 0), $currency);

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
            $unitPrice = $this->formatPrice((float) ($product['unit_price_tax_incl'] ?? 0), $currency);
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $total = $this->formatPrice((float) ($product['total_price_tax_incl'] ?? 0), $currency);

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
            $value = $this->formatPrice((float) ($rule['value'] ?? 0), $currency);

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
            $value = $this->formatPrice((float) ($rule['value'] ?? 0), $currency);
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
