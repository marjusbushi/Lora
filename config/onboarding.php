<?php

return [
    'steps' => [
        'hotel' => [
            'title' => 'Të dhënat e hotelit',
            'description' => 'Identiteti, domaini dhe lokalizimi.',
            'tasks' => [
                'profile' => ['title' => 'Profili i hotelit', 'description' => 'Emri, adresa dhe kontaktet'],
                'localization' => ['title' => 'Monedha dhe timezone', 'description' => 'Monedha bazë dhe zona kohore'],
                'domain' => ['title' => 'Domain primar', 'description' => 'Domaini është shtuar dhe verifikuar'],
            ],
        ],
        'rooms' => [
            'title' => 'Dhomat dhe struktura',
            'description' => 'Tipologjitë, dhomat dhe kapacitetet.',
            'tasks' => [
                'room_types' => ['title' => 'Tipet e dhomave', 'description' => 'Tipologjitë dhe komoditetet'],
                'rooms' => ['title' => 'Dhomat', 'description' => 'Numrat, katet dhe statuset'],
                'capacity' => ['title' => 'Kapacitetet', 'description' => 'Të rritur, fëmijë dhe krevate'],
            ],
        ],
        'pricing' => [
            'title' => 'Çmimet dhe politikat',
            'description' => 'Tarifat, sezonet, taksat dhe politikat.',
            'tasks' => [
                'rate_plan' => ['title' => 'Plani tarifor standard', 'description' => 'Tarifa bazë fleksibël'],
                'seasons' => ['title' => 'Sezonet dhe fundjavat', 'description' => 'Periudhat dhe diferencat e çmimeve'],
                'cancellation' => ['title' => 'Politika e anulimit', 'description' => 'Afatet dhe penalitetet'],
                'taxes' => ['title' => 'Taksat e hotelit', 'description' => 'TVSH dhe taksa e qytetit'],
            ],
        ],
        'users' => [
            'title' => 'Përdoruesit dhe rolet',
            'description' => 'Pronari, ekipi dhe lejet.',
            'tasks' => [
                'owner' => ['title' => 'Administratori i hotelit', 'description' => 'Pronari ka akses aktiv'],
                'staff' => ['title' => 'Ekipi i hotelit', 'description' => 'Ftesat dhe përdoruesit'],
                'roles' => ['title' => 'Rolet dhe lejet', 'description' => 'Aksesi është kontrolluar'],
            ],
        ],
        'finance' => [
            'title' => 'Financa dhe pagesat',
            'description' => 'Arka, bankat dhe mënyrat e pagesës.',
            'tasks' => [
                'cash' => ['title' => 'Arka kryesore', 'description' => 'Arka në monedhën bazë'],
                'bank' => ['title' => 'Llogaria bankare', 'description' => 'Banka dhe të dhënat e pagesës'],
                'payment_methods' => ['title' => 'Mënyrat e pagesës', 'description' => 'Cash, kartë dhe pagesa online'],
            ],
        ],
        'pos_inventory' => [
            'title' => 'POS dhe inventari',
            'description' => 'Pikat e shitjes, magazinat dhe produktet.',
            'tasks' => [
                'pos' => ['title' => 'Pika POS', 'description' => 'Bar, restorant ose shërbime'],
                'warehouse' => ['title' => 'Magazina qendrore', 'description' => 'Magazinat dhe stoku fillestar'],
                'products' => ['title' => 'Produktet dhe çmimet', 'description' => 'Artikujt, fotot dhe çmimet'],
            ],
        ],
        'integrations' => [
            'title' => 'Integrimet',
            'description' => 'Fiskalizimi, kanalet dhe pagesat online.',
            'tasks' => [
                'fature_al' => ['title' => 'fature.al', 'description' => 'Sandbox dhe production'],
                'channex' => ['title' => 'Channex', 'description' => 'Kanalet dhe sinkronizimi'],
                'payments' => ['title' => 'Pagesat online', 'description' => 'POK dhe link pagesash'],
            ],
        ],
        'testing' => [
            'title' => 'Testet dhe aktivizimi',
            'description' => 'Kontrollet fundore para dorëzimit.',
            'tasks' => [
                'reservation' => ['title' => 'Rezervim prove', 'description' => 'Rezervim, check-in dhe check-out'],
                'pos_sale' => ['title' => 'Shitje POS', 'description' => 'Porosi, pagesë dhe zbritje stoku'],
                'finance_report' => ['title' => 'Faturë dhe raport', 'description' => 'Fatura, pagesa dhe raporti financiar'],
            ],
        ],
    ],
];
