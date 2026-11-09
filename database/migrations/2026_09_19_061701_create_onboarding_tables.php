<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('token_digest', 64)->unique();
            $table->string('intended_normalized_email');
            $table->foreignUuid('invited_by_user_id')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('account_factor_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->text('reason');
            $table->unsignedBigInteger('audit_sequence');
            $table->timestamp('created_at');
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->unique()->constrained('principals');
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestamps();
        });
        Schema::create('team_memberships', function (Blueprint $table): void {
            $table->foreignUuid('team_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('created_at');
            $table->primary(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('account_factor_resets');
        Schema::dropIfExists('invitations');
    }
};
