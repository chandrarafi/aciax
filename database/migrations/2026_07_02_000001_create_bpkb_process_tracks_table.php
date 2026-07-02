<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpkb_process_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('no_mesin', 16)->index();
            $table->string('no_bpkb', 20);
            $table->string('nama_konsumen')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('image_paths')->nullable();
            $table->string('stage', 30)->default('pending'); // pending, create_pdf, update_stok_unit, update_tgl_bpkb_siap, completed, failed
            $table->string('status', 15)->default('queued'); // queued, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpkb_process_tracks');
    }
};
