<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Models\Amenity;
use App\Models\CleaningTask;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\AuditTimeline;
use App\Services\CurrencyRates;
use App\Services\MarketRates;
use App\Services\PricingRulesVersion;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(
        Request $request,
        UserController $userController,
        AuditLogController $auditLogController,
        AuditTimeline $timeline,
    ): Response
    {
        $settings = Setting::allGrouped();

        // Never ship the raw AI key to the browser — expose only a masked hint + a configured flag.
        $aiKey = $settings['ai']['gemini_key'] ?? null;
        $settings['ai'] = [
            'gemini_configured' => ! empty($aiKey) || ! empty(config('services.gemini.key')),
            'gemini_key_hint' => $aiKey ? str_repeat('•', 6).substr((string) $aiKey, -4) : null,
            'ai_hotel_context' => Setting::get('ai.hotel_context', ''),
            'gemini_from_env' => empty($aiKey) && ! empty(config('services.gemini.key')),
        ];

        // Currencies (daily fx rates): same rule — never ship the raw key.
        $curKey = trim((string) ($settings['currencies']['api_key'] ?? ''));
        $settings['currencies'] = [
            'enabled' => (bool) ($settings['currencies']['enabled'] ?? false),
            'configured' => $curKey !== '',
            'api_key_hint' => $curKey !== '' ? str_repeat('•', 6).substr($curKey, -4) : null,
            'rates' => CurrencyRates::rates(),
            'updated_at' => CurrencyRates::updatedAt(),
            'tracked' => CurrencyRates::CURRENCIES,
            'fallback_all' => (float) Setting::get('financial.fx_all_per_eur', 0) ?: null,
        ];

        // Rate shopping (market_rates): same rule — never ship the raw key.
        $marketKey = trim((string) ($settings['market_rates']['api_key'] ?? ''));
        $settings['market_rates'] = [
            'enabled' => (bool) ($settings['market_rates']['enabled'] ?? false),
            'configured' => $marketKey !== '',
            'api_key_hint' => $marketKey !== '' ? str_repeat('•', 6).substr($marketKey, -4) : null,
            'competitors' => MarketRates::competitors(),
            'frequency' => MarketRates::frequency(),
            'search_query' => MarketRates::searchQuery(),
        ];

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'checklistDefaults' => CleaningTask::DEFAULT_CHECKLISTS,
            'roomTypes' => RoomType::withCount('rooms')->with('images')->orderBy('name')->get(),
            'menuCategories' => MenuCategory::with([
                'items' => fn ($q) => $q->with('inventoryComponents')->orderBy('name'),
            ])
                ->orderBy('sort_order')
                ->get(),
            'inventoryItems' => InventoryItem::where('is_active', true)->where('type', '!=', 'service')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit']),
            'inventoryWarehouses' => Warehouse::where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type']),
            'floors' => Floor::orderBy('number')->get(),
            'amenities' => Amenity::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'userManagement' => $userController->pageData($request, 'user_'),
            'auditHistory' => $auditLogController->pageData($request, $timeline, 'audit_'),
        ]);
    }

    // --- Floors (Katet) ---
    public function storeFloor(Request $request): RedirectResponse
    {
        $request->validate([
            'number' => ['required', 'integer', 'min:0', 'max:255', TenantRule::unique('floors', 'number')],
            'name' => ['required', 'string', 'max:100'],
        ]);

        Floor::create($request->only('number', 'name'));

        return back()->with('success', 'Kati u shtua.');
    }

    public function updateFloor(Request $request, Floor $floor): RedirectResponse
    {
        $request->validate([
            'number' => ['required', 'integer', 'min:0', 'max:255', TenantRule::unique('floors', 'number')->ignore($floor->id)],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $floor->update($request->only('number', 'name'));

        return back()->with('success', 'Kati u perditesua.');
    }

    public function destroyFloor(Floor $floor): RedirectResponse
    {
        $roomsOnFloor = Room::where('floor', $floor->number)->count();
        if ($roomsOnFloor > 0) {
            return back()->with('error', "Nuk mund te fshihet — ka {$roomsOnFloor} dhoma ne katin {$floor->number}. Ndrysho katin e atyre dhomave se pari.");
        }

        $floor->delete();

        return back()->with('success', 'Kati u fshi.');
    }

    // --- Hotel Info ---
    public function updateHotel(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'string', 'max:50'],
            'currency' => ['required', 'string', 'in:EUR,ALL,USD,GBP'],
            'check_in_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'check_out_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:3072'],
            // Hero text shown at the top of the public Home page, editable per language (Albanian + English).
            'hero_eyebrow_sq' => ['nullable', 'string', 'max:120'],
            'hero_eyebrow_en' => ['nullable', 'string', 'max:120'],
            'hero_title_sq' => ['nullable', 'string', 'max:200'],
            'hero_title_en' => ['nullable', 'string', 'max:200'],
            'hero_subtitle_sq' => ['nullable', 'string', 'max:400'],
            'hero_subtitle_en' => ['nullable', 'string', 'max:400'],
        ]);

        foreach ([
            'name', 'address', 'phone', 'email', 'timezone', 'currency', 'check_in_time', 'check_out_time',
            'hero_eyebrow_sq', 'hero_eyebrow_en',
            'hero_title_sq', 'hero_title_en',
            'hero_subtitle_sq', 'hero_subtitle_en',
        ] as $key) {
            Setting::set("hotel.{$key}", $request->input($key));
        }

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store(TenantStorage::path('logos'), 'public');
            Setting::set('hotel.logo', $path, 'image');
        }

        return back()->with('success', 'Informacionet e hotelit u ruajten.');
    }

    // --- Website (public site media + links) ---
    public function updateWebsite(Request $request): RedirectResponse
    {
        $request->validate([
            'instagram' => ['nullable', 'string', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'maps_url' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:3072'],
            'hero_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
        ]);

        foreach (['instagram', 'facebook', 'maps_url'] as $key) {
            Setting::set("hotel.{$key}", $request->input($key));
        }

        if ($request->hasFile('logo')) {
            $oldLogo = Setting::get('hotel.logo');
            $path = $request->file('logo')->store(TenantStorage::path('branding'), 'public');
            Setting::set('hotel.logo', $path, 'image');
            if ($oldLogo) {
                Storage::disk('public')->delete($oldLogo);
            }
        }

        if ($request->hasFile('hero_image')) {
            $oldHero = Setting::get('hotel.hero_image');
            $path = $request->file('hero_image')->store(TenantStorage::path('branding'), 'public');
            Setting::set('hotel.hero_image', $path, 'image');
            if ($oldHero) {
                Storage::disk('public')->delete($oldHero);
            }
        }

        return back()->with('success', 'Faqja web u perditesua.');
    }

    /**
     * Manage the public /about page content — texts (bilingual SQ/EN) + photos
     * per section. Stored in the 'about' settings group; rendered by
     * WebsiteController::about() with i18n fallbacks so an unconfigured page
     * still looks complete.
     */
    public function updateAbout(Request $request): RedirectResponse
    {
        // Bilingual headings/paragraphs + short stat values. Paragraphs allow more text.
        $textKeys = [
            'hero_title_sq', 'hero_title_en',
            'story_title_sq', 'story_title_en',
            'story_p1_sq', 'story_p1_en',
            'story_p2_sq', 'story_p2_en',
            'stat1_value', 'stat1_label_sq', 'stat1_label_en',
            'stat2_value', 'stat2_label_sq', 'stat2_label_en',
            'stat3_value', 'stat3_label_sq', 'stat3_label_en',
            'staff_title_sq', 'staff_title_en',
            'staff_p1_sq', 'staff_p1_en',
            'staff_p2_sq', 'staff_p2_en',
        ];

        $rules = [
            'hero_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
            'story_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
            'staff_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
        ];
        foreach ($textKeys as $key) {
            if (str_contains($key, '_value')) {
                $rules[$key] = ['nullable', 'string', 'max:30'];          // "15+", "4.8"
            } elseif (str_contains($key, '_p1') || str_contains($key, '_p2')) {
                $rules[$key] = ['nullable', 'string', 'max:1500'];        // paragraphs
            } else {
                $rules[$key] = ['nullable', 'string', 'max:200'];         // titles + labels
            }
        }

        $request->validate($rules);

        foreach ($textKeys as $key) {
            Setting::set("about.{$key}", $request->input($key));
        }

        foreach (['hero_image', 'story_image', 'staff_image'] as $imgKey) {
            if ($request->hasFile($imgKey)) {
                $old = Setting::get("about.{$imgKey}");
                $path = $request->file($imgKey)->store(TenantStorage::path('about'), 'public');
                Setting::set("about.{$imgKey}", $path, 'image');
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            }
        }

        return back()->with('success', 'Faqja "Rreth Nesh" u perditesua.');
    }

    // --- Financial ---
    public function updateFinancial(Request $request): RedirectResponse
    {
        $request->validate([
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['in:cash,card,room_charge'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'channel_fees' => ['nullable', 'array'],
            'channel_fees.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::set('financial.tax_rate', $request->tax_rate, 'number');
        Setting::set('financial.payment_methods', $request->payment_methods, 'json');
        Setting::set('financial.default_currency_symbol', $request->currency_symbol);

        // Per-channel commission % — Direct is first-party and always commission-free.
        $fees = [];
        foreach ((array) $request->input('channel_fees', []) as $channel => $pct) {
            if ($channel !== 'direct' && in_array($channel, Reservation::CHANNELS, true) && is_numeric($pct)) {
                $fees[$channel] = round((float) $pct, 2);
            }
        }
        Setting::set('financial.channel_fees', $fees, 'json');

        return back()->with('success', 'Konfigurimet financiare u ruajten.');
    }

    // --- OTA pricing programs (Booking.com / Expedia) ---
    public function updatePricingPrograms(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direct_discount_enabled' => ['sometimes', 'boolean'],
            'direct_discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'booking_genius_enabled' => ['required', 'boolean'],
            'booking_genius_pct' => ['required', 'numeric', 'min:0', 'max:50'],
            'booking_mobile_enabled' => ['required', 'boolean'],
            'booking_mobile_pct' => ['required', 'numeric', 'min:0', 'max:50'],
            'booking_preferred_enabled' => ['required', 'boolean'],
            'expedia_member_enabled' => ['required', 'boolean'],
            'expedia_member_pct' => ['required', 'numeric', 'min:0', 'max:50'],
            'expedia_mobile_enabled' => ['required', 'boolean'],
            'expedia_mobile_pct' => ['required', 'numeric', 'min:0', 'max:50'],
        ]);

        DB::transaction(function () use ($data) {
            $version = PricingRulesVersion::lock();
            foreach ($data as $key => $value) {
                Setting::set(
                    "pricing_programs.{$key}",
                    is_bool($value) ? ($value ? '1' : '0') : round((float) $value, 2),
                    is_bool($value) ? 'boolean' : 'number',
                );
            }
            PricingRulesVersion::increment($version);
        });

        // The programs feed ChannelSync's per-channel rate compensation, so a
        // changed percentage must re-push every mapped room type's rates —
        // otherwise the OTAs keep selling on the OLD factor until the nightly
        // full sync. No-op when Channex is not configured.
        PushRoomTypeAri::dispatchAllMapped();

        return back()->with('success', 'Programet e çmimeve u ruajtën — webi u përditësua dhe tarifat OTA po ridërgohen.');
    }

    // --- Housekeeping ---
    public function updateHousekeeping(Request $request): RedirectResponse
    {
        $request->validate([
            'task_types' => ['required', 'array', 'min:1'],
            'task_types.*' => ['string', 'max:50'],
            'auto_create_on_checkout' => ['required', 'boolean'],
            'default_priority' => ['required', 'in:normal,urgent'],
            'checklists' => ['nullable', 'array'],
            'checklists.*' => ['array'],
            // nullable: blank rows become null via TrimStrings/ConvertEmptyStringsToNull —
            // the sanitize step below drops them rather than rejecting the whole save.
            'checklists.*.*' => ['nullable', 'string', 'max:200'],
        ]);

        // Sanitize server-side (don't trust the client): trim each item, drop blanks.
        $checklists = collect($request->input('checklists', []))
            ->map(fn ($list) => collect((array) $list)
                ->map(fn ($s) => trim((string) $s))
                ->filter(fn ($s) => $s !== '')
                ->values()
                ->all())
            ->all();

        Setting::set('housekeeping.task_types', $request->task_types, 'json');
        Setting::set('housekeeping.auto_create_on_checkout', $request->auto_create_on_checkout ? '1' : '0', 'boolean');
        Setting::set('housekeeping.default_priority', $request->default_priority);
        Setting::set('housekeeping.checklists', $checklists, 'json');

        return back()->with('success', 'Konfigurimet e housekeeping u ruajten.');
    }

    // --- AI (Gemini key for the Pricing Assistant) ---
    public function updateAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gemini_key' => ['nullable', 'string', 'max:200'],
            'hotel_context' => ['nullable', 'string', 'max:1000'],
            'clear' => ['nullable', 'boolean'],
        ]);

        if ($request->has('hotel_context')) {
            Setting::set('ai.hotel_context', trim((string) ($data['hotel_context'] ?? '')), 'text');
        }

        if ($request->boolean('clear')) {
            Setting::set('ai.gemini_key', '', 'text');

            return back()->with('success', 'Çelësi AI u hoq.');
        }

        $key = trim((string) ($data['gemini_key'] ?? ''));
        if ($key === '') {
            return back()->with('success', 'Asnjë ndryshim — fusha ishte bosh.');
        }

        Setting::set('ai.gemini_key', $key, 'text');

        return back()->with('success', 'Çelësi AI u ruajt. Asistenti i çmimeve tani është aktiv.');
    }

    // --- Currencies (daily fx rates for multi-currency finance) ---
    public function updateCurrencies(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:100'],
            'clear_key' => ['nullable', 'boolean'],
        ]);

        Setting::set('currencies.enabled', $data['enabled'] ? '1' : '0', 'boolean');
        if ($request->boolean('clear_key')) {
            Setting::set('currencies.api_key', '', 'text');
        } elseif (trim((string) ($data['api_key'] ?? '')) !== '') {
            Setting::set('currencies.api_key', trim($data['api_key']), 'text');
        }

        return back()->with('success', 'Monedhat u ruajtën.');
    }

    /** "Rifresko tani" — inline fetch so the owner sees fresh rates instantly. */
    public function refreshCurrencies(): RedirectResponse
    {
        if (! CurrencyRates::enabled()) {
            return back()->with('error', 'Aktivizo modulin dhe vendos çelësin API më parë.');
        }
        try {
            $count = app(CurrencyRates::class)->fetch();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Rifreskimi dështoi — kontrollo çelësin API.');
        }

        return back()->with('success', "U morën {$count} kurse të freskëta.");
    }

    // --- Market rates (rate shopping — competitor prices, Phase 1) ---
    public function updateMarketRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:200'],
            'clear_key' => ['nullable', 'boolean'],
            'competitors' => ['required', 'array', 'min:1', 'max:30'],
            // nullable: blank rows become null via ConvertEmptyStringsToNull —
            // the sanitize step below drops them rather than failing the save.
            'competitors.*' => ['nullable', 'string', 'max:120'],
            'frequency' => ['required', 'in:daily,3x_week'],
            'search_query' => ['required', 'string', 'max:120'],
        ]);

        // Trim + drop blank rows server-side (don't trust the client list).
        $competitors = collect($data['competitors'])
            ->map(fn ($c) => trim((string) $c))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->values()
            ->all();

        Setting::set('market_rates.enabled', $data['enabled'] ? '1' : '0', 'boolean');
        Setting::set('market_rates.competitors', $competitors, 'json');
        Setting::set('market_rates.frequency', $data['frequency'], 'text');
        Setting::set('market_rates.search_query', trim($data['search_query']), 'text');

        if ($request->boolean('clear_key')) {
            Setting::set('market_rates.api_key', '', 'text');
        } elseif (trim((string) ($data['api_key'] ?? '')) !== '') {
            // An empty field means "keep the stored key" — the form never
            // receives the real key back, only a masked hint.
            Setting::set('market_rates.api_key', trim($data['api_key']), 'text');
        }

        return back()->with('success', 'Çmimet e tregut u ruajtën.');
    }

    // --- Room Types CRUD ---
    public function storeRoomType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('room_types', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0.01'],
            'max_price' => ['nullable', 'numeric', 'min:0.01', function ($attr, $value, $fail) use ($request) {
                $min = $request->input('min_price');
                if ($value !== null && $value !== '' && $min !== null && $min !== '' && (float) $value < (float) $min) {
                    $fail('Çmimi maksimal duhet të jetë ≥ çmimit minimal.');
                }
            }],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'breakfast_included' => ['boolean'],
        ]);

        DB::transaction(function () use ($data) {
            $version = PricingRulesVersion::lock();
            RoomType::create($data);
            PricingRulesVersion::increment($version);
        }, 3);

        return back()->with('success', 'Tipi i dhomes u shtua.');
    }

    public function updateRoomType(Request $request, RoomType $roomType): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('room_types', 'name')->ignore($roomType->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0.01'],
            'max_price' => ['nullable', 'numeric', 'min:0.01', function ($attr, $value, $fail) use ($request) {
                $min = $request->input('min_price');
                if ($value !== null && $value !== '' && $min !== null && $min !== '' && (float) $value < (float) $min) {
                    $fail('Çmimi maksimal duhet të jetë ≥ çmimit minimal.');
                }
            }],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'breakfast_included' => ['boolean'],
        ]);

        DB::transaction(function () use ($data, $roomType) {
            $version = PricingRulesVersion::lock();
            $lockedType = RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
            $lockedType->fill($data);
            $engineChanged = $lockedType->isDirty(['base_price', 'min_price', 'max_price']);
            if ($lockedType->isDirty()) {
                $lockedType->save();
            }
            if ($engineChanged) {
                PricingRulesVersion::increment($version);
            }
        }, 3);

        return back()->with('success', 'Tipi i dhomes u perditesua.');
    }

    public function destroyRoomType(RoomType $roomType): RedirectResponse
    {
        $roomCount = DB::transaction(function () use ($roomType) {
            $version = PricingRulesVersion::lock();
            $lockedType = RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
            $count = $lockedType->rooms()->count();
            if ($count > 0) {
                return $count;
            }

            $lockedType->delete();
            PricingRulesVersion::increment($version);

            return 0;
        }, 3);

        if ($roomCount > 0) {
            return back()->with('error', "Nuk mund te fshihet — ka {$roomCount} dhoma te ketij tipi.");
        }

        return back()->with('success', 'Tipi i dhomes u fshi.');
    }

    // --- Amenities master list (create once, select on room types) ---
    public function storeAmenity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('amenities', 'name')],
        ]);

        Amenity::create([
            'name' => $data['name'],
            'sort_order' => (Amenity::max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'Pajisja u shtua.');
    }

    public function destroyAmenity(Amenity $amenity): RedirectResponse
    {
        // Removes the entry from the master list only; existing room types keep their saved names.
        $amenity->delete();

        return back()->with('success', 'Pajisja u fshi nga lista.');
    }

    // --- Menu Categories CRUD ---
    public function storeMenuCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('menu_categories', 'name')],
            'outlet' => ['nullable', 'in:bar,restaurant'],
            'warehouse_id' => ['nullable', TenantRule::exists('warehouses')->where('is_active', true)],
        ]);

        $maxOrder = MenuCategory::max('sort_order') ?? 0;
        MenuCategory::create($data + ['sort_order' => $maxOrder + 1]);

        return back()->with('success', 'Kategoria u shtua.');
    }

    public function updateMenuCategory(Request $request, MenuCategory $menuCategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('menu_categories', 'name')->ignore($menuCategory->id)],
            'outlet' => ['nullable', 'in:bar,restaurant'],
            'warehouse_id' => ['nullable', TenantRule::exists('warehouses')->where('is_active', true)],
        ]);

        $menuCategory->update($data);

        return back()->with('success', 'Kategoria u perditesua.');
    }

    public function destroyMenuCategory(MenuCategory $menuCategory): RedirectResponse
    {
        if ($menuCategory->items()->exists()) {
            return back()->with('error', "Nuk mund te fshihet — ka {$menuCategory->items()->count()} artikuj brenda.");
        }

        $menuCategory->delete();

        return back()->with('success', 'Kategoria u fshi.');
    }

    // --- Menu Items CRUD ---
    public function storeMenuItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'menu_category_id' => ['required', TenantRule::exists('menu_categories')],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'image' => ['nullable', 'image', 'max:2048'],
            'inventory_components' => ['nullable', 'array', 'max:20'],
            'inventory_components.*.inventory_item_id' => [
                'required', 'distinct', TenantRule::exists('inventory_items')->where('is_active', true)->whereNot('type', 'service'),
            ],
            'inventory_components.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999'],
        ]);

        $itemData = [
            'menu_category_id' => $data['menu_category_id'],
            'name' => $data['name'],
            'price' => $data['price'],
            'is_available' => true,
        ];

        if ($request->hasFile('image')) {
            $itemData['image_path'] = $request->file('image')->store(TenantStorage::path('menu'), 'public');
        }

        DB::transaction(function () use ($itemData, $data) {
            $item = MenuItem::create($itemData);
            $item->inventoryComponents()->createMany($data['inventory_components'] ?? []);
        });

        return back()->with('success', 'Artikulli u shtua.');
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'image' => ['nullable', 'image', 'max:2048'],
            'inventory_components' => ['nullable', 'array', 'max:20'],
            'inventory_components.*.inventory_item_id' => [
                'required', 'distinct', TenantRule::exists('inventory_items')->where('is_active', true)->whereNot('type', 'service'),
            ],
            'inventory_components.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999'],
        ]);

        $itemData = collect($data)->only('name', 'price')->all();

        if ($request->hasFile('image')) {
            // Delete old image
            if ($menuItem->image_path) {
                Storage::disk('public')->delete($menuItem->image_path);
            }
            $itemData['image_path'] = $request->file('image')->store(TenantStorage::path('menu'), 'public');
        }

        DB::transaction(function () use ($menuItem, $itemData, $data) {
            $menuItem->update($itemData);
            $menuItem->inventoryComponents()->delete();
            $menuItem->inventoryComponents()->createMany($data['inventory_components'] ?? []);
        });

        return back()->with('success', 'Artikulli u perditesua.');
    }

    public function toggleMenuItem(MenuItem $menuItem): RedirectResponse
    {
        $menuItem->update(['is_available' => ! $menuItem->is_available]);

        $status = $menuItem->is_available ? 'disponueshem' : 'jo disponueshem';

        return back()->with('success', "{$menuItem->name} tani eshte {$status}.");
    }

    public function destroyMenuItem(MenuItem $menuItem): RedirectResponse
    {
        $menuItem->delete();

        return back()->with('success', 'Artikulli u fshi.');
    }

    // --- Room Type Images ---
    public function uploadRoomTypeImages(Request $request, RoomType $roomType): RedirectResponse
    {
        // The client optimizes photos before upload (HEIC→JPG + downscaled to web size), so these
        // arrive as small JPEGs. The 15MB cap is a safety net for a raw file that skips the client
        // path; it also fits under php-fpm upload_max_filesize (25M) / post_max_size (30M).
        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:15360'], // 15MB per image
        ], [
            'images.required' => 'Zgjidh të paktën një foto.',
            'images.*.image' => 'Skedari duhet të jetë një foto (JPG, PNG ose WebP).',
            'images.*.max' => 'Fotoja është shumë e madhe — maksimumi 15MB.',
        ]);

        $maxOrder = $roomType->images()->max('sort_order') ?? -1;

        foreach ($request->file('images') as $image) {
            $path = $image->store(TenantStorage::path('room-types'), 'public');
            $roomType->images()->create([
                'path' => $path,
                'sort_order' => ++$maxOrder,
            ]);
        }

        return back()->with('success', count($request->file('images')).' foto u ngarkuan.');
    }

    public function deleteRoomTypeImage(RoomTypeImage $roomTypeImage): RedirectResponse
    {
        Storage::disk('public')->delete($roomTypeImage->path);
        $roomTypeImage->delete();

        return back()->with('success', 'Foto u fshi.');
    }

    public function reorderRoomTypeImages(Request $request, RoomType $roomType): RedirectResponse
    {
        $request->validate([
            'image_ids' => ['required', 'array'],
            'image_ids.*' => [TenantRule::exists('room_type_images')],
        ]);

        foreach ($request->image_ids as $index => $id) {
            RoomTypeImage::where('id', $id)->update(['sort_order' => $index]);
        }

        return back()->with('success', 'Renditja u perditesua.');
    }
}
