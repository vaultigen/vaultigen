<?php

namespace App\Console\Commands;

use App\Mail\AdminRecovered;
use App\Mail\EmergencyAccessStarted;
use App\Mail\FactorsReset;
use App\Mail\VaultPurgeInitiated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverSecurityNotifications extends Command
{
    protected $signature = 'vaultigen:deliver-security-notifications';

    protected $description = 'Deliver or retry durable security notifications without secret content';

    public function handle(): int
    {
        foreach (DB::table('security_notification_deliveries')->whereNull('delivered_at')->orderBy('id')->limit(100)->get() as $delivery) {
            Cache::lock('security-delivery:'.$delivery->id, 120)->get(function () use ($delivery): void {
                if (DB::table('security_notification_deliveries')->where('id', $delivery->id)->whereNotNull('delivered_at')->exists()) {
                    return;
                }
                DB::table('security_notification_deliveries')->where('id', $delivery->id)->increment('attempts');
                if (in_array(config('mail.default'), ['log', 'array'])) {
                    DB::table('security_notification_deliveries')->where('id', $delivery->id)->update(['failure_code' => 'mail_transport_unconfigured']);

                    return;
                }
                try {
                    Mail::to($delivery->recipient)->send(match (DB::table('audit_events')->where('sequence', $delivery->audit_sequence)->value('action')) {
                        'auth.admin_factor_reset' => new FactorsReset((int) $delivery->audit_sequence),
                        'vault.emergency_started' => new EmergencyAccessStarted((int) $delivery->audit_sequence),
                        'vault.purge_initiated' => new VaultPurgeInitiated((int) $delivery->audit_sequence),
                        default => new AdminRecovered((int) $delivery->audit_sequence),
                    });
                    DB::table('security_notification_deliveries')->where('id', $delivery->id)->update(['delivered_at' => now(), 'failure_code' => null]);
                } catch (Throwable) {
                    DB::table('security_notification_deliveries')->where('id', $delivery->id)->update(['failure_code' => 'mail_delivery_failed']);
                }
            });
        }

        return self::SUCCESS;
    }
}
