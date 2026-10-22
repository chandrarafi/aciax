<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('aciax.bpkb_process_tracks', function (Blueprint $table) {
            if (!Schema::hasColumn('aciax.bpkb_process_tracks', 'fk_dealer')) {
                $table->string('fk_dealer', 10)->nullable()->index();
            }
            if (!Schema::hasColumn('aciax.bpkb_process_tracks', 'tgl_mohon_samsat')) {
                $table->date('tgl_mohon_samsat')->nullable()->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aciax.bpkb_process_tracks', function (Blueprint $table) {
            if (Schema::hasColumn('aciax.bpkb_process_tracks', 'fk_dealer')) {
                $table->dropColumn('fk_dealer');
            }
            if (Schema::hasColumn('aciax.bpkb_process_tracks', 'tgl_mohon_samsat')) {
                $table->dropColumn('tgl_mohon_samsat');
            }
        });
    }
};
