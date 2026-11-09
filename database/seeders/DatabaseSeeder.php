<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use App\Models\Vault;
use App\Services\AuditWriter;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\PersonalVaults;
use App\Services\ScopedGrants;
use App\Services\TeamAdministration;
use App\Services\VaultAdministration;
use App\Services\VaultFolders;
use App\Services\VaultMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(AuditWriter $audit): void
    {
        if (! app()->isLocal()) {
            return;
        }

        // Seeding is a local-only system operation that creates the demo
        // installation before any account holds a factor. The bypass applies to
        // this CLI process only; the stored policy and later requests still
        // require a second factor unless the developer sets the environment flag.
        config(['vaultigen.mfa_disabled' => true]);

        if (config('vaultigen.installation_id') === null) {
            $path = config('vaultigen.installation_file');
            if (! is_file($path) && Artisan::call('vaultigen:initialize', ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Unable to initialize the local installation.');
            }
            $installation = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            config([
                'vaultigen.installation_id' => $installation['installation_id'],
                'vaultigen.audit_key' => config('vaultigen.audit_key') ?? $installation['audit_key'],
                'vaultigen.passkey_secret' => config('vaultigen.passkey_secret') ?? $installation['passkey_secret'],
            ]);
            Artisan::call('config:clear', ['--no-interaction' => true]);
        }

        Artisan::call('vaultigen:kek:generate', ['--if-missing' => true]);

        $audit->transaction(function () use ($audit): void {
            $admin = User::firstOrNew(['email' => 'admin@admin.com']);
            $admin->forceFill([
                'name' => 'Admin', 'password' => 'password', 'email_verified_at' => now(),
                'is_active' => true, 'must_reset_password' => false,
            ])->save();
            DB::table('user_platform_roles')->insertOrIgnore([
                'user_id' => $admin->id,
                'platform_role_id' => DB::table('platform_roles')->where('name', 'super_admin')->value('id'),
            ]);
            DB::table('installation_settings')->where('id', 1)->update([
                'bootstrapped_at' => now(), 'installation_id' => config('vaultigen.installation_id'),
            ]);
            $audit->record('installation.local_seeded', $admin->id);
            app(PersonalVaults::class)->provisionFor($admin);
            $this->seedDevelopmentTeams($admin);
            $this->seedDevelopmentScenarios($admin);
            $this->call(AgencyDemoSeeder::class);
        });
    }

    private function seedDevelopmentTeams(User $admin): void
    {
        $userNames = [
            'alice@example.com' => 'Alice Johnson',
            'bob@example.com' => 'Bob Smith',
            'carol@example.com' => 'Carol Diaz',
            'dave@example.com' => 'David Weber',
            'erin@example.com' => 'Erin Novak',
            'frank@example.com' => 'Frank Miller',
        ];

        foreach ($userNames as $email => $name) {
            $this->seedUser($email, $name);
        }

        $teamMembers = [
            'Operations' => ['alice@example.com', 'bob@example.com', 'erin@example.com', 'frank@example.com'],
            'Engineering' => ['bob@example.com', 'carol@example.com', 'dave@example.com', 'frank@example.com'],
            'Support' => [],
        ];

        $teams = app(TeamAdministration::class);
        $teamIds = [];

        foreach (array_keys($teamMembers) as $name) {
            $normalized = Str::lower($name);
            if (! Team::query()->where('normalized_name', $normalized)->exists()) {
                $teams->save($admin, null, $name);
            }
            $teamIds[$name] = Team::query()->where('normalized_name', $normalized)->value('id');
        }

        foreach ($teamMembers as $team => $emails) {
            foreach ($emails as $email) {
                $userId = User::query()->where('normalized_email', $email)->value('id');
                $alreadyMember = DB::table('team_memberships')->where('team_id', $teamIds[$team])->where('user_id', $userId)->exists();
                if (! $alreadyMember) {
                    $teams->membership($admin, $teamIds[$team], $userId, true);
                }
            }
        }
    }

    private function seedUser(string $email, string $name): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name, 'password' => 'password', 'email_verified_at' => now(),
            'is_active' => true, 'must_reset_password' => false, 'policy_accepted_at' => now(),
        ])->save();
        app(PersonalVaults::class)->provisionFor($user);

        return $user;
    }

    /**
     * One vault-wide role scenario (Project 1) and one folder-scoped scenario
     * (Project 2) so access behaviour can be exercised without manual setup.
     */
    private function seedDevelopmentScenarios(User $admin): void
    {
        $manager = $this->seedUser('manager@manager.com', 'Manager Ben');
        $editor = $this->seedUser('editor@editor.com', 'Editor Eva');
        $viewer = $this->seedUser('viewer@viewer.com', 'Viewer Tom');

        $this->seedProjectOne($admin, $manager, $editor, $viewer);
        $this->seedProjectTwo($admin, $editor, $viewer);
    }

    private function seedProjectOne(User $admin, User $manager, User $editor, User $viewer): void
    {
        if (Vault::query()->where('normalized_name', 'project 1')->exists()) {
            return;
        }

        $vaultId = app(VaultAdministration::class)->create($admin, [
            'name' => 'Project 1',
            'description' => 'Vault-wide roles: Manager Ben owns and manages it, Editor Eva can create and edit entries, and Viewer Tom can only read and use them.',
            'icon' => 'briefcase',
            'primary_custodian_user_id' => $manager->id,
            'initial_manager_user_id' => $manager->id,
        ]);

        $membership = app(VaultMembership::class);
        $membership->grant($manager, $vaultId, $editor->principal_id, 'editor');
        $membership->grant($manager, $vaultId, $viewer->principal_id, 'viewer');

        $entries = app(EntryVault::class);

        $entries->create($manager, $vaultId, [
            'type' => EntryPayload::TYPE_LOGIN,
            'title' => 'Production email',
            'owner_user_id' => $manager->id,
            'expires_at' => null,
            'generated' => false,
            'username' => 'manager@example.com',
            'password' => 'Project1-Email-Pa55!',
            'url' => 'https://mail.example.com',
            'notes' => 'Shared project mailbox.',
        ]);

        $entries->create($manager, $vaultId, [
            'type' => EntryPayload::TYPE_SSH_KEY,
            'title' => 'Production web server (10.0.0.12)',
            'owner_user_id' => $manager->id,
            'expires_at' => null,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIProject1PublicKey deploy@10.0.0.12',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nProject1PrivateKeyExample\n-----END OPENSSH PRIVATE KEY-----",
            'passphrase' => 'Project1-SSH-Pa55!',
            'notes' => 'Host 10.0.0.12, user deploy.',
        ]);

        $entries->create($manager, $vaultId, [
            'type' => EntryPayload::TYPE_TOTP,
            'title' => 'Acme VPN',
            'owner_user_id' => $manager->id,
            'expires_at' => null,
            'issuer' => 'Acme Corp',
            'account' => 'manager@example.com',
            'secret' => 'JBSWY3DPEHPK3PXP',
            'algorithm' => 'SHA1',
            'digits' => '6',
            'period' => '30',
            'notes' => 'Second factor for the VPN.',
        ]);
    }

    private function seedProjectTwo(User $admin, User $editor, User $viewer): void
    {
        if (Vault::query()->where('normalized_name', 'project 2')->exists()) {
            return;
        }

        $vaultId = app(VaultAdministration::class)->create($admin, [
            'name' => 'Project 2',
            'description' => 'Folder-scoped access with no Manager: Admin is a whole-vault Editor, while Editor Eva and Viewer Tom can only reach the Team folder.',
            'icon' => 'users',
            'primary_custodian_user_id' => $admin->id,
            'initial_manager_user_id' => $admin->id,
        ]);

        $folderId = app(VaultFolders::class)->create($admin, $vaultId, 'Team');

        $entries = app(EntryVault::class);

        $entries->create($admin, $vaultId, [
            'type' => EntryPayload::TYPE_NOTE,
            'title' => 'Project 2 runbook',
            'owner_user_id' => $admin->id,
            'expires_at' => null,
            'body' => 'Root-level notes that only the whole-vault Editor can see.',
        ]);

        $entries->create($admin, $vaultId, [
            'type' => EntryPayload::TYPE_LOGIN,
            'title' => 'Team shared mailbox',
            'owner_user_id' => $admin->id,
            'expires_at' => null,
            'generated' => false,
            'username' => 'team@example.com',
            'password' => 'Project2-Team-Pa55!',
            'url' => 'https://mail.example.com',
            'notes' => 'Shared mailbox used by the Team folder members.',
            'folder_id' => $folderId,
        ]);

        $entries->create($admin, $vaultId, [
            'type' => EntryPayload::TYPE_SSH_KEY,
            'title' => 'Team jump host (10.0.1.5)',
            'owner_user_id' => $admin->id,
            'expires_at' => null,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIProject2JumpKey deploy@10.0.1.5',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nProject2JumpHostPrivateKey\n-----END OPENSSH PRIVATE KEY-----",
            'passphrase' => 'Project2-SSH-Pa55!',
            'notes' => 'Host 10.0.1.5, user deploy. Reachable through the Team folder only.',
            'folder_id' => $folderId,
        ]);

        $grants = app(ScopedGrants::class);
        $grants->grantOnFolder($admin, $folderId, $editor->principal_id, 'editor');
        $grants->grantOnFolder($admin, $folderId, $viewer->principal_id, 'viewer');

        // Project 2 deliberately has no Manager: replace the administrator's
        // temporary Manager grant with whole-vault Editor access.
        app(VaultMembership::class)->grant($admin, $vaultId, $admin->principal_id, 'editor');
    }
}
