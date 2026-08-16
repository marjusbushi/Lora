<?php

namespace App\Http\Controllers;

use App\Models\HotelFaq;
use App\Models\HotelFaqSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FAQ e hotelit — njohuritë nga të cilat Lora AI Chat u përgjigjet mysafirëve.
 * CRUD i thjeshtë per-tenant (HotelFaq është TenantModel — izolimi vjen nga
 * global scope; cross-tenant id → 404 nga route-model-binding i skopuar).
 */
class HotelFaqController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:2000'],
        ]);

        HotelFaq::create($data + [
            'sort_order' => (int) (HotelFaq::query()->max('sort_order') + 1),
        ]);

        return back()->with('success', 'Pyetja u shtua — Lora do ta përdorë që tani.');
    }

    public function update(Request $request, HotelFaq $faq): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $faq->update($data);

        return back()->with('success', 'Pyetja u përditësua.');
    }

    public function destroy(HotelFaq $faq): RedirectResponse
    {
        $faq->delete();

        return back()->with('success', 'Pyetja u fshi.');
    }

    /**
     * Cikli i mësimit (task #334): pranon një sugjerim (pyetje që Lora s'e
     * dinte + përgjigjja e stafit) — teksti mund të vijë i redaktuar nga UI.
     * FAQ e re + shënimi 'saved' janë një veprim i vetëm (transaksion).
     */
    public function acceptSuggestion(Request $request, HotelFaqSuggestion $suggestion): RedirectResponse
    {
        if ($suggestion->status !== HotelFaqSuggestion::STATUS_PENDING) {
            return back()->with('error', 'Ky sugjerim është trajtuar tashmë.');
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($suggestion, $data) {
            HotelFaq::create($data + [
                'sort_order' => (int) (HotelFaq::query()->max('sort_order') + 1),
            ]);
            $suggestion->update(['status' => HotelFaqSuggestion::STATUS_SAVED]);
        });

        return back()->with('success', 'U shtua te FAQ — Lora e mësoi këtë përgjigje.');
    }

    public function dismissSuggestion(HotelFaqSuggestion $suggestion): RedirectResponse
    {
        if ($suggestion->status !== HotelFaqSuggestion::STATUS_PENDING) {
            return back()->with('error', 'Ky sugjerim është trajtuar tashmë.');
        }

        $suggestion->update(['status' => HotelFaqSuggestion::STATUS_DISMISSED]);

        return back()->with('success', 'Sugjerimi u hodh.');
    }
}
