<?php

namespace App\Http\Controllers;

use App\Models\SavedReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedReportController extends Controller
{
    private const ROUTES = [
        'reports.executive', 'reports.channels', 'reports.outstanding', 'reports.shifts',
        'reports.guests', 'reports.posSales', 'reports.arrivalsManifest', 'reports.departuresManifest',
        'reports.pace', 'reports.cancellations', 'reports.payments', 'reports.vat',
        'reports.performance', 'reports.repeatGuests', 'reports.guestSegments', 'reports.nationality',
        'reports.bookingBehavior', 'reports.posHourly', 'reports.posPaymentMix', 'reports.posVoids',
        'reports.stockValuation', 'reports.supplierPerformance', 'reports.roomStatus',
        'reports.housekeepingReport', 'reports.maintenanceSla', 'reports.recurringMaintenance',
        'reports.roomReadiness', 'reports.operationsExecutive', 'reports.guestMovements',
        'reports.inHouse', 'reports.discounts', 'reports.departmentRevenue',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'saved_reports' => SavedReport::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'route_name' => ['required', Rule::in(self::ROUTES)],
            'filters' => ['nullable', 'array'],
            'filters.from' => ['nullable', 'date_format:Y-m-d'],
            'filters.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.from'],
            'frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'delivery_email' => ['nullable', 'required_with:frequency', 'email:rfc', 'max:255'],
        ]);

        $report = new SavedReport($data);
        $report->user_id = $request->user()->id;
        $report->scheduleNext();
        $report->save();

        return response()->json(['saved_report' => $report], 201);
    }

    public function destroy(Request $request, SavedReport $savedReport): JsonResponse
    {
        abort_unless($savedReport->user_id === $request->user()->id, 403);
        $savedReport->delete();

        return response()->json(status: 204);
    }
}
