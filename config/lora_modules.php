<?php

return [
    // Zbritja sipas gjatësisë së kontratës (vite => %). Penaliteti i prishjes
    // (gjysma e vlerës së pashfrytëzuar) rregullohet në dokumentin e kontratës,
    // jo në sistem — vendim i pronarit (2026-08-14).
    'contract_discounts' => [
        1 => 10,
        2 => 15,
        3 => 20,
        5 => 30,
    ],

    'modules' => [
        'core' => [
            'name' => 'Lora Core',
            'description' => 'Rezervime, dhoma, mysafirë, folio dhe raporte.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 2900,
            'calculator_default' => true,
        ],
        'channel_manager' => [
            'name' => 'Channel Manager',
            'description' => 'Sinkronizim me OTA-t dhe marrje automatike e rezervimeve.',
            'billing_model' => 'tiered_per_room',
            'unit_label' => 'dhomë',
            // €7/dhomë deri në 30 dhoma; vetëm dhomat MBI 30 me €5 (jo gjithë hoteli).
            'unit_price_cents' => 700,
            'tier_limit' => 30,
            'excess_unit_price_cents' => 500,
            'calculator_default' => true,
        ],
        'messages' => [
            'name' => 'Mesazhet',
            'description' => 'Inbox i unifikuar i mysafirëve nga OTA-t (Booking, Expedia…).',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 1900,
            'calculator_default' => true,
        ],
        'booking_engine' => [
            'name' => 'Booking Online',
            'description' => 'Booking engine direkt dhe pagesa online.',
            'billing_model' => 'percentage',
            'unit_label' => 'rezervim direkt',
            'percentage_bps' => 100,
            'calculator_default' => true,
        ],
        'housekeeping' => [
            'name' => 'Housekeeping',
            'description' => 'Pastrimi, checklistat dhe raportimi i problemeve.',
            'billing_model' => 'per_user',
            'unit_label' => 'përdorues',
            'unit_price_cents' => 900,
            'calculator_default' => false,
        ],
        'maintenance' => [
            'name' => 'Mirëmbajtja',
            'description' => 'Raportim defektesh, ndërhyrje dhe historiku i mirëmbajtjes.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 900,
            'calculator_default' => false,
        ],
        'pos' => [
            'name' => 'POS Bar/Restorant',
            'description' => 'Porosi, turne dhe pika shitjeje — me fiskalizim të përfshirë dhe pa limit përdoruesish.',
            'billing_model' => 'per_pos',
            'unit_label' => 'pikë shitjeje',
            // Pika e parë €49 (mbulon fiskalizimin + përdorues pa limit); çdo pikë shtesë €19.
            'first_unit_price_cents' => 4900,
            'unit_price_cents' => 1900,
            'calculator_default' => false,
        ],
        'finance' => [
            'name' => 'Financa & Inventari',
            'description' => 'Arka, banka, pagesa, fatura blerjeje, furnitorë, artikuj dhe magazina.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 2900,
            'calculator_default' => false,
        ],
        'smart_pricing' => [
            'name' => 'Çmime Inteligjente',
            'description' => 'Sugjerime çmimesh dhe autopilot.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 4900,
            'calculator_default' => false,
        ],
        'beach' => [
            'name' => 'Plazhi',
            'description' => 'Rezervim shezllonesh online dhe porosi me QR nga çadra.',
            'billing_model' => 'flat',
            'unit_label' => 'muaj',
            'unit_price_cents' => 2900,
            'calculator_default' => false,
        ],
    ],
];
