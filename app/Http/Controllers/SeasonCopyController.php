<?php

namespace App\Http\Controllers;

use App\Services\SeasonCopyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeasonCopyController extends Controller
{
    public function preview(Request $request, SeasonCopyService $seasonCopy): JsonResponse
    {
        $data = $this->validateBase($request);

        return response()->json($seasonCopy->preview(
            (int) $data['source_year'],
            (int) $data['target_year'],
            (float) $data['uplift_pct'],
        ));
    }

    public function apply(Request $request, SeasonCopyService $seasonCopy): JsonResponse
    {
        $data = $request->validate(array_merge($this->baseRules(), [
            'rules_version' => ['required', 'integer', 'min:0'],
            'preview_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'confirmed' => ['required', 'accepted'],
        ]));

        $result = $seasonCopy->apply(
            (int) $data['source_year'],
            (int) $data['target_year'],
            (float) $data['uplift_pct'],
            (int) $data['rules_version'],
            $data['preview_hash'],
        );

        $status = match ($result['state']) {
            'stale' => 409,
            'conflict' => 422,
            default => 200,
        };

        return response()->json($result, $status);
    }

    /** @return array<string, mixed> */
    private function validateBase(Request $request): array
    {
        return $request->validate($this->baseRules());
    }

    /** @return array<string, array<int, string>> */
    private function baseRules(): array
    {
        return [
            'source_year' => ['required', 'integer', 'between:2000,2100'],
            'target_year' => ['required', 'integer', 'between:2000,2100', 'different:source_year'],
            'uplift_pct' => ['required', 'numeric', 'between:-50,100'],
        ];
    }
}
