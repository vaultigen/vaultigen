<?php

use App\Services\Operations\InstallationState;

it('accepts only a generated-length database password for server databases', function (string $driver, string $password, ?string $problem): void {
    config(['database.default' => $driver, "database.connections.{$driver}.password" => $password]);

    $result = app(InstallationState::class)->databasePasswordProblem();

    $problem === null ? expect($result)->toBeNull() : expect($result)->toContain($problem);
})->with([
    'placeholder' => ['pgsql', 'change-me', 'placeholder value'],
    'one character too short' => ['pgsql', str_repeat('x', 23), 'at least 24 characters'],
    'generated password' => ['pgsql', str_repeat('x', 24), null],
    'SQLite without a password' => ['sqlite', '', null],
]);
