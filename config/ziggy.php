<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Route-name groups exposed to the frontend
    |--------------------------------------------------------------------------
    |
    | Hotel-facing pages receive the "hotel" group: the full route map MINUS
    | the Lora control panel. Only control-panel hosts get the unfiltered map
    | (their Vue pages call route('super-admin.*')). Keeps the super-admin
    | URL surface out of every hotel visitor's HTML.
    |
    */
    'groups' => [
        'hotel' => ['!super-admin.*'],
    ],
];
