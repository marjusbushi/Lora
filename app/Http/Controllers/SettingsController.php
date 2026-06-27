<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use App\Models\Floor;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => Setting::allGrouped(),
            'roomTypes' => RoomType::withCount('rooms')->with('images')->orderBy('name')->get(),
            'menuCategories' => MenuCategory::with(['items' => fn($q) => $q->orderBy('name')])
                ->orderBy('sort_order')
                ->get(),
            'floors' => Floor::orderBy('number')->get(),
            'amenities' => Amenity::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // --- Floors (Katet) ---
    public function storeFloor(Request $request): RedirectResponse
    {
        $request->validate([
            'number' => ['required', 'integer', 'min:0', 'max:255', 'unique:floors,number'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        Floor::create($request->only('number', 'name'));

        return back()->with('success', 'Kati u shtua.');
    }

    public function updateFloor(Request $request, Floor $floor): RedirectResponse
    {
        $request->validate([
            'number' => ['required', 'integer', 'min:0', 'max:255', 'unique:floors,number,' . $floor->id],
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
            $path = $request->file('logo')->store('logos', 'public');
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
            $path = $request->file('logo')->store('branding', 'public');
            Setting::set('hotel.logo', $path, 'image');
            if ($oldLogo) {
                Storage::disk('public')->delete($oldLogo);
            }
        }

        if ($request->hasFile('hero_image')) {
            $oldHero = Setting::get('hotel.hero_image');
            $path = $request->file('hero_image')->store('branding', 'public');
            Setting::set('hotel.hero_image', $path, 'image');
            if ($oldHero) {
                Storage::disk('public')->delete($oldHero);
            }
        }

        return back()->with('success', 'Faqja web u perditesua.');
    }

    // --- Financial ---
    public function updateFinancial(Request $request): RedirectResponse
    {
        $request->validate([
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['in:cash,card,room_charge'],
            'currency_symbol' => ['required', 'string', 'max:5'],
        ]);

        Setting::set('financial.tax_rate', $request->tax_rate, 'number');
        Setting::set('financial.payment_methods', $request->payment_methods, 'json');
        Setting::set('financial.default_currency_symbol', $request->currency_symbol);

        return back()->with('success', 'Konfigurimet financiare u ruajten.');
    }

    // --- Housekeeping ---
    public function updateHousekeeping(Request $request): RedirectResponse
    {
        $request->validate([
            'task_types' => ['required', 'array', 'min:1'],
            'task_types.*' => ['string', 'max:50'],
            'auto_create_on_checkout' => ['required', 'boolean'],
            'default_priority' => ['required', 'in:normal,urgent'],
        ]);

        Setting::set('housekeeping.task_types', $request->task_types, 'json');
        Setting::set('housekeeping.auto_create_on_checkout', $request->auto_create_on_checkout ? '1' : '0', 'boolean');
        Setting::set('housekeeping.default_priority', $request->default_priority);

        return back()->with('success', 'Konfigurimet e housekeeping u ruajten.');
    }

    // --- Room Types CRUD ---
    public function storeRoomType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:room_types,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'breakfast_included' => ['boolean'],
        ]);

        RoomType::create($data);

        return back()->with('success', 'Tipi i dhomes u shtua.');
    }

    public function updateRoomType(Request $request, RoomType $roomType): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:room_types,name,' . $roomType->id],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'breakfast_included' => ['boolean'],
        ]);

        $roomType->update($data);

        return back()->with('success', 'Tipi i dhomes u perditesua.');
    }

    public function destroyRoomType(RoomType $roomType): RedirectResponse
    {
        if ($roomType->rooms()->exists()) {
            return back()->with('error', "Nuk mund te fshihet — ka {$roomType->rooms()->count()} dhoma te ketij tipi.");
        }

        $roomType->delete();

        return back()->with('success', 'Tipi i dhomes u fshi.');
    }

    // --- Amenities master list (create once, select on room types) ---
    public function storeAmenity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:amenities,name'],
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
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:menu_categories,name'],
        ]);

        $maxOrder = MenuCategory::max('sort_order') ?? 0;
        MenuCategory::create(['name' => $request->name, 'sort_order' => $maxOrder + 1]);

        return back()->with('success', 'Kategoria u shtua.');
    }

    public function updateMenuCategory(Request $request, MenuCategory $menuCategory): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:menu_categories,name,' . $menuCategory->id],
        ]);

        $menuCategory->update(['name' => $request->name]);

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
        $request->validate([
            'menu_category_id' => ['required', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $data = [
            'menu_category_id' => $request->menu_category_id,
            'name' => $request->name,
            'price' => $request->price,
            'is_available' => true,
        ];

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('menu', 'public');
        }

        MenuItem::create($data);

        return back()->with('success', 'Artikulli u shtua.');
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $data = $request->only('name', 'price');

        if ($request->hasFile('image')) {
            // Delete old image
            if ($menuItem->image_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($menuItem->image_path);
            }
            $data['image_path'] = $request->file('image')->store('menu', 'public');
        }

        $menuItem->update($data);

        return back()->with('success', 'Artikulli u perditesua.');
    }

    public function toggleMenuItem(MenuItem $menuItem): RedirectResponse
    {
        $menuItem->update(['is_available' => !$menuItem->is_available]);

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
        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:3072'], // 3MB per image
        ]);

        $maxOrder = $roomType->images()->max('sort_order') ?? -1;

        foreach ($request->file('images') as $image) {
            $path = $image->store('room-types', 'public');
            $roomType->images()->create([
                'path' => $path,
                'sort_order' => ++$maxOrder,
            ]);
        }

        return back()->with('success', count($request->file('images')) . ' foto u ngarkuan.');
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
            'image_ids.*' => ['exists:room_type_images,id'],
        ]);

        foreach ($request->image_ids as $index => $id) {
            RoomTypeImage::where('id', $id)->update(['sort_order' => $index]);
        }

        return back()->with('success', 'Renditja u perditesua.');
    }
}
