<?php

namespace App\Auth;

use App\Filament\Livewire\ActiveSessions;
use App\Filament\Livewire\TrustedDevices;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\LocaleResolver;
use App\Services\MfaPolicy;
use App\Services\StrongAuthentication;
use Closure;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * @property-read Schema $passwordForm
 */
class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    protected Width|string|null $maxWidth = Width::SevenExtraLarge;

    /** @var array<string, mixed>|null */
    public ?array $passwordData = [];

    private bool $passwordChanged = false;

    private bool $emailChanged = false;

    public static function isMultiFactorAuthenticationRequired(Panel $panel): bool
    {
        return false;
    }

    public function mount(): void
    {
        $user = $this->currentUser();
        if (! $user->getAttribute('must_reset_password') && ! app(MfaPolicy::class)->userHasMethod($user)) {
            $this->redirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
        }
        parent::mount();
    }

    protected function fillForm(): void
    {
        parent::fillForm();
        $this->data['locale'] = $this->currentUser()->locale ?? app()->getLocale();
        $this->passwordForm->fill();
    }

    public function save(): void
    {
        try {
            parent::save();
        } finally {
            $this->data['profileCurrentPassword'] = null;
            $this->data['profileConfirmationCode'] = null;
        }
    }

    public function savePassword(): void
    {
        try {
            try {
                $this->rateLimit(5);
            } catch (TooManyRequestsException $exception) {
                $this->getRateLimitedNotification($exception)?->send();

                return;
            }

            try {
                $this->beginDatabaseTransaction();

                $this->callHook('beforeValidate');

                $data = $this->passwordForm->getState();

                $this->callHook('afterValidate');

                $data = $this->mutatePasswordDataBeforeSave($data);

                $this->callHook('beforeSave');

                $this->currentUser()->update($data);

                $this->callHook('afterSave');
            } catch (Throwable $exception) {
                $this->rollBackDatabaseTransaction();

                throw $exception;
            }

            $this->commitDatabaseTransaction();

            $this->passwordData['password'] = null;
            $this->passwordData['passwordConfirmation'] = null;

            $this->getSavedNotification()?->send();
        } finally {
            foreach (['password', 'passwordConfirmation', 'currentPassword', 'confirmationCode'] as $field) {
                $this->passwordData[$field] = null;
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make((string) __('profile.sections.profile_information'))
                ->description((string) __('profile.sections.profile_information_description'))
                ->icon(Heroicon::OutlinedUserCircle)
                ->columns(2)
                ->schema([
                    $this->getNameFormComponent(),
                    TextInput::make('email')
                        ->label((string) __('profile.fields.email'))
                        ->email()
                        ->required()
                        ->maxLength(254)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true),
                    Select::make('locale')
                        ->label((string) __('profile.fields.locale'))
                        ->options(fn (): array => app(LocaleResolver::class)->supported())
                        ->helperText((string) __('profile.fields.locale_helper'))
                        ->native(false),
                    TextInput::make('profileCurrentPassword')
                        ->label((string) __('profile.fields.current_password'))
                        ->password()
                        ->autocomplete('current-password')
                        ->currentPassword(guard: Filament::getAuthGuard())
                        ->dehydrated(false)
                        ->visible(fn (Get $get): bool => $this->emailIsChanging($get))
                        ->required(fn (Get $get): bool => $this->emailIsChanging($get)),
                    $this->confirmationCodeField(
                        'profileConfirmationCode',
                        'sendProfileEmailCode',
                        fn (Get $get): bool => $this->emailIsChanging($get),
                    ),
                ]),
        ]);
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->inlineLabel(! static::isSimple())
            ->statePath('passwordData')
            ->components([
                Section::make((string) __('profile.sections.change_password'))
                    ->description((string) __('profile.sections.change_password_description'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columns(2)
                    ->schema([
                        TextInput::make('password')
                            ->label((string) __('profile.fields.new_password'))
                            ->password()
                            ->autocomplete('new-password')
                            ->required(fn (): bool => $this->currentUser()->getAttribute('must_reset_password'))
                            ->rules(fn (): array => app()->isLocal() ? [] : [Password::min(15)->when(app()->isProduction(), fn (Password $rule): Password => $rule->uncompromised())])
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (#[\SensitiveParameter] string $state): string => Hash::make($state))
                            ->same('passwordConfirmation'),
                        TextInput::make('passwordConfirmation')
                            ->label((string) __('profile.fields.confirm_new_password'))
                            ->password()
                            ->autocomplete('new-password')
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                        TextInput::make('currentPassword')
                            ->label((string) __('profile.fields.current_password'))
                            ->password()
                            ->autocomplete('current-password')
                            ->currentPassword(guard: Filament::getAuthGuard())
                            ->dehydrated(false)
                            ->required(fn (Get $get): bool => filled($get('password'))),
                        $this->confirmationCodeField(
                            'confirmationCode',
                            'sendPasswordEmailCode',
                            fn (): bool => app(MfaPolicy::class)->enabled(),
                        ),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getBackContentComponent(),
            $this->getFormContentComponent(),
            $this->getPasswordFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            ...Arr::wrap($this->getActiveSessionsContentComponent()),
            ...Arr::wrap($this->getTrustedDevicesContentComponent()),
        ]);
    }

    public function getBackContentComponent(): Component
    {
        return Actions::make([
            $this->backAction()
                ->name('backToApp')
                ->label((string) __('app.actions.back'))
                ->icon(Heroicon::OutlinedArrowLeft),
        ])->alignment(Alignment::Start);
    }

    public function getPasswordFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('passwordForm')])
            ->id('passwordForm')
            ->livewireSubmitHandler('savePassword')
            ->footer([
                Actions::make($this->getPasswordFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('password-form-actions'),
            ]);
    }

    /** @return array<Action> */
    protected function getPasswordFormActions(): array
    {
        return [
            Action::make('savePassword')->label((string) __('app.actions.update_password'))->submit('savePassword'),
            $this->getCancelFormAction()->name('backFromPassword'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label((string) __('app.actions.save'));
    }

    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        $section = parent::getMultiFactorAuthenticationContentComponent();

        if ($section instanceof Section) {
            $section
                ->label(null)
                ->heading((string) __('profile.sections.two_factor'))
                ->description((string) __('profile.sections.two_factor_description'))
                ->icon(Heroicon::OutlinedShieldCheck);
        }

        return $section;
    }

    public function getActiveSessionsContentComponent(): ?Component
    {
        $user = $this->currentUser();

        if (! $user->is_active || $user->must_reset_password) {
            return null;
        }

        return Section::make((string) __('profile.sections.active_sessions'))
            ->description((string) __('profile.sections.active_sessions_description'))
            ->icon(Heroicon::OutlinedComputerDesktop)
            ->compact()
            ->divided()
            ->secondary()
            ->schema([
                Livewire::make(ActiveSessions::class),
            ]);
    }

    public function getTrustedDevicesContentComponent(): ?Component
    {
        $user = $this->currentUser();

        if (! $user->is_active || $user->must_reset_password || ! app(MfaPolicy::class)->trustedDevicesEnabled()) {
            return null;
        }

        return Section::make((string) __('profile.sections.trusted_devices'))
            ->description((string) __('profile.sections.trusted_devices_description'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->compact()
            ->divided()
            ->secondary()
            ->schema([
                Livewire::make(TrustedDevices::class),
            ]);
    }

    protected function confirmationCodeField(string $name, string $sendActionName, Closure $visible): TextInput
    {
        return TextInput::make($name)
            ->label(fn (): string => (string) (app(MfaPolicy::class)->userMethod($this->currentUser()) === 'email'
                ? __('profile.fields.email_code')
                : __('profile.fields.authenticator_code')))
            ->password()
            ->autocomplete('one-time-code')
            ->helperText((string) __('profile.fields.confirmation_code_helper'))
            ->visible(fn (Get $get): bool => app(MfaPolicy::class)->enabled() && $visible($get))
            ->suffixAction(fn (): ?Action => $this->emailCodeAction($sendActionName))
            ->dehydrated(false);
    }

    protected function emailCodeAction(string $name): ?Action
    {
        if (app(MfaPolicy::class)->userMethod($this->currentUser()) !== 'email') {
            return null;
        }

        return Action::make($name)
            ->label((string) __('app.actions.send_code'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->action(function (): void {
                $user = $this->currentUser();

                if (! app(StrongAuthentication::class)->sendCode($user)) {
                    Notification::make()->danger()->title((string) __('profile.notifications.email_code_throttled'))->send();

                    return;
                }

                Notification::make()->success()->title((string) __('profile.notifications.email_code_sent'))->send();
            });
    }

    protected function emailIsChanging(Get $get): bool
    {
        return $get('email') !== $this->currentUser()->getAttribute('email');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[\SensitiveParameter] array $data): array
    {
        $user = $this->currentUser();
        $policy = app(MfaPolicy::class);
        abort_if(! $user->must_reset_password && ! $policy->userHasMethod($user), 403);
        $this->emailChanged = ($data['email'] ?? null) !== $user->email;
        if ($policy->enabled() && $policy->userHasMethod($user) && $this->emailChanged) {
            app(StrongAuthentication::class)->confirm($user,
                (string) ($this->data['profileCurrentPassword'] ?? ''),
                (string) ($this->data['profileConfirmationCode'] ?? ''),
                'data.profileConfirmationCode');
        }

        $this->applyLocalePreference(is_string($data['locale'] ?? null) ? $data['locale'] : null, $user);

        return $data;
    }

    /**
     * Apply an explicit language choice immediately and remember it for the
     * signed-out screens, so it survives signing out.
     */
    private function applyLocalePreference(?string $locale, User $user): void
    {
        if (! app(LocaleResolver::class)->isSupported($locale) || $locale === $user->locale) {
            return;
        }

        app()->setLocale((string) $locale);

        Cookie::queue(
            app(LocaleResolver::class)->cookieName(),
            $locale,
            (int) config('localization.cookie_lifetime'),
        );
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutatePasswordDataBeforeSave(#[\SensitiveParameter] array $data): array
    {
        $user = $this->currentUser();
        $policy = app(MfaPolicy::class);
        abort_if(! $user->must_reset_password && ! $policy->userHasMethod($user), 403);
        $this->passwordChanged = filled($data['password'] ?? null);
        if ($policy->enabled() && $policy->userHasMethod($user) && $this->passwordChanged) {
            app(StrongAuthentication::class)->confirm($user,
                (string) ($this->passwordData['currentPassword'] ?? ''),
                (string) ($this->passwordData['confirmationCode'] ?? ''),
                'passwordData.confirmationCode');
        }
        if ($user->must_reset_password) {
            $user->must_reset_password = false;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $user = $this->currentUser();
        $audit = app(AuditWriter::class);
        if ($this->emailChanged) {
            $audit->record('auth.email_changed', $user->id);
        }
        if ($this->passwordChanged) {
            $audit->record('auth.password_changed', $user->id);
        }
        session()->put('vaultigen.auth_version', $user->getAttribute('auth_version'));
        session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());
        session()->regenerate();
        $this->data['profileConfirmationCode'] = null;
        $this->passwordData['confirmationCode'] = null;
        if (! $user->must_reset_password && ! app(MfaPolicy::class)->userHasMethod($user)) {
            $this->redirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
