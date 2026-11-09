<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Vault;
use App\Services\AuditRetention;
use App\Services\AuditWriter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class Activity extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.activity';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 30;

    /** @var array<string, string>|null */
    private ?array $vaultNames = null;

    public static function canAccess(): bool
    {
        return Gate::allows('view-activity');
    }

    public function getSubheading(): string
    {
        return (string) __('activity.subheading');
    }

    public function mount(): void
    {
        Gate::authorize('view-activity');

        /** @var User $user */
        $user = auth()->user();
        app(AuditWriter::class)->record('audit.viewed', $user->id);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pruneAudit')->label((string) __('activity.prune.label'))
                ->icon(Heroicon::OutlinedScissors)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading((string) __('activity.prune.heading'))
                ->modalDescription((string) __('activity.prune.description'))
                ->visible(fn (): bool => Gate::allows('manage-installation'))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $result = app(AuditRetention::class)->prune($user);
                    Notification::make()
                        ->title((string) __('activity.prune.done'))
                        ->body((string) __('activity.prune.result', [
                            'redacted' => $result['redacted'],
                            'pruned' => $result['pruned'],
                            'range' => $result['last_sequence'] === null
                                ? (string) __('activity.prune.no_range')
                                : '#'.$result['first_sequence'].'–#'.$result['last_sequence'],
                            'verified' => $result['verified'] ? (string) __('activity.prune.yes') : (string) __('activity.prune.no'),
                        ]))
                        ->color($result['verified'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditEvent::query()->with('actor'))
            ->columns([
                TextColumn::make('occurred_at')->label((string) __('activity.columns.time_utc'))
                    ->dateTime('Y-m-d H:i:s', 'UTC')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('actor.name')->label((string) __('activity.columns.actor'))
                    ->state(fn (AuditEvent $record): string => $this->actorName($record))
                    ->description(fn (AuditEvent $record): ?string => $this->actorDetail($record))
                    ->icon(fn (AuditEvent $record): Heroicon => $record->actor_id === null ? Heroicon::OutlinedCpuChip : Heroicon::OutlinedUser)
                    ->iconColor(fn (AuditEvent $record): string => $record->actor_id === null ? 'gray' : 'primary')
                    ->weight(FontWeight::Medium)
                    ->tooltip(fn (AuditEvent $record): HtmlString => $this->actorTooltip($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->whereHas('actor', fn (Builder $actorQuery): Builder => $actorQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhere('actor_id', 'like', "%{$search}%")
                            ->orWhere('actor_name_snapshot', 'like', "%{$search}%"),
                    )),
                TextColumn::make('action')
                    ->description(fn (AuditEvent $record): string => $this->humanizeAction($record->action))
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target')->label((string) __('activity.columns.target'))
                    ->state(fn (AuditEvent $record): string => $this->targetLabel($record))
                    ->description(fn (AuditEvent $record): ?string => $record->target_id)
                    ->placeholder((string) __('activity.values.none'))
                    ->toggleable(),
                TextColumn::make('source_channel')->label((string) __('activity.columns.source_channel'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? (string) __('activity.values.none') : (string) __('activity.channels.'.$state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outcome')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->colors(['success' => 'success', 'danger' => 'denied']),
                TextColumn::make('sequence')->label((string) __('activity.columns.chain'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event_hash')->label((string) __('activity.columns.event_hash'))
                    ->fontFamily(FontFamily::Mono)
                    ->limit(10)
                    ->tooltip(fn (AuditEvent $record): string => $record->event_hash)
                    ->copyableState(fn (AuditEvent $record): string => $record->event_hash)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->label((string) __('activity.filters.action'))
                    ->searchable()
                    ->options(fn (): array => $this->actionFilterOptions()),
                SelectFilter::make('source_channel')->label((string) __('activity.filters.source_channel'))
                    ->options(fn (): array => $this->channelFilterOptions()),
                SelectFilter::make('outcome')->label((string) __('activity.filters.outcome'))
                    ->options([
                        'success' => (string) __('activity.filters.outcome_options.success'),
                        'denied' => (string) __('activity.filters.outcome_options.denied'),
                    ]),
                SelectFilter::make('vault_id')->label((string) __('activity.filters.vault'))
                    ->searchable()
                    ->options(fn (): array => $this->vaultFilterOptions()),
                SelectFilter::make('actor')->label((string) __('activity.filters.actor'))
                    ->searchable()
                    ->options(fn (): array => $this->actorFilterOptions())
                    ->query(function (Builder $query, array $state): Builder {
                        $value = $state['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        return $value === 'system'
                            ? $query->whereNull('actor_id')
                            : $query->where('actor_id', $value);
                    }),
                Filter::make('occurred_at')->label((string) __('activity.filters.time_period'))
                    ->form([
                        DatePicker::make('occurred_from')->label((string) __('activity.filters.from'))->maxDate(now()),
                        DatePicker::make('occurred_until')->label((string) __('activity.filters.until'))->maxDate(now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        // `occurred_at` is stored as a UTC ISO-8601 string, so lexical comparison
                        // equals chronological comparison; the upper bound is exclusive at the
                        // start of the following day so the selected day is fully included.
                        return $query
                            ->when(
                                $data['occurred_from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->where('occurred_at', '>=', Carbon::parse($date)->utc()->startOfDay()->format('Y-m-d\TH:i:s')),
                            )
                            ->when(
                                $data['occurred_until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->where('occurred_at', '<', Carbon::parse($date)->utc()->addDay()->startOfDay()->format('Y-m-d\TH:i:s')),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['occurred_from'] ?? null)) {
                            $indicators[] = Indicator::make((string) __('activity.filters.from_indicator', ['date' => Carbon::parse($data['occurred_from'])->format('M j, Y')]))
                                ->removeField('tableFilters.occurred_at.occurred_from');
                        }
                        if (filled($data['occurred_until'] ?? null)) {
                            $indicators[] = Indicator::make((string) __('activity.filters.until_indicator', ['date' => Carbon::parse($data['occurred_until'])->format('M j, Y')]))
                                ->removeField('tableFilters.occurred_at.occurred_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                Action::make('viewEvent')->label((string) __('activity.details.label'))
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->iconButton()->tooltip((string) __('activity.details.tooltip'))
                    ->color('gray')
                    ->modalHeading(fn (AuditEvent $record): string => (string) __('activity.details.modal_heading', ['sequence' => $record->sequence, 'action' => $record->action]))
                    ->modalDescription((string) __('activity.details.modal_description'))
                    ->infolist([
                        TextEntry::make('actor.name')->label((string) __('activity.columns.actor'))
                            ->state(fn (AuditEvent $record): string => $this->actorName($record))
                            ->icon(fn (AuditEvent $record): Heroicon => $record->actor_id === null ? Heroicon::OutlinedCpuChip : Heroicon::OutlinedUser),
                        TextEntry::make('occurred_at')->label((string) __('activity.details.occurred_at'))
                            ->dateTime('Y-m-d H:i:s.u', 'UTC'),
                        TextEntry::make('outcome')->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state))
                            ->colors(['success' => 'success', 'danger' => 'denied']),
                        TextEntry::make('target')->label((string) __('activity.columns.target'))
                            ->state(fn (AuditEvent $record): string => $this->targetLabel($record)),
                        TextEntry::make('source_channel')->label((string) __('activity.columns.source_channel'))
                            ->state(fn (AuditEvent $record): string => $record->source_channel === null ? (string) __('activity.values.none') : (string) __('activity.channels.'.$record->source_channel)),
                        TextEntry::make('ip_address')->label((string) __('activity.details.ip_address'))
                            ->state(fn (AuditEvent $record): string => $this->metadataValue((int) $record->sequence, 'ip_address')),
                        TextEntry::make('user_agent')->label((string) __('activity.details.user_agent'))
                            ->state(fn (AuditEvent $record): string => $this->metadataValue((int) $record->sequence, 'user_agent'))
                            ->columnSpanFull(),
                        KeyValueEntry::make('sanitized_context')->label((string) __('activity.details.sanitized_context'))
                            ->keyLabel((string) __('activity.details.key_field'))->valueLabel((string) __('activity.details.value_field'))
                            ->state(fn (AuditEvent $record): array => $this->contextState($record)),
                        TextEntry::make('previous_hash')->copyable()->fontFamily(FontFamily::Mono),
                        TextEntry::make('event_hash')->copyable()->fontFamily(FontFamily::Mono),
                        KeyValueEntry::make('canonical_event')->label((string) __('activity.details.canonical_event'))
                            ->keyLabel((string) __('activity.details.key_field'))->valueLabel((string) __('activity.details.value_field'))
                            ->state(fn (AuditEvent $record): array => json_decode($record->canonical_event, true, 512, JSON_THROW_ON_ERROR)),
                    ]),
            ])
            ->defaultSort('sequence', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('activity.empty.heading'))
            ->emptyStateDescription((string) __('activity.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedClock);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('view-activity');

        return [
            'verified' => app(AuditWriter::class)->verifyDetail(),
        ];
    }

    private function actorName(AuditEvent $record): string
    {
        if ($record->actor_id === null) {
            return (string) __('activity.actors.system');
        }

        return $record->actor->name ?? $record->actor_name_snapshot ?? (string) __('activity.actors.unknown');
    }

    private function actorDetail(AuditEvent $record): ?string
    {
        if ($record->actor_id === null) {
            return null;
        }

        // Auditor activity is redacted: only Super Admins may see user contact details.
        if (! $this->viewerCanSeeContactDetails()) {
            return null;
        }

        return $record->actor->email ?? $record->actor_id;
    }

    private function viewerCanSeeContactDetails(): bool
    {
        return Gate::allows('manage-installation');
    }

    private function actorTooltip(AuditEvent $record): HtmlString
    {
        if ($record->actor_id === null) {
            return new HtmlString((string) __('activity.actors.anonymous_tooltip'));
        }

        $actor = $record->actor;
        if ($actor === null) {
            $snapshot = $record->actor_name_snapshot;
            if ($snapshot !== null) {
                return new HtmlString((string) __('activity.actors.missing_snapshot_tooltip', ['name' => e($snapshot), 'uid' => e($record->actor_id)]));
            }

            return new HtmlString((string) __('activity.actors.missing_tooltip', ['uid' => e($record->actor_id)]));
        }

        $roles = collect($actor->platformRoleNames())
            ->map(fn (string $role): string => Str::title(str_replace('_', ' ', $role)))
            ->implode(', ');

        $lines = ['<strong>'.e($actor->name).'</strong>'];
        if ($this->viewerCanSeeContactDetails()) {
            $lines[] = e($actor->email);
        }
        if (filled($roles)) {
            $lines[] = (string) __('activity.actors.platform_roles', ['roles' => e($roles)]);
        }
        if (! $actor->is_active) {
            $lines[] = '<strong>'.(string) __('activity.actors.deactivated').'</strong>';
        }
        $lines[] = '<br><small>'.(string) __('activity.actors.uid', ['uid' => e($record->actor_id)]).'</small>';

        return new HtmlString(implode('<br>', $lines));
    }

    private function targetLabel(AuditEvent $record): string
    {
        if ($record->vault_id !== null) {
            $name = $this->vaultNames()[$record->vault_id] ?? null;
            if ($name !== null) {
                return $name;
            }
        }

        if ($record->target_type === null) {
            return (string) __('activity.values.none');
        }

        $key = 'activity.targets.'.$record->target_type;

        return trans()->has($key) ? (string) __($key) : Str::title(str_replace('_', ' ', $record->target_type));
    }

    private function metadataValue(int $sequence, string $column): string
    {
        $value = DB::table('audit_event_metadata')->where('sequence', $sequence)->value($column);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : (string) __('activity.values.none');
    }

    /** @return array<string, string> */
    private function contextState(AuditEvent $record): array
    {
        if ($record->sanitized_context === null) {
            return [];
        }

        $decoded = json_decode($record->sanitized_context, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_THROW_ON_ERROR), $decoded) : [];
    }

    /** @return array<string, array<string, string>> */
    private function actionFilterOptions(): array
    {
        $groupLabels = [
            'account' => (string) __('activity.groups.account'),
            'attachment' => (string) __('activity.groups.attachment'),
            'audit' => (string) __('activity.groups.audit'),
            'auth' => (string) __('activity.groups.auth'),
            'entry' => (string) __('activity.groups.entry'),
            'folder' => (string) __('activity.groups.folder'),
            'grant' => (string) __('activity.groups.grant'),
            'installation' => (string) __('activity.groups.installation'),
            'invitation' => (string) __('activity.groups.invitation'),
            'key' => (string) __('activity.groups.key'),
            'team' => (string) __('activity.groups.team'),
            'user' => (string) __('activity.groups.user'),
            'vault' => (string) __('activity.groups.vault'),
        ];
        $options = [];
        foreach (AuditEvent::query()->select('action')->distinct()->orderBy('action')->pluck('action') as $action) {
            $prefix = Str::before((string) $action, '.');
            $options[$groupLabels[$prefix] ?? Str::title($prefix)][(string) $action] = $this->humanizeAction((string) $action);
        }
        ksort($options);

        return $options;
    }

    /** @return array<string, string> */
    private function channelFilterOptions(): array
    {
        $options = [];
        foreach (AuditEvent::query()->whereNotNull('source_channel')->select('source_channel')->distinct()->orderBy('source_channel')->pluck('source_channel') as $channel) {
            $key = 'activity.channels.'.(string) $channel;
            $options[(string) $channel] = trans()->has($key) ? (string) __($key) : Str::title((string) $channel);
        }

        return $options;
    }

    /** @return array<string, string> */
    private function vaultFilterOptions(): array
    {
        $ids = AuditEvent::query()->whereNotNull('vault_id')->select('vault_id')->distinct()->pluck('vault_id')->all();
        $names = $this->vaultNames();

        return array_filter(array_map(fn (mixed $id): ?string => $names[(string) $id] ?? null, $ids), fn (?string $label): bool => $label !== null);
    }

    /** @return array<string, string> */
    private function vaultNames(): array
    {
        if ($this->vaultNames === null) {
            $this->vaultNames = array_map(fn (mixed $name): string => (string) $name, Vault::query()->pluck('name', 'id')->all());
        }

        return $this->vaultNames;
    }

    /** @return array<string, string> */
    private function actorFilterOptions(): array
    {
        $withContactDetails = $this->viewerCanSeeContactDetails();
        $actorIds = AuditEvent::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id');
        $options = User::query()->whereIn('id', $actorIds)->orderBy('name')->get()
            ->mapWithKeys(function (User $user) use ($withContactDetails): array {
                $label = $withContactDetails ? "{$user->name} ({$user->email})" : $user->name;

                return [$user->id => $label];
            })
            ->all();

        return ['system' => (string) __('activity.actors.system')] + $options;
    }

    private function humanizeAction(string $action): string
    {
        return match ($action) {
            'account.activated' => (string) __('activity.actions.account_activated'),
            'account.deactivated' => (string) __('activity.actions.account_deactivated'),
            'account.roles_changed' => (string) __('activity.actions.account_roles_changed'),
            'attachment.deleted' => (string) __('activity.actions.attachment_deleted'),
            'attachment.download_completed' => (string) __('activity.actions.attachment_download_completed'),
            'attachment.download_started' => (string) __('activity.actions.attachment_download_started'),
            'attachment.uploaded' => (string) __('activity.actions.attachment_uploaded'),
            'attachment.validation_failed' => (string) __('activity.actions.attachment_validation_failed'),
            'audit.pruned' => (string) __('activity.actions.audit_pruned'),
            'audit.viewed' => (string) __('activity.actions.audit_viewed'),
            'auth.admin_factor_reset' => (string) __('activity.actions.auth_admin_factor_reset'),
            'auth.email_changed' => (string) __('activity.actions.auth_email_changed'),
            'auth.factor_added' => (string) __('activity.actions.auth_factor_added'),
            'auth.factor_removed' => (string) __('activity.actions.auth_factor_removed'),
            'auth.local_recovery' => (string) __('activity.actions.auth_local_recovery'),
            'auth.login' => (string) __('activity.actions.auth_login'),
            'auth.logout' => (string) __('activity.actions.auth_logout'),
            'auth.multi_factor' => (string) __('activity.actions.auth_multi_factor'),
            'auth.other_sessions_revoked' => (string) __('activity.actions.auth_other_sessions_revoked'),
            'auth.password_changed' => (string) __('activity.actions.auth_password_changed'),
            'auth.reauthentication' => (string) __('activity.actions.auth_reauthentication'),
            'auth.session_revoked' => (string) __('activity.actions.auth_session_revoked'),
            'grant.created' => (string) __('activity.actions.grant_created'),
            'grant.revoked' => (string) __('activity.actions.grant_revoked'),
            'import.cancelled' => (string) __('activity.actions.import_cancelled'),
            'import.completed' => (string) __('activity.actions.import_completed'),
            'import.failed' => (string) __('activity.actions.import_failed'),
            'import.started' => (string) __('activity.actions.import_started'),
            'installation.bootstrapped' => (string) __('activity.actions.installation_bootstrapped'),
            'installation.local_seeded' => (string) __('activity.actions.installation_local_seeded'),
            'installation.settings_changed' => (string) __('activity.actions.installation_settings_changed'),
            'invitation.accepted' => (string) __('activity.actions.invitation_accepted'),
            'invitation.cancelled' => (string) __('activity.actions.invitation_cancelled'),
            'invitation.created' => (string) __('activity.actions.invitation_created'),
            'license.installed' => (string) __('activity.actions.license_installed'),
            'license.removed' => (string) __('activity.actions.license_removed'),
            'seat_limit.reached' => (string) __('activity.actions.seat_limit_reached'),
            'team.created' => (string) __('activity.actions.team_created'),
            'team.member_added' => (string) __('activity.actions.team_member_added'),
            'team.member_removed' => (string) __('activity.actions.team_member_removed'),
            'team.renamed' => (string) __('activity.actions.team_renamed'),
            'user.administrator_created' => (string) __('activity.actions.user_administrator_created'),
            'user.created' => (string) __('activity.actions.user_created'),
            'user.offboarded' => (string) __('activity.actions.user_offboarded'),
            'user.updated' => (string) __('activity.actions.user_updated'),
            'vault.archived' => (string) __('activity.actions.vault_archived'),
            'vault.converted' => (string) __('activity.actions.vault_converted'),
            'vault.created' => (string) __('activity.actions.vault_created'),
            'vault.creation_denied' => (string) __('activity.actions.vault_creation_denied'),
            'vault.custodian_changed' => (string) __('activity.actions.vault_custodian_changed'),
            'vault.emergency_ended' => (string) __('activity.actions.vault_emergency_ended'),
            'vault.emergency_started' => (string) __('activity.actions.vault_emergency_started'),
            'vault.purge_initiated' => (string) __('activity.actions.vault_purge_initiated'),
            'vault.purge_cancelled' => (string) __('activity.actions.vault_purge_cancelled'),
            'vault.purged' => (string) __('activity.actions.vault_purged'),
            'vault.reactivated' => (string) __('activity.actions.vault_reactivated'),
            'vault.recovered' => (string) __('activity.actions.vault_recovered'),
            'vault.updated' => (string) __('activity.actions.vault_updated'),
            default => Str::title(strtr($action, ['.' => ' ', '_' => ' '])),
        };
    }
}
