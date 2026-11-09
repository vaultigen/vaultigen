<?php

namespace Tests;

use App\Models\User;
use App\Services\MfaPolicy;
use App\Services\SiteIcons\HostResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fixtures\FakeHostResolver;
use Tests\Fixtures\LicenseVectorBuilder;

abstract class TestCase extends BaseTestCase
{
    /**
     * Queued jobs run synchronously in tests, so saving any login would fetch
     * its site icon. The fake resolver resolves nothing unless a test says so.
     *
     * The release date is pinned to the one the test licenses are built for,
     * so moving the real release date never changes a license test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostResolver::class, FakeHostResolver::class);
        config(['vaultigen.release_date' => LicenseVectorBuilder::RELEASE_DATE]);
    }

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);
        if ($user instanceof User) {
            $this->withSession([
                'vaultigen.assurance' => app(MfaPolicy::class)->userMethod($user) === null ? 'setup' : 'strong',
                'vaultigen.auth_version' => $user->auth_version,
                'vaultigen.started_at' => now()->timestamp,
                'vaultigen.last_at' => now()->timestamp,
            ]);
        }

        return $this;
    }
}
