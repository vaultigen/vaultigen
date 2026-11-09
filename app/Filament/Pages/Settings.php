<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\InstallationSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** @property-read Schema $form */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getSubheading(): string
    {
        return (string) __('settings.subheading');
    }

    public function mount(InstallationSettings $settings): void
    {
        Gate::authorize('manage-installation');
        $this->form->fill($settings->values());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make((string) __('settings.sections.installation'))
                ->description((string) __('settings.sections.installation_description'))
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->schema([
                    TextInput::make('display_name')
                        ->label((string) __('settings.fields.display_name'))
                        ->required()
                        ->maxLength(100)
                        ->placeholder((string) __('settings.fields.display_name_placeholder'))
                        ->helperText((string) __('settings.fields.display_name_helper')),
                ]),
            Section::make((string) __('settings.sections.vault_creation'))
                ->description((string) __('settings.sections.vault_creation_description'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->schema([
                    Radio::make('vault_creation_policy')
                        ->label((string) __('settings.fields.vault_creation_policy'))
                        ->required()
                        ->options([
                            'admins' => (string) __('settings.fields.vault_creation_policy_options.admins'),
                            'vault_creators' => (string) __('settings.fields.vault_creation_policy_options.vault_creators'),
                            'any_active_user' => (string) __('settings.fields.vault_creation_policy_options.any_active_user'),
                        ])
                        ->helperText((string) __('settings.fields.vault_creation_policy_helper')),
                    TextInput::make('vault_creation_limit_per_user')
                        ->label((string) __('settings.fields.vault_creation_limit_per_user'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->helperText((string) __('settings.fields.vault_creation_limit_per_user_helper')),
                    TextInput::make('vault_creation_limit_installation')
                        ->label((string) __('settings.fields.vault_creation_limit_installation'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(100000)
                        ->helperText((string) __('settings.fields.vault_creation_limit_installation_helper')),
                ]),
            Section::make((string) __('settings.sections.retention'))
                ->description((string) __('settings.sections.retention_description'))
                ->icon(Heroicon::OutlinedTrash)
                ->columns(3)
                ->schema([
                    TextInput::make('trash_retention_days')
                        ->label((string) __('settings.fields.trash_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.trash_retention_days_helper')),
                    TextInput::make('vault_purge_retention_days')
                        ->label((string) __('settings.fields.vault_purge_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.vault_purge_retention_days_helper')),
                    TextInput::make('revision_retention_count')
                        ->label((string) __('settings.fields.revision_retention_count'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->helperText((string) __('settings.fields.revision_retention_count_helper')),
                    TextInput::make('revision_retention_days')
                        ->label((string) __('settings.fields.revision_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(3650)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.revision_retention_days_helper')),
                ]),
            Section::make((string) __('settings.sections.audit_retention'))
                ->description((string) __('settings.sections.audit_retention_description'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->columns(2)
                ->schema([
                    TextInput::make('audit_retention_days')
                        ->label((string) __('settings.fields.audit_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.audit_retention_days_helper')),
                    TextInput::make('audit_ip_retention_days')
                        ->label((string) __('settings.fields.audit_ip_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(3650)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.audit_ip_retention_days_helper')),
                ]),
            Section::make((string) __('settings.sections.imports'))
                ->description((string) __('settings.sections.imports_description'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    TextInput::make('import_retention_days')
                        ->label((string) __('settings.fields.import_retention_days'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.import_retention_days_helper')),
                ]),
            Section::make((string) __('settings.sections.site_icons'))
                ->description((string) __('settings.sections.site_icons_description'))
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    Toggle::make('site_icons_enabled')
                        ->label((string) __('settings.fields.site_icons_enabled'))
                        ->helperText((string) __('settings.fields.site_icons_enabled_helper')),
                ]),
            Section::make((string) __('settings.sections.session_security'))
                ->description((string) __('settings.sections.session_security_description'))
                ->icon(Heroicon::OutlinedClock)
                ->columns(2)
                ->schema([
                    TextInput::make('inactivity_minutes')
                        ->label((string) __('settings.fields.inactivity_minutes'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(60)
                        ->lte('absolute_minutes')
                        ->suffix((string) __('settings.fields.suffix_minutes'))
                        ->helperText((string) __('settings.fields.inactivity_minutes_helper')),
                    TextInput::make('absolute_minutes')
                        ->label((string) __('settings.fields.absolute_minutes'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(720)
                        ->suffix((string) __('settings.fields.suffix_minutes'))
                        ->helperText((string) __('settings.fields.absolute_minutes_helper')),
                ]),
            Section::make((string) __('settings.sections.authentication'))
                ->description((string) __('settings.sections.authentication_description'))
                ->icon(Heroicon::OutlinedKey)
                ->columns(2)
                ->schema([
                    CheckboxList::make('mfa_methods')
                        ->label((string) __('settings.fields.mfa_methods'))
                        ->required()
                        ->minItems(1)
                        ->options([
                            'app' => (string) __('settings.fields.mfa_methods_options.app'),
                            'email' => (string) __('settings.fields.mfa_methods_options.email'),
                        ])
                        ->columns(1)
                        ->helperText((string) __('settings.fields.mfa_methods_helper'))
                        ->columnSpan(1),
                    TextInput::make('remember_device_days')
                        ->label((string) __('settings.fields.remember_device_days'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(365)
                        ->suffix((string) __('settings.fields.suffix_days'))
                        ->helperText((string) __('settings.fields.remember_device_days_helper'))
                        ->columnSpan(1),
                ]),
            Section::make((string) __('settings.sections.security_notifications'))
                ->description((string) __('settings.sections.security_notifications_description'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->schema([
                    Repeater::make('security_recipients')
                        ->hiddenLabel()
                        ->simple(
                            TextInput::make('email')
                                ->email()
                                ->required()
                                ->maxLength(254)
                                ->placeholder((string) __('settings.security_recipients.email_placeholder'))
                                ->rule(fn (TextInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                                    $rawState = $component->getParentRepeater()?->getRawState();
                                    $recipients = collect(is_array($rawState) ? $rawState : [])
                                        ->pluck('email')
                                        ->filter(fn (mixed $email): bool => is_string($email) && filled($email));

                                    $isDuplicate = filled($value) && $recipients->filter(fn (string $email): bool => strcasecmp($email, (string) $value) === 0)->count() > 1;

                                    if ($isDuplicate) {
                                        $fail((string) __('settings.security_recipients.duplicate'));
                                    }
                                }),
                        )
                        ->addActionLabel((string) __('settings.security_recipients.add_action'))
                        ->maxItems(10)
                        ->reorderable(false)
                        ->helperText((string) __('settings.security_recipients.helper')),
                    Toggle::make('vault_purge_notifications_enabled')
                        ->label((string) __('settings.fields.vault_purge_notifications_enabled'))
                        ->helperText((string) __('settings.fields.vault_purge_notifications_enabled_helper')),
                ]),
        ]);
    }

    public function save(InstallationSettings $settings): void
    {
        Gate::authorize('manage-installation');
        /** @var User $user */
        $user = auth()->user();
        $settings->save($user, $this->form->getState());
        Notification::make()->title((string) __('settings.notifications.saved'))->success()->send();
    }
}
