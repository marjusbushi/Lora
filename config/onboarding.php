<?php

return [
    'steps' => [
        'hotel' => [
            'title' => 'Të dhënat e hotelit',
            'description' => 'Identiteti, domaini dhe lokalizimi.',
            'tasks' => [
                'profile' => ['title' => 'Profili i hotelit', 'description' => 'Emri, adresa dhe kontaktet', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=hotel']],
                'localization' => ['title' => 'Monedha dhe timezone', 'description' => 'Monedha bazë dhe zona kohore', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=hotel']],
                'domain' => ['title' => 'Domain primar', 'description' => 'Domaini është shtuar dhe verifikuar', 'action' => ['type' => 'control', 'tab' => 'domains']],
            ],
        ],
        'rooms' => [
            'title' => 'Dhomat dhe struktura',
            'description' => 'Tipologjitë, dhomat dhe kapacitetet.',
            'tasks' => [
                'room_types' => ['title' => 'Tipet e dhomave', 'description' => 'Tipologjitë dhe komoditetet', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=room-types']],
                'rooms' => ['title' => 'Dhomat', 'description' => 'Numrat, katet dhe statuset', 'action' => ['type' => 'tenant', 'path' => '/rooms']],
                'capacity' => ['title' => 'Kapacitetet', 'description' => 'Të rritur, fëmijë dhe krevate', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=room-types']],
            ],
        ],
        'pricing' => [
            'title' => 'Çmimet dhe politikat',
            'description' => 'Tarifat, sezonet, taksat dhe politikat.',
            'tasks' => [
                'rate_plan' => ['title' => 'Plani tarifor standard', 'description' => 'Tarifa bazë fleksibël', 'action' => ['type' => 'tenant', 'path' => '/pricing']],
                'seasons' => ['title' => 'Sezonet dhe fundjavat', 'description' => 'Periudhat dhe diferencat e çmimeve', 'action' => ['type' => 'tenant', 'path' => '/pricing']],
                'cancellation' => ['title' => 'Politika e anulimit', 'description' => 'Afatet dhe penalitetet', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=pricing-programs']],
                'taxes' => ['title' => 'Taksat e hotelit', 'description' => 'TVSH dhe taksa e qytetit', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=financial']],
            ],
        ],
        'users' => [
            'title' => 'Përdoruesit dhe rolet',
            'description' => 'Pronari, ekipi dhe lejet.',
            'tasks' => [
                'owner' => ['title' => 'Administratori i hotelit', 'description' => 'Pronari ka akses aktiv', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=users']],
                'staff' => ['title' => 'Ekipi i hotelit', 'description' => 'Ftesat dhe përdoruesit', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=users']],
                'roles' => ['title' => 'Rolet dhe lejet', 'description' => 'Aksesi është kontrolluar', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=users']],
            ],
        ],
        'finance' => [
            'title' => 'Financa dhe pagesat',
            'description' => 'Arka, bankat dhe mënyrat e pagesës.',
            'tasks' => [
                'cash' => ['title' => 'Arka kryesore', 'description' => 'Arka në monedhën bazë', 'action' => ['type' => 'tenant', 'path' => '/finance/accounts']],
                'bank' => ['title' => 'Llogaria bankare', 'description' => 'Banka dhe të dhënat e pagesës', 'action' => ['type' => 'tenant', 'path' => '/finance/accounts']],
                'payment_methods' => ['title' => 'Mënyrat e pagesës', 'description' => 'Cash, kartë dhe pagesa online', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=financial']],
            ],
        ],
        'pos_inventory' => [
            'title' => 'POS dhe inventari',
            'description' => 'Pikat e shitjes, magazinat dhe produktet.',
            'tasks' => [
                'pos' => ['title' => 'Pika POS', 'description' => 'Bar, restorant ose shërbime', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=menu']],
                'warehouse' => ['title' => 'Magazina qendrore', 'description' => 'Magazinat dhe stoku fillestar', 'action' => ['type' => 'tenant', 'path' => '/inventory/warehouses']],
                'products' => ['title' => 'Produktet dhe çmimet', 'description' => 'Artikujt, fotot dhe çmimet', 'action' => ['type' => 'tenant', 'path' => '/inventory/items']],
            ],
        ],
        'integrations' => [
            'title' => 'Integrimet',
            'description' => 'Fiskalizimi, kanalet, pagesat dhe kurset e këmbimit.',
            'tasks' => [
                'fature_al' => ['title' => 'fature.al', 'description' => 'Sandbox dhe production', 'action' => ['type' => 'control', 'tab' => 'fature']],
                'channex' => ['title' => 'Channex', 'description' => 'Kanalet dhe sinkronizimi', 'action' => ['type' => 'control', 'tab' => 'channex']],
                'payments' => ['title' => 'Pagesat online', 'description' => 'POK dhe link pagesash', 'action' => ['type' => 'control', 'tab' => 'pok']],
                'exchange_rates' => ['title' => 'ExchangeRate API', 'description' => 'API key dhe rifreskimi i kurseve', 'action' => ['type' => 'tenant', 'path' => '/settings?tab=currencies']],
            ],
        ],
        'testing' => [
            'title' => 'Testet dhe aktivizimi',
            'description' => 'Kontrollet fundore para dorëzimit.',
            'tasks' => [
                'reservation' => ['title' => 'Rezervim prove', 'description' => 'Rezervim, check-in dhe check-out', 'action' => ['type' => 'tenant', 'path' => '/reservations']],
                'pos_sale' => ['title' => 'Shitje POS', 'description' => 'Porosi, pagesë dhe zbritje stoku', 'action' => ['type' => 'tenant', 'path' => '/pos']],
                'finance_report' => ['title' => 'Faturë dhe raport', 'description' => 'Fatura, pagesa dhe raporti financiar', 'action' => ['type' => 'tenant', 'path' => '/reports']],
            ],
        ],
    ],
];
