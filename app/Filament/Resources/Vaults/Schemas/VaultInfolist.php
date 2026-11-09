<?php

namespace App\Filament\Resources\Vaults\Schemas;

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class VaultInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make((string) __('vaults.labels.vault'))
                    ->columns(2)
                    ->components([
                        TextEntry::make('name'),
                        TextEntry::make('kind')
                            ->label((string) __('vaults.labels.type'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === Vault::KIND_PRIVATE ? (string) __('vaults.kinds.personal') : (string) __('vaults.kinds.shared'))
                            ->color(fn (string $state): string => $state === Vault::KIND_PRIVATE ? 'info' : 'primary'),
                        TextEntry::make('state')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => (string) __('vaults.states.'.$state))
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'read_only' => 'warning',
                                'pending_purge' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('purge_after')->label((string) __('vaults.labels.purge_after'))->dateTime()
                            ->visible(fn (Vault $record): bool => $record->state === 'pending_purge'),
                        TextEntry::make('custodian.name')->label((string) __('vaults.labels.custodian'))->placeholder((string) __('vaults.labels.unknown')),
                        TextEntry::make('owner.name')->label((string) __('vaults.labels.owner'))->placeholder('—'),
                        TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->label((string) __('vaults.labels.created'))->dateTime(),
                    ]),
                Section::make((string) __('vaults.infolist.sections.your_access'))
                    ->columns(2)
                    ->components([
                        TextEntry::make('your_permission')
                            ->label((string) __('vaults.labels.permission'))
                            ->badge()
                            ->state(fn (Vault $record): ?string => self::authority($record)['permission'] ?? null)
                            ->formatStateUsing(fn (string $state): string => (string) __('vaults.permissions.'.$state))
                            ->color(fn (string $state): string => $state === 'manager' ? 'success' : 'gray'),
                        TextEntry::make('your_access_expires_at')
                            ->label((string) __('vaults.labels.expires'))
                            ->state(fn (Vault $record): ?Carbon => self::authority($record)['expires_at'] ?? null)
                            ->dateTime()
                            ->placeholder((string) __('vaults.labels.no_expiry')),
                    ]),
            ]);
    }

    /**
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    private static function authority(Vault $record): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(VaultAccess::class)->authority($user, $record->id);
    }
}
