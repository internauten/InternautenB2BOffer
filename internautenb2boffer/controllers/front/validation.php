<?php

class Internautenb2bofferValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] validation:';

    public function postProcess()
    {
        $this->debugLog('start cart=' . (int) $this->context->cart->id);

        if (!$this->module->active) {
            $this->debugLog('redirect module_inactive');
            Tools::redirect('index.php?controller=order');
        }

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            $this->debugLog(
                'redirect invalid_cart customer=' . (int) $cart->id_customer
                . ' delivery=' . (int) $cart->id_address_delivery
                . ' invoice=' . (int) $cart->id_address_invoice
            );
            Tools::redirect('index.php?controller=order');
        }

        if (!$this->module->checkCurrency($cart)) {
            $this->debugLog('redirect currency_not_allowed currency=' . (int) $cart->id_currency);
            Tools::redirect('index.php?controller=order');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->debugLog('redirect invalid_customer customer=' . (int) $cart->id_customer);
            Tools::redirect('index.php?controller=order');
        }

        $orderStateId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_GETOFFER');
        if ($orderStateId <= 0) {
            $this->debugLog('redirect missing_order_state');
            Tools::redirect('index.php?controller=order');
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $this->debugLog('validate_order cart=' . (int) $cart->id . ' customer=' . (int) $customer->id . ' state=' . $orderStateId . ' total=' . $total);

        $this->module->validateOrder(
            (int) $cart->id,
            $orderStateId,
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $this->debugLog('validate_order_done order=' . (int) $this->module->currentOrder . ' cart=' . (int) $cart->id);

        Tools::redirect(
            'index.php?controller=order-confirmation'
                . '&id_cart=' . (int) $cart->id
                . '&id_module=' . (int) $this->module->id
                . '&id_order=' . (int) $this->module->currentOrder
                . '&key=' . $customer->secure_key
        );
    }

    private function debugLog($message, $severity = 1)
    {
        if (!(bool) Configuration::get(Internautenb2boffer::DEBUG_CONFIG_KEY)) {
            return;
        }

        PrestaShopLogger::addLog(
            self::DEBUG_LOG_PREFIX . $message,
            (int) $severity,
            null,
            'module',
            (int) $this->module->id,
            true
        );
    }
}
