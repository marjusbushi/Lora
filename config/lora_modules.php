<?php

return [
    'annual_discount_percent' => 20,

    'modules' => [
        'core' => [
            'name' => 'Lora Core',
            'description' => 'Rezervime, dhoma, mysafirë, folio dhe raporte.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 2900,
        ],
        'channel_manager' => [
            'name' => 'Channel Manager',
            'description' => 'Sinkronizim me OTA-t dhe marrje automatike e rezervimeve.',
            'billing_model' => 'tiered_per_room',
            'unit_label' => 'dhomë',
            'unit_price_cents' => 700,
            'tier_limit' => 50,
            'excess_unit_price_cents' => 500,
        ],
        'booking_engine' => [
            'name' => 'Booking Online',
            'description' => 'Booking engine direkt dhe pagesa online.',
            'billing_model' => 'percentage',
            'unit_label' => 'rezervim direkt',
            'percentage_bps' => 100,
        ],
        'housekeeping' => [
            'name' => 'Housekeeping',
            'description' => 'Pastrimi, checklistat dhe raportimi i problemeve.',
            'billing_model' => 'per_user',
            'unit_label' => 'përdorues',
            'unit_price_cents' => 900,
        ],
        'pos' => [
            'name' => 'POS Bar/Restorant',
            'description' => 'Porosi, turne dhe pika shitjeje.',
            'billing_model' => 'per_pos',
            'unit_label' => 'pikë shitjeje',
            'unit_price_cents' => 1900,
        ],
        'finance' => [
            'name' => 'Financa & Inventari',
            'description' => 'Arka, banka, pagesa, fatura blerjeje, furnitorë, artikuj dhe magazina.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 2900,
        ],
        'smart_pricing' => [
            'name' => 'Çmime Inteligjente',
            'description' => 'Sugjerime çmimesh dhe autopilot.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 1900,
        ],
    ],
];
