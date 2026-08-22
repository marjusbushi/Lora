<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Models\Setting;
use App\Services\DirectBookingPricing;
use App\Services\PublicRoomPricing;
use App\Support\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web Studio (task #411) — paneli i VETËM i webit publik. Konsolidon çfarë
 * ishte shpërndarë në 4 vende (tekstet e hero-s te Hoteli, logo/foto/social te
 * Faqja Web, faqja Rreth Nesh, dhomat te Tipet e dhomave) pa lëvizur asnjë
 * çelës ruajtjeje: shkruhen po ata `hotel.*`/`about.*` që lexon faqja publike.
 */
class WebStudioController extends Controller
{
    public function index(PublicRoomPricing $publicPricing, DirectBookingPricing $directPricing): Response
    {
        $hotel = Setting::getGroup('hotel');
        $about = Setting::getGroup('about');

        // Karta e dhomave tregon çmimin që sheh VËRTET vizitori — e njëjta
        // rrugë si Home publike (from_price sipas disponueshmërisë + zbritjes
        // direkte), jo base_price i brendshëm (gjetje Codex, PR #562).
        $roomTypes = RoomType::with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->orderBy('base_price')
            ->get(['id', 'name', 'description', 'base_price', 'max_occupancy']);
        $fromPrices = $publicPricing->fromPrices($roomTypes);

        $home = [
            'hero_eyebrow_sq' => $hotel['hero_eyebrow_sq'] ?? null,
            'hero_eyebrow_en' => $hotel['hero_eyebrow_en'] ?? null,
            'hero_title_sq' => $hotel['hero_title_sq'] ?? null,
            'hero_title_en' => $hotel['hero_title_en'] ?? null,
            'hero_subtitle_sq' => $hotel['hero_subtitle_sq'] ?? null,
            'hero_subtitle_en' => $hotel['hero_subtitle_en'] ?? null,
            'hero_image' => $hotel['hero_image'] ?? null,
        ];
        $contact = [
            'address' => $hotel['address'] ?? null,
            'phone' => $hotel['phone'] ?? null,
            'email' => $hotel['email'] ?? null,
            'whatsapp_number' => $hotel['whatsapp_number'] ?? null,
            'instagram' => $hotel['instagram'] ?? null,
            'facebook' => $hotel['facebook'] ?? null,
            'maps_url' => $hotel['maps_url'] ?? null,
        ];

        return Inertia::render('WebStudio/Index', [
            'home' => $home,
            'brand' => ['logo' => $hotel['logo'] ?? null],
            'contact' => $contact,
            'about' => $about,
            'hotelName' => $hotel['name'] ?? '',
            // Karta read-only të dhomave siç dalin në sajt — editimi mbetet te
            // Tipet e dhomave (asnjë dublikim editori).
            'roomTypes' => $roomTypes->map(fn (RoomType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                // null = pa disponueshmëri të shitshme → karta s'tregon çmim (si publikja).
                'from_price' => $directPricing->fromPrice($fromPrices[$type->id] ?? null)['direct'],
                'max_occupancy' => $type->max_occupancy,
                'image' => $type->images->first()?->path,
            ]),
            // Pikat e statusit në rail — çfarë i mungon çdo faqeje.
            'completeness' => [
                'home' => filled($home['hero_image']) && filled($home['hero_title_sq']),
                'about' => filled($about['story_p1_sq'] ?? null),
                'contact' => filled($contact['phone']) && (filled($contact['instagram']) || filled($contact['facebook'])),
                'brand' => filled($hotel['logo'] ?? null),
            ],
        ]);
    }

    /** Kreu: 6 tekstet e hero-s + fotoja kryesore. Çelësa identikë me faqen publike. */
    public function updateHome(Request $request): RedirectResponse
    {
        $request->validate([
            'hero_eyebrow_sq' => ['nullable', 'string', 'max:120'],
            'hero_eyebrow_en' => ['nullable', 'string', 'max:120'],
            'hero_title_sq' => ['nullable', 'string', 'max:200'],
            'hero_title_en' => ['nullable', 'string', 'max:200'],
            'hero_subtitle_sq' => ['nullable', 'string', 'max:400'],
            'hero_subtitle_en' => ['nullable', 'string', 'max:400'],
            'hero_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
        ]);

        foreach ([
            'hero_eyebrow_sq', 'hero_eyebrow_en',
            'hero_title_sq', 'hero_title_en',
            'hero_subtitle_sq', 'hero_subtitle_en',
        ] as $key) {
            Setting::set("hotel.{$key}", $request->input($key));
        }

        $this->storeImage($request, 'hero_image', 'hotel.hero_image');

        return back()->with('success', 'Faqja Kreu u ruajt.');
    }

    /** Marka: logo e sajtit (i njëjti çelës + dosje si updateWebsite i vjetër). */
    public function updateBrand(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:3072'],
        ]);

        $this->storeImage($request, 'logo', 'hotel.logo');

        return back()->with('success', 'Marka u ruajt.');
    }

    /** Kontakti dhe rrjetet — çfarë sheh vizitori te faqja publike. */
    public function updateContact(Request $request): RedirectResponse
    {
        $request->validate([
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:30', 'regex:/^[+\d][\d\s\-()]*$/'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'maps_url' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['address', 'phone', 'email', 'instagram', 'facebook', 'maps_url'] as $key) {
            Setting::set("hotel.{$key}", $request->input($key));
        }

        // Normalizim identik me updateHotel: wa.me refuzon prefiksin 00 (task #340).
        $whatsapp = trim((string) $request->input('whatsapp_number'));
        Setting::set('hotel.whatsapp_number', $whatsapp === '' ? null : preg_replace('/^00/', '+', $whatsapp));

        return back()->with('success', 'Kontakti u ruajt.');
    }

    /** Ruajtje imazhi në branding/ me fshirjen e të vjetrit — sjellja e updateWebsite. */
    private function storeImage(Request $request, string $field, string $settingKey): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        $old = Setting::get($settingKey);
        $path = $request->file($field)->store(TenantStorage::path('branding'), 'public');
        Setting::set($settingKey, $path, 'image');
        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }
}
