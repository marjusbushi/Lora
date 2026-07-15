<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ReservationFiscalizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReservationFiscalizationController extends Controller
{
    public function store(
        Reservation $reservation,
        ReservationFiscalizationService $fiscalization,
    ): RedirectResponse {
        try {
            $document = $fiscalization->fiscalize($reservation);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()->withErrors(['fiscalization' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Fatura sandbox u fiskalizua me sukses%s.',
            $document->fiscal_number ? ' · '.$document->fiscal_number : '',
        ));
    }
}
