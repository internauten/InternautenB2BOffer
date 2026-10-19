<?php

declare(strict_types=1);

// Lets the customer confirm an accepted offer as a binding order on the existing order (payment by invoice).
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

class Internautenb2bofferReorderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] reorder:';

    private ?Order $order = null;

    public function postProcess(): void
    {
        $order = $this->loadCustomerOrder();

        $offerState = $this->module->getOfferState($order);
        if ($offerState === Internautenb2boffer::OFFER_STATE_EXPIRED) {
            $this->debugLog('redirect detail: offer_expired order=' . (int) $order->id);
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, Internautenb2boffer::OFFER_STATE_EXPIRED));
        }
        if ($offerState !== Internautenb2boffer::OFFER_STATE_ORDERABLE) {
            $this->debugLog('redirect detail: order_not_accepted order=' . (int) $order->id . ' state=' . (int) $order->current_state);
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'not_accepted'));
        }

        $this->order = $order;

        if (!Tools::isSubmit('submitPlaceOffer')) {
            return;
        }

        if (!hash_equals($this->getFormToken($order), (string) Tools::getValue('token'))) {
            $this->debugLog('invalid_token order=' . (int) $order->id, 2);
            Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'failed'));
        }

        try {
            $placed = $this->module->placeAcceptedOffer($order);
        } catch (Throwable $exception) {
            $this->debugLog('exception order=' . (int) $order->id . ' ' . $exception->getMessage(), 3);
            $placed = false;
        }

        $this->debugLog('place_offer order=' . (int) $order->id . ' success=' . (int) $placed);
        Tools::redirect($this->getOrderDetailUrl((int) $order->id, $placed ? 'ordered' : 'failed'));
    }

    public function initContent(): void
    {
        parent::initContent();

        $order = $this->order;
        $this->context->smarty->assign([
            'internautenb2boffer_reference' => $order->getUniqReference(),
            'internautenb2boffer_total' => $this->context->getCurrentLocale()->formatPrice(
                (float) $order->total_paid,
                (string) Currency::getIsoCodeById((int) $order->id_currency)
            ),
            'internautenb2boffer_action_url' => $this->context->link->getModuleLink(
                $this->module->name,
                'reorder',
                ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key],
                true
            ),
            'internautenb2boffer_token' => $this->getFormToken($order),
            'internautenb2boffer_detail_url' => $this->context->link->getPageLink('order-detail', true, null, ['id_order' => (int) $order->id]),
        ]);

        $this->setTemplate('module:internautenb2boffer/views/templates/front/place_offer.tpl');
    }

    private function loadCustomerOrder(): Order
    {
        if (!$this->module->active || !$this->context->customer->isLogged()) {
            $this->debugLog('redirect history: module_inactive_or_not_logged');
            Tools::redirect('index.php?controller=history');
        }

        $order = new Order((int) Tools::getValue('id_order'));
        $secureKey = (string) Tools::getValue('key');
        if (
            !Validate::isLoadedObject($order)
            || $secureKey === ''
            || (int) $order->id_customer !== (int) $this->context->customer->id
            || !hash_equals((string) $order->secure_key, $secureKey)
        ) {
            $this->debugLog('redirect history: invalid_order_or_key order=' . (int) Tools::getValue('id_order'), 2);
            Tools::redirect('index.php?controller=history');
        }

        return $order;
    }

    private function getFormToken(Order $order): string
    {
        return Tools::getToken('internautenb2boffer_place_offer_' . (int) $order->id);
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
