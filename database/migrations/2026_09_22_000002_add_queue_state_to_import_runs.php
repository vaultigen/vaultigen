<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->longText('wrapped_credentials')->nullable()->after('wrapped_import_key');
            $table->string('credentials_key_id', 60)->nullable()->after('wrapped_credentials');
            $table->unsignedInteger('progress_total')->nullable()->after('failure_count');
            $table->timestamp('queued_at')->nullable()->after('started_at');
            $table->timestamp('cancel_requested_at')->nullable()->after('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'wrapped_credentials',
                'credentials_key_id',
                'progress_total',
                'queued_at',
                'cancel_requested_at',
            ]);
        });
    }
};
