<?php

namespace Webkul\MercadoPago\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Shop\Http\Resources\OrderResource;

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoController extends Controller
{
    public function __construct(
        protected OrderRepository $orderRepository,
        protected InvoiceRepository $invoiceRepository
    ) {}

    /**
     * 1) Bagisto llega aquí: creamos ORDER (pending) + preferencia y redirigimos a MP.
     * Esto hace que en .test el pedido sí exista aunque webhook no llegue.
     */
    public function redirect(Request $request)
    {
        $cart = Cart::getCart();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Tu carrito está vacío.');
        }

        // ✅ Crear ORDER usando el mismo payload que usa tu OnepageController
        $data  = (new OrderResource($cart))->toArray($request);
        $order = $this->orderRepository->create($data);

        // Status pendiente de pago (si tu Bagisto no tiene este status, usa "pending")
        $this->orderRepository->update(['status' => 'pending_payment'], $order->id);

        // Evita duplicados del mismo carrito
        Cart::deActivateCart();

        $accessToken = core()->getConfigData('sales.payment_methods.mercadopago.access_token');

        if (empty($accessToken)) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Mercado Pago: falta configurar access_token en Admin.');
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        $amount   = (float) $order->grand_total;
        $currency = $order->order_currency_code ?? 'MXN';

        $payload = [
            'items' => [[
                'title'       => 'Pedido #' . $order->increment_id,
                'quantity'    => 1,
                'unit_price'  => $amount,
                'currency_id' => $currency,
            ]],

            // ✅ una sola ruta de retorno (MP manda status/payment_id en query)
            'back_urls' => [
                'success' => route('mercadopago.return'),
                'failure' => route('mercadopago.return'),
                'pending' => route('mercadopago.return'),
            ],

            // 🔑 enlaza el pago al pedido
            'external_reference' => (string) $order->id,
        ];

        // En local (.test/http) MP suele rechazar auto_return y webhook.
        // En producción https sí lo activamos sin cambiar código.
        if (str_starts_with(config('app.url'), 'https://')) {
            $payload['auto_return'] = 'approved';
            $payload['notification_url'] = route('mercadopago.webhook');
        }

        try {
            $client = new PreferenceClient();
            $preference = $client->create($payload);

            if (empty($preference->init_point)) {
                Log::error('MP preference sin init_point', ['pref' => $preference, 'payload' => $payload]);
                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', 'Mercado Pago: no se pudo generar el link de pago.');
            }

            return redirect()->away($preference->init_point);

        } catch (MPApiException $e) {
            $apiResponse = method_exists($e, 'getApiResponse') ? $e->getApiResponse() : null;

            Log::error('MercadoPago MPApiException (redirect)', [
                'status'  => $apiResponse ? $apiResponse->getStatusCode() : null,
                'content' => $apiResponse ? $apiResponse->getContent() : null,
                'payload' => $payload,
            ]);

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Mercado Pago: error al crear la preferencia.');
        }
    }

    /**
     * 2) Retorno del usuario desde MP (fallback UX).
     * Confirma el pago consultando a MP y marca la orden.
     */
    public function return(Request $request)
    {
        Log::info('MercadoPago return', ['query' => $request->query()]);

        $paymentId = $request->get('payment_id') ?? $request->get('collection_id');

        if (! $paymentId) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Mercado Pago: no se recibió payment_id.');
        }

        $accessToken = core()->getConfigData('sales.payment_methods.mercadopago.access_token');

        if (empty($accessToken)) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Mercado Pago: access_token no configurado.');
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        try {
            $paymentClient = new PaymentClient();
            $payment = $paymentClient->get((int) $paymentId);

            if (($payment->status ?? null) !== 'approved') {
                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', 'Pago no aprobado (' . ($payment->status ?? 'unknown') . ').');
            }

            $orderId = (int) ($payment->external_reference ?? 0);
            if (! $orderId) {
                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', 'No se pudo vincular el pago al pedido (external_reference).');
            }

            $order = $this->orderRepository->find($orderId);

            if (! $order) {
                return redirect()->route('shop.checkout.cart.index')
                    ->with('error', 'Pedido no encontrado.');
            }

            $this->confirmOrder($order);

            session()->flash('order', $order);

            return redirect()->route('shop.checkout.onepage.success');

        } catch (\Throwable $e) {
            Log::error('MercadoPago return error', ['msg' => $e->getMessage()]);
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Mercado Pago: error al confirmar el pago.');
        }
    }

    /**
     * 3) Webhook (producción): confirma pago aunque el usuario no regrese.
     */
    public function webhook(Request $request)
    {
        Log::info('MP Webhook', $request->all());

        if (($request->type ?? null) !== 'payment') {
            return response()->json(['ok' => true]);
        }

        $paymentId = $request->input('data.id');

        if (! $paymentId) {
            return response()->json(['ok' => true]);
        }

        $accessToken = core()->getConfigData('sales.payment_methods.mercadopago.access_token');

        if (empty($accessToken)) {
            return response()->json(['ok' => true]);
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        try {
            $paymentClient = new PaymentClient();
            $payment = $paymentClient->get((int) $paymentId);

            if (($payment->status ?? null) !== 'approved') {
                return response()->json(['ok' => true]);
            }

            $orderId = (int) ($payment->external_reference ?? 0);
            if (! $orderId) {
                return response()->json(['ok' => true]);
            }

            $order = $this->orderRepository->find($orderId);

            if ($order) {
                $this->confirmOrder($order);
            }

        } catch (\Throwable $e) {
            Log::error('MercadoPago webhook error', ['msg' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    protected function confirmOrder($order): void
    {
        if ($order->status !== 'processing') {
            $this->orderRepository->update(['status' => 'processing'], $order->id);
        }

        if ($order->canInvoice()) {
            $this->invoiceRepository->create($this->prepareInvoiceData($order));
        }
    }

    protected function prepareInvoiceData($order): array
    {
        $data = ['order_id' => $order->id];

        foreach ($order->items as $item) {
            $data['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }

        return $data;
    }
}
