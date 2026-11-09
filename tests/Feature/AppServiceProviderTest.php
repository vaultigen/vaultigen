<?php

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

test('the application uses immutable dates', function () {
    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class);
});

test('password defaults are strict outside local and testing', function (string $environment) {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    $this->app->detectEnvironment(fn () => $environment);

    (new AppServiceProvider($this->app))->boot();

    $rule = Password::defaults();
    $validator = Validator::make(['password' => 'weak'], ['password' => $rule]);

    expect($validator->fails())->toBeTrue();
})->with(['production', 'staging', 'demo']);
