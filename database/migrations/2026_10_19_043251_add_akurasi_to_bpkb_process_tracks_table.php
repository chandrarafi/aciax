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
            $table->string('akurasi', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aciax.bpkb_process_tracks', function (Blueprint $table) {
            $table->dropColumn('akurasi');
        });
    }
};
