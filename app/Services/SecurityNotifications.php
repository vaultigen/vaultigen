<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Durable security-notification queue.
 *
 * A committed security event must not be lost between its transaction and the
 * queue, so notifications are written to a durable table and delivered with
 * retry by the scheduler. Only non-secret recipients and audit sequences are
 * stored.
 */
final class SecurityNotifications
{
    /**
     * @param  list<string>  $recipients
     * @return int the number of distinct recipients queued
     */
    public function queue(int $auditSequence, array $recipients): int
    {
        $recipients = array_values(array_unique(array_filter(
            array_map(fn (string $recipient): string => trim($recipient), $recipients),
            fn (string $recipient): bool => $recipient !== '',
        )));

        foreach ($recipients as $recipient) {
            DB::table('security_notification_deliveries')->insert([
                'id' => (string) Str::uuid(),
                'audit_sequence' => $auditSequence,
                'recipient' => $recipient,
            ]);
        }

        return count($recipients);
    }
}
