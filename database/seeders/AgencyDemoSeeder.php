<?php

namespace Database\Seeders;

use App\Models\Folder;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Models\Vault;
use App\Services\AuditWriter;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\Favorites;
use App\Services\PersonalVaults;
use App\Services\ScopedGrants;
use App\Services\TeamAdministration;
use App\Services\VaultAdministration;
use App\Services\VaultDirectory;
use App\Services\VaultFolders;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class AgencyDemoSeeder extends Seeder
{
    /**
     * Fictional agency accounts for local screenshots. Existing vaults and
     * credentials are left intact, including edits made after the first run.
     */
    public function run(AuditWriter $audit): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@admin.com')->first();
        if ($admin === null || ! $admin->isSuperAdmin()) {
            throw new RuntimeException('Run the local DatabaseSeeder before adding agency demo data.');
        }

        $mfaDisabled = config('vaultigen.mfa_disabled');
        config(['vaultigen.mfa_disabled' => true]);

        try {
            $audit->transaction(function () use ($admin): void {
                $this->seedUserAuthentication();
                $teams = $this->seedTeams($admin);
                $tags = $this->seedTags($admin);

                foreach ($this->clients() as $name => $project) {
                    $this->seedSharedVault($admin, $name, $project, $this->clientEntries($name, $project), $teams, $tags);
                }

                $this->seedSharedVault($admin, 'Agency HQ', 'Internal tools, infrastructure, and studio operations.', $this->agencyEntries(), $teams, $tags);
                $this->seedPersonalEntries($admin, $tags);
            });
        } finally {
            config(['vaultigen.mfa_disabled' => $mfaDisabled]);
        }
    }

    private function seedUserAuthentication(): void
    {
        $methods = [
            'alice@example.com' => 'email',
            'bob@example.com' => 'app',
            'carol@example.com' => 'app',
            'dave@example.com' => 'app',
            'erin@example.com' => 'email',
            'frank@example.com' => 'email',
        ];

        foreach ($methods as $email => $method) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null || $user->mfaMethods() !== []) {
                continue;
            }

            if ($method === 'email') {
                $user->toggleEmailAuthentication(true);
            } else {
                $authentication = AppAuthentication::make();
                $authentication->saveSecret($user, $authentication->generateSecret());
                $authentication->saveRecoveryCodes($user, $authentication->generateRecoveryCodes());
            }
        }
    }

    /** @return array<string, string> */
    private function clients(): array
    {
        return [
            'Apple' => 'Developer conference microsite',
            'Tesla' => 'European test-drive campaign',
            'Airbnb' => 'Host onboarding experience',
            'Patagonia' => 'Worn Wear editorial platform',
            'Spotify' => 'Artist discovery campaign',
            'Nike' => 'Running club member portal',
        ];
    }

    /** @return array<string, Team> */
    private function seedTeams(User $admin): array
    {
        $members = [
            'Design' => ['alice@example.com', 'erin@example.com'],
            'Development' => ['bob@example.com', 'carol@example.com', 'dave@example.com', 'frank@example.com'],
            'Project Management' => ['manager@manager.com', 'editor@editor.com', 'viewer@viewer.com'],
        ];
        $teams = [];
        $administration = app(TeamAdministration::class);

        foreach ($members as $name => $emails) {
            $team = Team::query()->where('normalized_name', Str::lower($name))->first();
            if ($team === null) {
                $administration->save($admin, null, $name);
                $team = Team::query()->where('normalized_name', Str::lower($name))->sole();
            }

            foreach ($emails as $email) {
                $user = User::query()->where('email', $email)->first();
                if ($user !== null && ! $team->members()->whereKey($user->id)->exists()) {
                    $administration->membership($admin, $team->id, $user->id, true);
                }
            }

            $teams[$name] = $team;
        }

        return $teams;
    }

    /** @return array<string, string> */
    private function seedTags(User $admin): array
    {
        $tags = [];
        foreach (['Production' => 'danger', 'Staging' => 'warning', 'Shared' => 'info', 'SSO' => 'success'] as $name => $color) {
            $tags[$name] = Tag::query()->whereNull('vault_id')->where('normalized_name', Str::lower($name))->value('id')
                ?? app(VaultDirectory::class)->createTag($admin, null, ['name' => $name, 'color' => $color]);
        }

        return $tags;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $folders
     * @param  array<string, Team>  $teams
     * @param  array<string, string>  $tags
     */
    private function seedSharedVault(User $admin, string $name, string $description, array $folders, array $teams, array $tags): void
    {
        if (Vault::query()->where('normalized_name', Str::lower($name))->exists()) {
            return;
        }

        $vaultId = app(VaultAdministration::class)->create($admin, [
            'name' => $name,
            'description' => $description,
            'icon' => $name === 'Agency HQ' ? 'building-office-2' : 'briefcase',
            'primary_custodian_user_id' => $admin->id,
            'initial_manager_user_id' => $admin->id,
        ]);

        foreach ($folders as $folderName => $entries) {
            $folderId = app(VaultFolders::class)->create($admin, $vaultId, $folderName);
            app(ScopedGrants::class)->grantOnFolder($admin, $folderId, $teams[$folderName]->principal_id, 'editor');
            $this->seedEntries($admin, $vaultId, $folderId, $entries, $tags);
        }

        if (in_array($name, ['Apple', 'Tesla', 'Agency HQ'], true)) {
            app(Favorites::class)->toggleVault($admin, $vaultId);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, string>  $tags
     */
    private function seedEntries(User $admin, string $vaultId, string $folderId, array $entries, array $tags): void
    {
        foreach ($entries as $input) {
            $tagName = $input['demo_tag'] ?? 'Shared';
            unset($input['demo_tag']);
            $entryId = app(EntryVault::class)->create($admin, $vaultId, [
                ...$input,
                'owner_user_id' => $admin->id,
                'folder_id' => $folderId,
                'tags' => [$tags[$tagName]],
                'expires_at' => null,
            ]);

            if (in_array($input['title'], ['Figma — design system', 'GitHub — agency organization', 'Google Workspace — work account'], true)) {
                app(Favorites::class)->toggleEntry($admin, $entryId);
            }
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function clientEntries(string $client, string $project): array
    {
        $slug = Str::slug($client);
        $domain = $slug.'.example.com';
        $designEmail = 'design+'.$slug.'@northstar.example';
        $devEmail = 'engineering+'.$slug.'@northstar.example';
        $pmEmail = 'projects+'.$slug.'@northstar.example';

        return [
            'Design' => [
                $this->login('Figma — design system', $designEmail, 'https://www.figma.com', $project.'. Shared component library, responsive layouts, and developer handoff.', 'SSO'),
                $this->login('Adobe Creative Cloud — brand assets', $designEmail, 'https://account.adobe.com', 'Brand guidelines, campaign artwork, and licensed fonts.'),
                $this->login('Webflow — campaign preview', $designEmail, 'https://webflow.com/dashboard', 'Design review workspace. Publish previews to staging only.', 'Staging'),
                $this->note('Creative brief & handoff checklist', $project."\n\nDesign lead: Alice Johnson\nReview: Tuesday, 10:00 CET\nDeliverables: mobile and desktop layouts, accessible components, and export-ready assets.\nApproved artwork lives in the client Figma library. Engineering handoff follows design sign-off."),
            ],
            'Development' => [
                $this->login('AWS — production console', $devEmail, 'https://console.aws.amazon.com', $client.' application infrastructure. Region eu-central-1; production changes require peer approval.', 'Production'),
                $this->login('Google Cloud — analytics project', $devEmail, 'https://console.cloud.google.com', 'BigQuery reporting and campaign analytics. Project: '.$slug.'-digital.', 'Production'),
                $this->ssh('SSH — production web server', 'web-01.'.$domain, 'Production'),
                $this->ssh('SSH — staging application server', 'staging.'.$domain, 'Staging'),
                $this->login('GitHub — website repository', $devEmail, 'https://github.com', 'Repository northstar-studio/'.$slug.'-web. Main branch deploys through the release workflow.', 'SSO'),
                $this->login('Cloudflare — DNS & CDN', $devEmail, 'https://dash.cloudflare.com', 'DNS, edge cache, and WAF rules for '.$domain.'.', 'Production'),
                $this->login('Sentry — application monitoring', $devEmail, 'https://sentry.io', 'Frontend and API error tracking. Route critical alerts to the engineering channel.'),
                $this->login('Contentful — content staging', $devEmail, 'https://app.contentful.com', 'Editorial content preview for '.$project.'.', 'Staging'),
            ],
            'Project Management' => [
                $this->login('HubSpot — client workspace', $pmEmail, 'https://app.hubspot.com', 'Client contacts, meeting notes, and quarterly account planning.'),
                $this->login('Asana — delivery roadmap', $pmEmail, 'https://app.asana.com', $project.'. Milestones, sprint planning, and approval tracking.', 'SSO'),
                $this->login('Notion — project documentation', $pmEmail, 'https://www.notion.so', 'Scope, workshop notes, release calendar, and client decision log.'),
                $this->login('Google Analytics — campaign reporting', $pmEmail, 'https://analytics.google.com', 'Read-only campaign dashboard. Weekly reports go to the client every Friday.'),
            ],
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function agencyEntries(): array
    {
        return [
            'Design' => [
                $this->login('Figma — studio workspace', 'design@northstar.example', 'https://www.figma.com', 'Internal design library, proposal templates, and studio website.', 'SSO'),
                $this->login('Adobe Stock — asset library', 'design@northstar.example', 'https://stock.adobe.com', 'Licensed photography and illustration for client campaigns.'),
                $this->login('Fontspring — font licenses', 'design@northstar.example', 'https://www.fontspring.com', 'Studio font licenses and purchase receipts.'),
            ],
            'Development' => [
                $this->login('GitHub — agency organization', 'engineering@northstar.example', 'https://github.com', 'Organization settings, shared packages, and repository access.', 'SSO'),
                $this->login('Laravel Forge — server management', 'engineering@northstar.example', 'https://forge.laravel.com', 'Server provisioning, deployment recipes, and scheduled jobs.', 'Production'),
                $this->login('UptimeRobot — service monitoring', 'engineering@northstar.example', 'https://dashboard.uptimerobot.com', 'Client uptime checks and escalation contacts.'),
            ],
            'Project Management' => [
                $this->login('Harvest — time & budgets', 'operations@northstar.example', 'https://id.getharvest.com', 'Project budgets, weekly time reports, and invoicing.'),
                $this->login('HubSpot — agency sales pipeline', 'operations@northstar.example', 'https://app.hubspot.com', 'New business enquiries, proposals, and retainer renewals.'),
                $this->note('Client onboarding checklist', "1. Confirm statement of work and project contacts.\n2. Create a client vault and assign the delivery teams.\n3. Request staging access and brand assets.\n4. Schedule the kickoff workshop.\n5. Confirm release approvals and support handover."),
            ],
        ];
    }

    /** @param array<string, string> $tags */
    private function seedPersonalEntries(User $admin, array $tags): void
    {
        $vaultId = app(PersonalVaults::class)->provisionFor($admin);
        $folders = [
            'Work accounts' => [
                $this->login('Google Workspace — work account', 'alex@northstar.example', 'https://accounts.google.com', 'Work email, calendar, and shared drives.', 'SSO'),
                $this->login('Slack — Northstar Studio', 'alex@northstar.example', 'https://slack.com/signin', 'Studio announcements, client channels, and delivery updates.', 'SSO'),
                $this->login('Linear — personal workspace', 'alex@northstar.example', 'https://linear.app', 'My assigned issues and weekly planning.'),
            ],
            'Infrastructure' => [
                $this->ssh('SSH — agency bastion', 'bastion.northstar.example', 'Production'),
                $this->note('Release day checklist', "Confirm client approval and green CI checks.\nReview the migration plan with engineering.\nVerify the latest backup and rollback steps.\nDeploy during the agreed release window.\nCheck monitoring and send the client a release note."),
            ],
        ];

        foreach ($folders as $name => $entries) {
            if (Folder::query()->where('vault_id', $vaultId)->where('normalized_name', Str::lower($name))->exists()) {
                continue;
            }

            $folderId = app(VaultFolders::class)->create($admin, $vaultId, $name);
            $this->seedEntries($admin, $vaultId, $folderId, $entries, $tags);
        }
    }

    /** @return array<string, mixed> */
    private function login(string $title, string $username, string $url, string $notes, string $tag = 'Shared'): array
    {
        return [
            'type' => EntryPayload::TYPE_LOGIN,
            'title' => $title,
            'username' => $username,
            'password' => Str::password(24),
            'generated' => true,
            'url' => $url,
            'notes' => $notes,
            'demo_tag' => $tag,
        ];
    }

    /** @return array<string, mixed> */
    private function note(string $title, string $body): array
    {
        return ['type' => EntryPayload::TYPE_NOTE, 'title' => $title, 'body' => $body];
    }

    /** @return array<string, mixed> */
    private function ssh(string $title, string $host, string $tag): array
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        return [
            'type' => EntryPayload::TYPE_SSH_KEY,
            'title' => $title,
            'public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$publicKey).' deploy@'.$host,
            'notes' => 'Host: '.$host."\nPort: 22\nUser: deploy\nConnect through the agency VPN. Private keys remain on the engineering team's hardware tokens.",
            'custom_fields' => [
                ['label' => 'Host', 'value' => $host, 'concealed' => false],
                ['label' => 'Port', 'value' => '22', 'concealed' => false],
                ['label' => 'User', 'value' => 'deploy', 'concealed' => false],
            ],
            'demo_tag' => $tag,
        ];
    }
}
