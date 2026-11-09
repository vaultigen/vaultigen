<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 20);
            $table->timestamp('created_at');
        });
        Schema::create('users_uuid', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->unique()->constrained('principals');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('normalized_email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_reset_password')->default(false);
            $table->unsignedInteger('auth_version')->default(1);
            $table->timestamp('policy_accepted_at')->nullable();
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $principalId = (string) Str::uuid();
            DB::table('principals')->insert(['id' => $principalId, 'kind' => 'user', 'created_at' => now()]);
            $data = (array) $user;
            $data['id'] = (string) Str::uuid();
            $data['principal_id'] = $principalId;
            $data['normalized_email'] = Str::lower(trim($user->email));
            $data['remember_token'] = null;
            DB::table('users_uuid')->insert($data);
        }
        Schema::drop('sessions');
        DB::table('password_reset_tokens')->delete();
        Schema::drop('users');
        Schema::rename('users_uuid', 'users');
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 30)->unique();
        });
        DB::table('platform_roles')->insert([['name' => 'super_admin'], ['name' => 'auditor']]);
        Schema::create('user_platform_roles', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained();
            $table->foreignId('platform_role_id')->constrained();
            $table->primary(['user_id', 'platform_role_id']);
        });
        Schema::create('installation_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('display_name', 100)->default('Vaultigen');
            $table->unsignedSmallInteger('inactivity_minutes')->default(15);
            $table->unsignedSmallInteger('absolute_minutes')->default(720);
            $table->json('security_recipients');
            $table->uuid('installation_id')->nullable();
            $table->timestamp('bootstrapped_at')->nullable();
        });
        DB::table('installation_settings')->insert(['id' => 1, 'security_recipients' => '[]']);
        Schema::create('audit_chain_heads', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('event_hash', 64);
        });
        DB::table('audit_chain_heads')->insert(['id' => 1, 'sequence' => 0, 'event_hash' => str_repeat('0', 64)]);
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('outcome', 20);
            $table->string('occurred_at', 32);
            $table->longText('canonical_event');
            $table->string('previous_hash', 64);
            $table->string('event_hash', 64);
        });
        Schema::create('audit_checkpoints', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->primary();
            $table->string('event_hash', 64);
            $table->string('key_id', 50);
            $table->string('mac', 64);
        });
        Schema::create('security_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('audit_sequence');
            $table->string('recipient');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->unique(['audit_sequence', 'recipient']);
        });
        Schema::create('operational_heartbeats', function (Blueprint $table): void {
            $table->string('name', 30)->primary();
            $table->timestamp('observed_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('UUID identity migration cannot be reversed safely. Restore a pre-migration backup.');
    }
};
