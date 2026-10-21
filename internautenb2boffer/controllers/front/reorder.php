<?php

declare(strict_types=1);

// Starts the regular checkout for an accepted offer with a cart that keeps the offer prices.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

class Internautenb2bofferReorderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] reorder:';

    public function postProcess(): void
    {
        if (!$this->module->active || !$this->context->customer->isLogged()) {
            $this->debugLog('redirect history: module_inactive_or_not_logged');
            Tools::redirect('index.php?controller=history');
        }

        $order = new Order((int) Tools::getValue('id_order'));
        $secureKey = (string) Tools::getValue('key');
        if (!Validate::isLoadedObject($order)
            || $secureKey === ''
            || (int) $order->id_customer !== (int) $this->context->customer->id
            || !hash_equals((string) $order->secure_key, $secureKey)
        ) {
            $this->debugLog('redirect history: invalid_order_or_key order=' . (int) Tools::getValue('id_order'), 2);
            Tools::redirect('index.php?controller=history');
        }

        $offerState = $this->module->getOfferState($order);
        if ($offerState === Internautenb2boffer::OFFER_STATE_EXPIRED) {
            $this->debugLog('redirect detail: offer_expired order=' . (int) $order->id);
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, Internautenb2boffer::OFFER_STATE_EXPIRED));
        }
        if ($offerState !== Internautenb2boffer::OFFER_STATE_ORDERABLE) {
            $this->debugLog('redirect detail: order_not_accepted order=' . (int) $order->id . ' state=' . (int) $order->current_state);
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'not_accepted'));
        }

        try {
            $cart = $this->module->startOfferCheckout($order);
        } catch (Throwable $exception) {
            $this->debugLog('exception order=' . (int) $order->id . ' ' . $exception->getMessage(), 3);
            $cart = null;
        }

        if ($cart === null) {
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'failed'));
        }

        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->write();

        $this->debugLog('checkout order=' . (int) $order->id . ' cart=' . (int) $cart->id);
        Tools::redirect($this->context->link->getPageLink('order', true));
    }

    private function getOrderDetailUrl(int $orderId, string $status): string
    {
        return $this->context->link->getPageLink('order-detail', true, null, [
            'id_order' => $orderId,
            'offer_reorder' => $status,
        ]);
    }

    private function debugLog(string $message, int $severity = 1): void
    {
        if (!(bool) Configuration::get(Internautenb2boffer::DEBUG_CONFIG_KEY)) {
            return;
        }

        PrestaShopLogger::addLog(
            self::DEBUG_LOG_PREFIX . $message,
            $severity,
            null,
            'module',
            (int) $this->module->id,
            true
        );
    }
}
