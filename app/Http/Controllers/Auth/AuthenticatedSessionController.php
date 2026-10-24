<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The one place a session is created or destroyed.
 *
 * - Session regeneration on login defends against session fixation; full
 *   invalidation and token rotation on logout means a stolen pre-logout
 *   session id is useless afterwards.
 * - Credentials that fail for either reason (unknown email or wrong
 *   password) hit the same branch and the same message, so a caller cannot
 *   tell which it was.
 * - CSRF is not handled here: it is the `web` middleware group's
 *   ValidateCsrfToken, already applied to these routes.
 *
 * Rate limiting is implemented directly against the cache rather than via
 * the RateLimiter facade: RateLimiter::hit()'s decay argument only takes
 * effect on the first call for a key, so a growing decay on later failed
 * attempts is silently ignored. The facade cannot express per-attempt
 * exponential backoff, only a single fixed window. Tracking the lockout
 * deadline explicitly avoids that trap. The deadline is stored as a Unix
 * timestamp (an int), never a Carbon object: config/cache.php sets
 * `serializable_classes` to false, so a serializing store (Redis) would hand
 * a Carbon back as __PHP_Incomplete_Class and every retry would 500.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): InertiaResponse
    {
        return Inertia::render('Login');
    }

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request);
        $lockedUntil = Cache::get("login-lockout:{$throttleKey}");

        if ($lockedUntil !== null && now()->getTimestamp() < $lockedUntil) {
            $secondsRemaining = max(1, $lockedUntil - now()->getTimestamp());

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$secondsRemaining} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials)) {
            $attempts = (int) Cache::get("login-attempts:{$throttleKey}", 0) + 1;
            Cache::put("login-attempts:{$throttleKey}", $attempts, now()->addHour());

            // Exponential backoff: each further failed attempt at least
            // doubles the next wait (2s, 4s, 8s, ...), capped at 5 minutes.
            $decaySeconds = min(300, 2 ** $attempts);
            Cache::put("login-lockout:{$throttleKey}", now()->addSeconds($decaySeconds)->getTimestamp(), now()->addHour());

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        Cache::forget("login-attempts:{$throttleKey}");
        Cache::forget("login-lockout:{$throttleKey}");

        $request->session()->regenerate();

        return response()->json(['redirect' => '/dashboard']);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => '/login']);
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->string('email'))).'|'.$request->ip();
    }
}
