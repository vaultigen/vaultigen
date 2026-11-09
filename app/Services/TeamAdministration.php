<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamAdministration
{
    public function __construct(private AuditWriter $audit) {}

    public function save(User $actor, ?string $id, string $name): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $name = trim($name);
        $normalized = Str::lower($name);
        Validator::make(['name' => $normalized], ['name' => ['required', 'max:255', Rule::unique('teams', 'normalized_name')->ignore($id)]])->validate();
        $this->audit->transaction(function () use ($actor, $id, $name, $normalized): void {
            if ($id === null) {
                $principal = (string) Str::uuid();
                DB::table('principals')->insert(['id' => $principal, 'kind' => 'team', 'created_at' => now()]);
                DB::table('teams')->insert(['id' => (string) Str::uuid(), 'principal_id' => $principal, 'name' => $name, 'normalized_name' => $normalized, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                abort_unless(DB::table('teams')->where('id', $id)->exists(), 404);
                DB::table('teams')->where('id', $id)->update(['name' => $name, 'normalized_name' => $normalized, 'updated_at' => now()]);
            }
            $this->audit->record($id === null ? 'team.created' : 'team.renamed', $actor->id);
        });
    }

    public function membership(User $actor, string $teamId, string $userId, bool $add): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $this->audit->transaction(function () use ($actor, $teamId, $userId, $add): void {
            abort_unless(DB::table('teams')->where('id', $teamId)->exists(), 404);
            User::findOrFail($userId);
            if ($add) {
                DB::table('team_memberships')->upsert([['team_id' => $teamId, 'user_id' => $userId, 'created_by_user_id' => $actor->id, 'created_at' => now()]], ['team_id', 'user_id'], ['user_id']);
            } else {
                DB::table('team_memberships')->where('team_id', $teamId)->where('user_id', $userId)->delete();
            }
            $this->audit->record($add ? 'team.member_added' : 'team.member_removed', $actor->id);
        });
    }
}
