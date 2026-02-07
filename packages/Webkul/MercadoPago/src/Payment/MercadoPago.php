<?php

namespace Webkul\MercadoPago\Payment;

use Webkul\Payment\Payment\Payment;

class MercadoPago extends Payment
{
    protected $code = 'mercadopago';

    /**
     * Bagisto solo necesita que lo mandes a TU endpoint,
     * y ese endpoint se encarga de crear la preferencia y redirigir a MP.
     */
    public function getRedirectUrl()
    {
        return route('mercadopago.redirect');
    }

    public function isAvailable()
    {
        if (! $this->cart) {
            $this->setCart();
        }

        return (bool) $this->getConfigData('active') && $this->cart?->haveStockableItems();
    }
}
