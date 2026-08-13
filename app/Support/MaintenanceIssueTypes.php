<?php

namespace App\Support;

/**
 * Curated catalog of common maintenance issue types, grouped by the existing
 * category enum. The KEY is what gets stored (language-independent — this is
 * what the Recurring Maintenance report fingerprints on); each UI language
 * renders its own label from resources/js/locales (maintenance.issueTypes.*,
 * kept in sync with LABELS_SQ below — the server-side Albanian label used to
 * derive the issue title, since the product is Albanian-first).
 */
final class MaintenanceIssueTypes
{
    /** @var array<string, list<string>> category => type keys */
    public const TYPES = [
        'electronics' => ['tv_not_working', 'tv_no_signal', 'remote_missing', 'phone_not_working', 'safe_not_working', 'minibar_fridge_broken', 'kettle_broken', 'iron_broken', 'washing_machine_broken', 'kitchen_stove_broken', 'kitchen_hood_broken'],
        'climate' => ['ac_not_cooling', 'ac_leaking', 'ac_noisy', 'heating_not_working'],
        'electrical' => ['light_bulb_out', 'lamp_fixture_broken', 'power_socket_broken', 'no_power', 'hair_dryer_broken'],
        'plumbing' => ['boiler_no_hot_water', 'water_leak', 'blocked_drain', 'toilet_flush_broken', 'wc_seat_broken', 'sanitary_fixture_damage', 'shower_cabin_damage', 'low_water_pressure', 'shower_head_broken', 'faucet_dripping'],
        'furniture' => ['door_lock_broken', 'door_handle_broken', 'balcony_door_broken', 'window_not_closing', 'curtains_broken', 'bed_damaged', 'mattress_damaged', 'mirror_broken', 'wardrobe_damaged', 'chair_table_damaged'],
        'safety' => ['smoke_detector_fault', 'fire_extinguisher_missing', 'balcony_railing_loose', 'emergency_light_out', 'key_card_reader_fault'],
        'other' => ['wifi_not_working', 'elevator_fault', 'pest_control', 'wall_paint_damage', 'floor_tile_damage'],
    ];

    /** Albanian labels — the server derives the issue title from these. */
    public const LABELS_SQ = [
        'tv_not_working' => 'Televizori nuk punon',
        'tv_no_signal' => 'Televizori pa sinjal',
        'kettle_broken' => 'Ibriku elektrik i prishur',
        'iron_broken' => 'Hekuri i hekurosjes i prishur',
        'washing_machine_broken' => 'Lavatriçja nuk punon',
        'kitchen_stove_broken' => 'Soba/pianura nuk punon',
        'kitchen_hood_broken' => 'Aspiratori nuk punon',
        'lamp_fixture_broken' => 'Ndriçuesi/abazhuri i prishur',
        'wc_seat_broken' => 'Kapaku i WC-së i thyer',
        'sanitary_fixture_damage' => 'Hidrosanitare të dëmtuara (lavaman/WC/vaskë)',
        'shower_cabin_damage' => 'Kabina e dushit e dëmtuar',
        'balcony_door_broken' => 'Dera e ballkonit nuk mbyllet',
        'mattress_damaged' => 'Dysheku i dëmtuar',
        'mirror_broken' => 'Pasqyra e thyer',
        'remote_missing' => 'Telekomanda mungon ose nuk punon',
        'phone_not_working' => 'Telefoni i dhomës nuk punon',
        'safe_not_working' => 'Kasaforta nuk punon',
        'minibar_fridge_broken' => 'Minibari/frigoriferi nuk punon',
        'ac_not_cooling' => 'Kondicioneri nuk ftoh',
        'ac_leaking' => 'Kondicioneri pikon',
        'ac_noisy' => 'Kondicioneri bën zhurmë',
        'heating_not_working' => 'Ngrohja nuk punon',
        'light_bulb_out' => 'Llambë e djegur',
        'power_socket_broken' => 'Prizë e prishur',
        'no_power' => 'Nuk ka korrent',
        'boiler_no_hot_water' => 'Bojleri / s\'ka ujë të ngrohtë',
        'hair_dryer_broken' => 'Tharësja e flokëve e prishur',
        'water_leak' => 'Rrjedhje uji',
        'blocked_drain' => 'Kanalizim i bllokuar',
        'toilet_flush_broken' => 'Kazaneta e WC-së e prishur',
        'low_water_pressure' => 'Presion i ulët i ujit',
        'shower_head_broken' => 'Koka e dushit e prishur',
        'faucet_dripping' => 'Rubineti pikon',
        'door_lock_broken' => 'Brava e derës e prishur',
        'door_handle_broken' => 'Doreza e derës e prishur',
        'window_not_closing' => 'Dritarja nuk mbyllet',
        'curtains_broken' => 'Perdet/grilat e prishura',
        'bed_damaged' => 'Krevati i dëmtuar',
        'wardrobe_damaged' => 'Garderoba e dëmtuar',
        'chair_table_damaged' => 'Karrige/tavolinë e dëmtuar',
        'smoke_detector_fault' => 'Detektori i tymit me defekt',
        'fire_extinguisher_missing' => 'Fikësja e zjarrit mungon/skaduar',
        'balcony_railing_loose' => 'Parmaku i ballkonit i liruar',
        'emergency_light_out' => 'Drita e emergjencës nuk punon',
        'key_card_reader_fault' => 'Lexuesi i kartës me defekt',
        'wifi_not_working' => 'Wi-Fi / interneti nuk punon',
        'elevator_fault' => 'Ashensori me defekt',
        'pest_control' => 'Insekte / dezinsektim',
        'wall_paint_damage' => 'Dëmtim i bojës së murit',
        'floor_tile_damage' => 'Dëmtim i pllakave/dyshemesë',
    ];

    /** @return list<string> every valid key */
    public static function keys(): array
    {
        return array_merge(...array_values(self::TYPES));
    }

    public static function labelSq(string $key): string
    {
        return self::LABELS_SQ[$key] ?? $key;
    }
}
