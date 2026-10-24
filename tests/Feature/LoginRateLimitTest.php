<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// This is the first real test of the login/logout/session-invalidation flow
// against an actual HTTP session: tests that use actingAs() never exercise a
// real POST /login.

// Laravel's test HTTP client does not carry cookies between separate requests
// within a test (each one only sends what was attached via withCookie()), so
// "session id changed after login" can only be tested for real by forwarding
// the exact (already-encrypted) session cookie value the server issued, the
// way a browser would.
function sessionCookieValue(TestResponse $response): ?string
{
    $name = config('session.cookie');

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie->getValue();
        }
    }

    return null;
}

test('a user can log in with valid credentials and gets a fresh, regenerated session', function () {
    $user = User::factory()->admin()->create(['password' => bcrypt('correct-password')]);
    $sessionCookieName = config('session.cookie');

    $preLogin = $this->get('/login');
    $originalSessionId = sessionCookieValue($preLogin);
    expect($originalSessionId)->not->toBeNull();

    $response = $this->withUnencryptedCookie($sessionCookieName, $originalSessionId)
        ->postJson('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJson(['redirect' => '/dashboard']);

    expect(auth()->id())->toBe($user->id);

    // Session regenerated on login, not merely a coincidentally different id
    // from an unrelated session: defends against fixation.
    $newSessionId = sessionCookieValue($response);
    expect($newSessionId)->not->toBeNull();
    expect($newSessionId)->not->toBe($originalSessionId);
});

test('logout invalidates the session and clears the guard', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertOk();

    expect(auth()->check())->toBeTrue();

    $this->postJson('/logout')->assertOk()->assertJson(['redirect' => '/login']);

    expect(auth()->check())->toBeFalse();
});

test('an unknown email and a wrong password produce the identical generic error message', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $wrongPassword = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'not-the-right-password',
    ])->assertStatus(422)->json();

    $unknownEmail = $this->postJson('/login', [
        'email' => 'nobody-with-this-email@example.test',
        'password' => 'anything',
    ])->assertStatus(422)->json();

    // The caller cannot distinguish "email exists, password wrong" from
    // "email does not exist".
    expect($wrongPassword['fields']['email'][0])->toBe($unknownEmail['fields']['email'][0]);
    expect(auth()->check())->toBeFalse();
});

test('a failed attempt locks out the next attempt, and each further failure at least doubles the wait', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $throttleKey = Str::transliterate(Str::lower($user->email)).'|127.0.0.1';

    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
    expect(Cache::get("login-attempts:{$throttleKey}"))->toBe(1);
    $firstDecay = Cache::get("login-lockout:{$throttleKey}") - now()->getTimestamp();

    // Immediately retrying with the *correct* password is still rejected by
    // the lockout itself, before credentials are even checked.
    $locked = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertStatus(422)->json();
    expect($locked['fields']['email'][0])->toContain('Too many login attempts');
    expect(auth()->check())->toBeFalse();

    // Force the first lockout to have expired so the next failure is actually
    // evaluated, then confirm the second lockout window is longer.
    Cache::put("login-lockout:{$throttleKey}", now()->subSecond()->getTimestamp(), now()->addHour());
    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
    expect(Cache::get("login-attempts:{$throttleKey}"))->toBe(2);
    $secondDecay = Cache::get("login-lockout:{$throttleKey}") - now()->getTimestamp();

    expect($secondDecay)->toBeGreaterThan($firstDecay);
});

test('a successful login clears any accumulated rate-limit state', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $throttleKey = Str::transliterate(Str::lower($user->email)).'|127.0.0.1';

    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
    Cache::put("login-lockout:{$throttleKey}", now()->subSecond()->getTimestamp(), now()->addHour());

    $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertOk();

    expect(Cache::get("login-attempts:{$throttleKey}"))->toBeNull();
    expect(Cache::get("login-lockout:{$throttleKey}"))->toBeNull();
});

test('the lockout survives a cache store that serializes values', function () {
    // Redis (the .env.example default) serializes cached values, and
    // config/cache.php forbids unserializing classes, so a Carbon stored as the
    // lockout deadline came back as __PHP_Incomplete_Class and the retry 500ed.
    // The array store only does the same when asked to serialize.
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');

    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);

    $locked = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertStatus(422)->json();

    expect($locked['fields']['email'][0])->toContain('Too many login attempts');
});
