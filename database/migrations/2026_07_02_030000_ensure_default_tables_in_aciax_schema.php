<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS aciax');

        // Helper to check if a table exists in public schema
        $tableExistsInPublic = function (string $table): bool {
            $result = DB::selectOne("
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);
            return $result !== null;
        };

        // Helper to check if a table exists in aciax schema
        $tableExistsInAciax = function (string $table): bool {
            $result = DB::selectOne("
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = 'aciax' AND table_name = ?
            ", [$table]);
            return $result !== null;
        };

        $tablesToMove = [
            'jobs',
            'job_batches',
            'failed_jobs',
            'cache',
            'cache_locks',
            'users',
            'password_reset_tokens',
            'sessions',
            'personal_access_tokens',
        ];

        // 1. Move tables from public to aciax if they exist in public but not in aciax
        foreach ($tablesToMove as $table) {
            if ($tableExistsInPublic($table) && !$tableExistsInAciax($table)) {
                DB::statement("ALTER TABLE public.{$table} SET SCHEMA aciax");
            }
        }

        // 2. Ensure jobs tables exist in aciax
        if (!$tableExistsInAciax('jobs')) {
            Schema::create('aciax.jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!$tableExistsInAciax('job_batches')) {
            Schema::create('aciax.job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!$tableExistsInAciax('failed_jobs')) {
            Schema::create('aciax.failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        // 3. Ensure cache tables exist in aciax
        if (!$tableExistsInAciax('cache')) {
            Schema::create('aciax.cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->bigInteger('expiration')->index();
            });
        }

        if (!$tableExistsInAciax('cache_locks')) {
            Schema::create('aciax.cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration')->index();
            });
        }

        // 4. Ensure auth & session tables exist in aciax
        if (!$tableExistsInAciax('users')) {
            Schema::create('aciax.users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!$tableExistsInAciax('password_reset_tokens')) {
            Schema::create('aciax.password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!$tableExistsInAciax('sessions')) {
            Schema::create('aciax.sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        // 5. Ensure personal_access_tokens exists in aciax
        if (!$tableExistsInAciax('personal_access_tokens')) {
            Schema::create('aciax.personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tables remain in aciax or drop if needed
    }
};
