<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->string('scope', 20)->default('vault');
            $table->string('global_key', 60)->nullable();
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->uuid('vault_id')->nullable()->change();
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->unique('global_key', 'uk_tags_global_key');
        });

        DB::table('tags')->update(['scope' => 'vault']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('entry_tag', function (Blueprint $table): void {
                $table->dropForeign(['tag_id', 'vault_id']);
            });
        } else {
            Schema::table('entry_tag', function (Blueprint $table): void {
                $table->dropForeign('fk_entry_tag_tag_vault');
            });
        }

        Schema::table('entry_tag', function (Blueprint $table): void {
            $table->foreign('tag_id', 'fk_entry_tag_tag')->references('id')->on('tags');
        });
    }

    public function down(): void
    {
        // Rolling back requires that no installation-wide tag exists and that
        // every tag is attached only inside its own vault.
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('entry_tag', function (Blueprint $table): void {
                $table->dropForeign(['tag_id']);
            });
        } else {
            Schema::table('entry_tag', function (Blueprint $table): void {
                $table->dropForeign('fk_entry_tag_tag');
            });
        }

        Schema::table('entry_tag', function (Blueprint $table): void {
            $table->foreign(['tag_id', 'vault_id'], 'fk_entry_tag_tag_vault')
                ->references(['id', 'vault_id'])->on('tags');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique('uk_tags_global_key');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'global_key']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->uuid('vault_id')->nullable(false)->change();
        });
    }
};
