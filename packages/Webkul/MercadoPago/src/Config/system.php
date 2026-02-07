<?php

return [
    [
        'key'    => 'sales.payment_methods.mercadopago',
        'name'   => 'Mercado Pago', // título que verás en el admin
        'info'   => 'Configura tu integración con Mercado Pago', // texto informativo
        'sort'   => 5,
        'fields' => [
            [
                'name'          => 'title',
                'title'         => 'Título',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
                'default_value' => 'Mercado Pago',
            ],
            [
                'name'          => 'description',
                'title'         => 'Descripción',
                'type'          => 'textarea',
                'channel_based' => false,
                'locale_based'  => true,
                'default_value' => 'Paga de forma segura con Mercado Pago',
            ],
            [
                'name'          => 'active',
                'title'         => 'Estado',
                'type'          => 'boolean',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
                'default_value' => false,
            ],
            [
                'name'          => 'access_token',
                'title'         => 'Access Token',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
            ],
            [
                'name'          => 'public_key',
                'title'         => 'Public Key',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
            ],
        ],
    ],
];