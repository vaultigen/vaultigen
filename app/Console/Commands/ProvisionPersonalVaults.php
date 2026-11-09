<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PersonalVaults;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ProvisionPersonalVaults extends Command
{
    protected $signature = 'vaultigen:provision-personal-vaults';

    protected $description = 'Ensure every user has exactly one personal work vault with an owner Manager grant';

    public function handle(PersonalVaults $vaults): int
    {
        $count = 0;
        User::query()->orderBy('id')->chunkById(100, function (Collection $users) use ($vaults, &$count): void {
            foreach ($users as $user) {
                $vaults->provisionFor($user);
                $count++;
            }
        });
        $this->info("Personal work vaults ensured for {$count} users.");

        return self::SUCCESS;
    }
}
