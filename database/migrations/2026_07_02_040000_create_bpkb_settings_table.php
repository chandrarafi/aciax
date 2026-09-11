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

        Schema::create('aciax.bpkb_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed setting default untuk scan effect (aktif secara default)
        DB::table('aciax.bpkb_settings')->insert([
            [
                'key'         => 'scan_effect_enabled',
                'value'       => '1',
                'description' => 'Mengaktifkan atau menonaktifkan efek scan dokumen (1 = Aktif, 0 = Nonaktif)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'scan_contrast',
                'value'       => '-12',
                'description' => 'Level kontras scanner (-5 sampai -30, semakin minus semakin kontras)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'scan_brightness',
                'value'       => '8',
                'description' => 'Level kecerahan background scanner (0 sampai 20)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aciax.bpkb_settings');
        Schema::dropIfExists('bpkb_settings');
    }
};
