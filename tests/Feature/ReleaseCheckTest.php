<?php

/**
 * Writes a Docker Compose environment template that preselects the given image tag.
 */
function releaseEnvTemplate(string $imageTag): string
{
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-env-');
    file_put_contents($path, "# Exact image release.\nVAULTIGEN_IMAGE_TAG={$imageTag}\nVAULTIGEN_DOMAIN=vault.example.com\n");

    return $path;
}

test('a release tag passes when version, release date, and image tag match', function () {
    $this->travelTo('2026-10-03');
    config(['vaultigen.version' => '1.2.0', 'vaultigen.release_date' => '2026-10-01']);

    $this->artisan('vaultigen:release:check', ['tag' => 'v1.2.0', '--env-template' => releaseEnvTemplate('1.2.0')])
        ->expectsOutput('Release v1.2.0 (2026-10-01) is consistent.')
        ->assertSuccessful();
});

test('a release tag fails when the version or release date is wrong', function (string $tag, string $version, ?string $releaseDate, string $message) {
    $this->travelTo('2026-10-03');
    config(['vaultigen.version' => $version, 'vaultigen.release_date' => $releaseDate]);

    $this->artisan('vaultigen:release:check', ['tag' => $tag, '--env-template' => releaseEnvTemplate($version)])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'missing release date' => ['v1.2.0', '1.2.0', null, 'Set release_date in config/vaultigen.php'],
    'impossible release date' => ['v1.2.0', '1.2.0', '2026-02-30', 'Set release_date in config/vaultigen.php'],
    'not a date' => ['v1.2.0', '1.2.0', 'soon', 'Set release_date in config/vaultigen.php'],
    'stale release date' => ['v1.2.0', '1.2.0', '2026-09-20', 'is more than 7 days from today'],
    'future release date' => ['v1.2.0', '1.2.0', '2026-10-20', 'is more than 7 days from today'],
    'version mismatch' => ['v1.2.1', '1.2.0', '2026-10-01', 'does not match the version 1.2.0'],
    'development version' => ['v1.0.0', '1.0.0-dev', '2026-10-01', 'does not match the version 1.0.0-dev'],
    'not a release tag' => ['nightly', '1.2.0', '2026-10-01', 'is not a release tag'],
]);

test('a release tag fails when the Compose template preselects another image', function () {
    $this->travelTo('2026-10-03');
    config(['vaultigen.version' => '1.2.0', 'vaultigen.release_date' => '2026-10-01']);
    $template = releaseEnvTemplate('1.1.0');

    $this->artisan('vaultigen:release:check', ['tag' => 'v1.2.0', '--env-template' => $template])
        ->expectsOutputToContain("Set VAULTIGEN_IMAGE_TAG=1.2.0 in {$template}.")
        ->assertFailed();
});

test('a release tag fails when the Compose template is missing', function () {
    $this->travelTo('2026-10-03');
    config(['vaultigen.version' => '1.2.0', 'vaultigen.release_date' => '2026-10-01']);

    $this->artisan('vaultigen:release:check', ['tag' => 'v1.2.0', '--env-template' => 'scripts/docker/missing.example'])
        ->expectsOutputToContain('The Docker Compose environment template scripts/docker/missing.example is missing.')
        ->assertFailed();
});

test('the shipped Compose template is checked by default', function () {
    $this->travelTo('2026-10-03');
    config(['vaultigen.version' => '0.0.1', 'vaultigen.release_date' => '2026-10-01']);

    $this->artisan('vaultigen:release:check', ['tag' => 'v0.0.1'])
        ->expectsOutputToContain('Set VAULTIGEN_IMAGE_TAG=0.0.1 in scripts/docker/env.example.')
        ->assertFailed();
});

test('pre-release tags are accepted', function () {
    $this->travelTo('2026-10-01');
    config(['vaultigen.version' => '1.2.0-rc.1', 'vaultigen.release_date' => '2026-10-01']);

    $this->artisan('vaultigen:release:check', ['tag' => 'v1.2.0-rc.1', '--env-template' => releaseEnvTemplate('1.2.0-rc.1')])
        ->assertSuccessful();
});
