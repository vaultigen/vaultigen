<?php

namespace App\Filament\Pages;

use App\Mail\TestMail;
use App\Services\InstallationHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

class InstallationStatus extends Page
{
    protected string $view = 'filament.pages.installation-status';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getSubheading(): string
    {
        return (string) __('installation.subheading');
    }

    public function testMailAction(): Action
    {
        return Action::make('testMail')
            ->label((string) __('installation.test_mail.label'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->iconButton()
            ->tooltip((string) __('installation.test_mail.tooltip'))
            ->modalHeading((string) __('installation.test_mail.modal_heading'))
            ->modalDescription((string) __('installation.test_mail.modal_description'))
            ->modalSubmitActionLabel((string) __('installation.test_mail.modal_submit'))
            ->schema([
                TextInput::make('email')
                    ->label((string) __('installation.test_mail.email'))
                    ->email()
                    ->required()
                    ->maxLength(254)
                    ->default(fn (): ?string => auth()->user()?->email),
            ])
            ->action(function (array $data): void {
                $this->sendTestMail((string) $data['email']);
            });
    }

    private function sendTestMail(string $email): void
    {
        Gate::authorize('manage-installation');

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Notification::make()->warning()->title((string) __('installation.test_mail.not_delivering_title'))
                ->body((string) __('installation.test_mail.not_delivering_body'))->send();

            return;
        }

        try {
            Mail::to($email)->send(new TestMail);
        } catch (Throwable) {
            Notification::make()->danger()->title((string) __('installation.test_mail.failed_title'))
                ->body((string) __('installation.test_mail.failed_body'))->send();

            return;
        }

        Notification::make()->success()->title((string) __('installation.test_mail.sent_title'))
            ->body((string) __('installation.test_mail.sent_body', ['email' => $email]))->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        $report = app(InstallationHealth::class)->report();

        return [
            'report' => $report,
            'summary' => $this->summarize($report),
            'trustNotes' => [
                [
                    'heading' => (string) __('installation.trust.server_trusted.heading'),
                    'icon' => Heroicon::OutlinedShieldExclamation,
                    'body' => (string) __('installation.trust.server_trusted.body'),
                ],
                [
                    'heading' => (string) __('installation.trust.metadata.heading'),
                    'icon' => Heroicon::OutlinedEye,
                    'body' => (string) __('installation.trust.metadata.body'),
                ],
                [
                    'heading' => (string) __('installation.trust.personal_vaults.heading'),
                    'icon' => Heroicon::OutlinedUserGroup,
                    'body' => (string) __('installation.trust.personal_vaults.body'),
                ],
                [
                    'heading' => (string) __('installation.trust.audit_checkpoints.heading'),
                    'icon' => Heroicon::OutlinedClipboardDocumentCheck,
                    'body' => (string) __('installation.trust.audit_checkpoints.body'),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{label: string, status: string, state: 'healthy'|'warning'|'error'}>  $report
     * @return array{color: string, icon: Heroicon, heading: string, description: string}
     */
    protected function summarize(array $report): array
    {
        $failed = collect($report)->where('state', 'error')->count();
        $review = collect($report)->where('state', 'warning')->count();

        if ($failed > 0) {
            $description = trans_choice('installation.summary.failed_required', $failed);
            $description .= $review > 0
                ? trans_choice('installation.summary.review_suffix', $review)
                : '.';

            return [
                'color' => 'danger',
                'icon' => Heroicon::OutlinedXCircle,
                'heading' => (string) __('installation.summary.needs_attention'),
                'description' => $description,
            ];
        }

        if ($review > 0) {
            return [
                'color' => 'warning',
                'icon' => Heroicon::OutlinedExclamationTriangle,
                'heading' => (string) __('installation.summary.review_recommended'),
                'description' => trans_choice('installation.summary.review_only', $review),
            ];
        }

        return [
            'color' => 'success',
            'icon' => Heroicon::OutlinedCheckCircle,
            'heading' => (string) __('installation.summary.all_passing'),
            'description' => (string) __('installation.summary.all_passing_description'),
        ];
    }
}
