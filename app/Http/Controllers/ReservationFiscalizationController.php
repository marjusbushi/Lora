<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\FatureAlConfiguration;
use App\Services\ReservationFiscalizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReservationFiscalizationController extends Controller
{
    public function store(
        Reservation $reservation,
        ReservationFiscalizationService $fiscalization,
        FatureAlConfiguration $configuration,
    ): RedirectResponse {
        try {
            $document = $fiscalization->fiscalize($reservation);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()->withErrors(['fiscalization' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Fatura %s u fiskalizua me sukses%s.',
            $configuration->get('environment') === 'production' ? 'LIVE' : 'sandbox',
            $document->fiscal_number ? ' · '.$document->fiscal_number : '',
        ));
    }
}
