<?php

class Internautenb2bofferReorderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private const DEBUG_LOG_PREFIX = '[internautenb2boffer] reorder:';

    public function postProcess()
    {
        try {
            if (!$this->module->active || !$this->context->customer->isLogged()) {
                $this->debugLog('redirect history: module_inactive_or_not_logged');
                Tools::redirect('index.php?controller=history');
            }

            $orderId = (int) Tools::getValue('id_order');
            $secureKey = (string) Tools::getValue('key');
            if ($orderId <= 0 || $secureKey === '') {
                $this->debugLog('redirect history: invalid_params order=' . $orderId);
                Tools::redirect('index.php?controller=history');
            }

            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                $this->debugLog('redirect history: order_not_loaded order=' . $orderId, 3);
                Tools::redirect('index.php?controller=history');
            }

            if ($order->module !== $this->module->name) {
                $this->debugLog('redirect history: wrong_module order=' . (int) $order->id . ' module=' . (string) $order->module, 3);
                Tools::redirect('index.php?controller=history');
            }

            if ((int) $order->id_customer !== (int) $this->context->customer->id) {
                $this->debugLog('redirect history: wrong_customer order=' . (int) $order->id, 3);
                Tools::redirect('index.php?controller=history');
            }

            if (!hash_equals((string) $order->secure_key, $secureKey)) {
                $this->debugLog('redirect history: secure_key_mismatch order=' . (int) $order->id, 3);
                Tools::redirect('index.php?controller=history');
            }

            $acceptedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_ACCEPTED');
            if ($acceptedId <= 0 || (int) $order->current_state !== $acceptedId) {
                $this->debugLog('redirect detail: order_not_accepted order=' . (int) $order->id . ' state=' . (int) $order->current_state . ' accepted=' . $acceptedId);
                Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'not_accepted'));
            }

            $this->debugLog('start from_order=' . (int) $order->id . ' customer=' . (int) $this->context->customer->id);

            $oldCart = new Cart((int) $order->id_cart);
            if (!Validate::isLoadedObject($oldCart)) {
                $this->debugLog('redirect detail: source_cart_not_loaded order=' . (int) $order->id . ' cart=' . (int) $order->id_cart, 3);
                Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'source_cart_missing'));
            }

            // Match PrestaShop reorder flow: duplicate original cart and move user into checkout with the duplicated cart.
            $duplicate = $oldCart->duplicate();
            if (!is_array($duplicate)
                || empty($duplicate['success'])
                || empty($duplicate['cart'])
                || !$duplicate['cart'] instanceof Cart
            ) {
                $this->debugLog('duplicate_failed order=' . (int) $order->id . ' source_cart=' . (int) $oldCart->id . ' fallback=manual_cart_rebuild', 2);
                $cart = $this->buildCartFromOrder($order);
                if (!Validate::isLoadedObject($cart)) {
                    $this->debugLog('reorder_failed order=' . (int) $order->id . ' reason=manual_rebuild_failed', 3);
                    Tools::redirect($this->getOrderDetailUrl((int) $order->id, 'duplicate_failed'));
                }
            } else {
                $cart = $duplicate['cart'];
            }
            $this->context->cart = $cart;
            $this->context->cookie->id_cart = (int) $cart->id;
            $this->context->cookie->write();

            if (class_exists('CartRule')) {
                CartRule::autoRemoveFromCart($this->context);
                CartRule::autoAddToCart($this->context);
            }

            $this->debugLog('reorder_success order=' . (int) $order->id . ' source_cart=' . (int) $oldCart->id . ' new_cart=' . (int) $cart->id);
            Tools::redirect($this->context->link->getPageLink('order', true));
        } catch (Throwable $exception) {
            $this->debugLog('exception ' . $exception->getMessage(), 3);
            Tools::redirect('index.php?controller=history&offer_reorder=exception');
        }
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

    private function buildCartFromOrder(Order $order)
    {
        $cart = new Cart();
        $cart->id_customer = (int) $order->id_customer;
        $cart->id_guest = (int) $order->id_guest;
        $cart->id_address_delivery = (int) $order->id_address_delivery;
        $cart->id_address_invoice = (int) $order->id_address_invoice;
        $cart->id_currency = (int) $order->id_currency;
        $cart->id_lang = (int) $order->id_lang;
        $cart->id_shop = (int) $order->id_shop;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->secure_key = (string) $order->secure_key;
        $cart->recyclable = (int) $order->recyclable;
        $cart->gift = (int) $order->gift;
        $cart->gift_message = (string) $order->gift_message;

        if (!$cart->add()) {
            $this->debugLog('manual_rebuild_failed order=' . (int) $order->id . ' step=create_cart', 3);
            return null;
        }

        $products = $order->getProducts();
        foreach ($products as $product) {
            $added = $cart->updateQty(
                (int) ($product['product_quantity'] ?? 0),
                (int) ($product['product_id'] ?? 0),
                (int) ($product['product_attribute_id'] ?? 0),
                null,
                'up',
                0,
                new Shop((int) $order->id_shop),
                true
            );

            if (!$added) {
                $this->debugLog(
                    'manual_rebuild_failed order=' . (int) $order->id
                        . ' step=add_product product=' . (int) ($product['product_id'] ?? 0)
                        . ' attribute=' . (int) ($product['product_attribute_id'] ?? 0),
                    3
                );
                return null;
            }
        }

        return $cart;
    }

    private function getOrderDetailUrl($orderId, $status)
    {
        return $this->context->link->getPageLink('order-detail', true, null, [
            'id_order' => (int) $orderId,
            'offer_reorder' => (string) $status,
        ]);
    }
}
