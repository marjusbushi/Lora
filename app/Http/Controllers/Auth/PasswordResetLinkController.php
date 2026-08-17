<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // PËRGJIGJE NEUTRALE. Sjellja e Breeze-it kthente "nuk gjejmë përdorues me
        // këtë email" — pra faqja u tregonte të panjohurve se cilat adresa KANË
        // llogari këtu (numërim llogarish). Tani një email i panjohur duket
        // saktësisht si një i njohur: kush e ka llogarinë merr emailin, kush provon
        // adresa të huaja nuk mëson asgjë.
        // Sinjal, jo tekst: stringjet e dukshme jetojnë te resources/js/locales,
        // ndaj faqja e përkthen vetë (lang/ i serverit s'ka fare skedarë auth).
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return back()->with('status', 'password-reset-link-sent');
        }

        // Kufizimi i shpeshtësisë mbetet i dukshëm: ai s'zbulon ekzistencën e
        // llogarisë (vlen edhe për adresa të panjohura) dhe përdoruesi duhet ta dijë
        // pse s'po ndodh asgjë.
        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
