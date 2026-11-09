<?php

use App\Http\Middleware\EnsureCryptographyAvailable;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('panel requests fail closed when the keyring is unavailable', function () {
    config(['vaultigen.kek_provider' => 'environment', 'vaultigen.kek_keyring' => null]);
    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/')->assertStatus(503);
});

test('the middleware rejects missing sodium and a missing keyring', function () {
    $request = Request::create('/');
    $next = fn () => response('ok');

    $missingSodium = new class extends EnsureCryptographyAvailable
    {
        protected function sodiumLoaded(): bool
        {
            return false;
        }
    };

    try {
        $missingSodium->handle($request, $next);
        $this->fail('Expected an unavailable response.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }

    config(['vaultigen.kek_provider' => 'environment', 'vaultigen.kek_keyring' => null]);
    try {
        (new EnsureCryptographyAvailable)->handle($request, $next);
        $this->fail('Expected an unavailable response.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }

    config(['vaultigen.kek_keyring' => json_encode([
        'active_key_id' => 'kek-test-v1',
        'keys' => ['kek-test-v1' => base64_encode(str_repeat('k', 32))],
    ])]);
    expect((new EnsureCryptographyAvailable)->handle($request, $next)->getContent())->toBe('ok');
});
