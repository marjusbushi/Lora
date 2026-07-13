<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Services\GuestMergeAdvisor;
use App\Services\GuestMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GuestMergeController extends Controller
{
    public function show(Guest $guest, Guest $duplicate, GuestMergeAdvisor $advisor, GuestMergeService $service): Response
    {
        abort_unless($service->arePotentialDuplicates($guest, $duplicate), 404);

        return Inertia::render('Guests/Merge', [
            'profiles' => [$this->profile($guest), $this->profile($duplicate)],
            'suggestion' => $advisor->fallbackAdvice($guest, $duplicate),
            'fields' => GuestMergeService::FIELDS,
        ]);
    }

    public function suggest(Guest $guest, Guest $duplicate, GuestMergeAdvisor $advisor, GuestMergeService $service): JsonResponse
    {
        abort_unless($service->arePotentialDuplicates($guest, $duplicate), 404);

        return response()->json(['suggestion' => $advisor->advise($guest, $duplicate)]);
    }

    public function store(Request $request, Guest $guest, Guest $duplicate, GuestMergeService $service): RedirectResponse
    {
        abort_unless($service->arePotentialDuplicates($guest, $duplicate), 404);
        $ids = [$guest->id, $duplicate->id];
        $rules = [
            'primary_id' => ['required', 'integer', Rule::in($ids)],
            'field_sources' => ['required', 'array:'.implode(',', GuestMergeService::FIELDS)],
            'suggestion_source' => ['required', 'in:ai,fallback,manual'],
        ];
        foreach (GuestMergeService::FIELDS as $field) {
            $rules["field_sources.{$field}"] = ['required', 'integer', Rule::in($ids)];
        }
        $data = $request->validate($rules);

        $result = $service->merge(
            $guest,
            $duplicate,
            (int) $data['primary_id'],
            $data['field_sources'],
            (int) $request->user()->id,
            $data['suggestion_source'],
        );

        return redirect()->route('guests.show', $result['primary_id'])->with('success', 'Profilet u bashkuan me sukses.');
    }

    /** @return array<string,mixed> */
    private function profile(Guest $guest): array
    {
        return array_merge($guest->only(GuestMergeService::FIELDS), [
            'id' => $guest->id,
            'full_name' => $guest->full_name,
            'created_at' => $guest->created_at?->toDateString(),
            'tags' => $guest->tags ?? [],
            'counts' => [
                'reservations' => $guest->reservations()->withTrashed()->count(),
                'documents' => $guest->documents()->count(),
                'invoices' => $guest->invoices()->count(),
                'reviews' => $guest->reviews()->count(),
            ],
        ]);
    }
}
