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
        // RESET_THROTTLED duhet të jetë neutral bashkë me të tjerët (gjetje P1 e
        // Codex): brokeri e kthen INVALID_USER PARA se të kontrollojë throttle-in,
        // ndaj dy dërgime brenda 60 sekondave jepnin gabim VETËM për adresat që
        // ekzistojnë — i njëjti orakull, një hap më thellë. Dhe s'gënjejmë duke e
        // quajtur sukses: linku U DËRGUA vërtet, nga kërkesa e parë.
        // Mbrojtja nga abuzimi nuk varet më nga ky mesazh — POST-i ka throttle
        // uniform në rrugë, që godet njësoj pavarësisht se ç'adresë futet.
        // Sinjal, jo tekst: stringjet e dukshme jetojnë te resources/js/locales,
        // ndaj faqja e përkthen vetë (lang/ i serverit s'ka fare skedarë auth).
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', 'password-reset-link-sent');
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
