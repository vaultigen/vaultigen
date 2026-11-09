<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;

final class PrincipalDirectory
{
    /**
     * @param  list<string>  $principalIds
     * @return array<string, array{kind: string, label: string}>
     */
    public function labels(array $principalIds): array
    {
        if ($principalIds === []) {
            return [];
        }

        $labels = [];
        foreach (User::query()->whereIn('principal_id', $principalIds)->get() as $user) {
            $labels[(string) $user->principal_id] = ['kind' => 'user', 'label' => "{$user->name} ({$user->email})"];
        }
        foreach (Team::query()->whereIn('principal_id', $principalIds)->get() as $team) {
            $labels[(string) $team->principal_id] = ['kind' => 'team', 'label' => $team->name];
        }

        return $labels;
    }

    /**
     * @return list<array{id: string, kind: string, label: string}>
     */
    public function selectablePrincipals(): array
    {
        $principals = [];
        foreach (User::query()->where('is_active', true)->orderBy('name')->get() as $user) {
            $principals[] = ['id' => (string) $user->principal_id, 'kind' => 'user', 'label' => "{$user->name} ({$user->email})"];
        }
        foreach (Team::query()->orderBy('name')->get() as $team) {
            $principals[] = ['id' => (string) $team->principal_id, 'kind' => 'team', 'label' => "Team: {$team->name}"];
        }

        return $principals;
    }
}
