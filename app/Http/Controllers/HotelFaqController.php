<?php

namespace App\Http\Controllers;

use App\Models\HotelFaq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
}
