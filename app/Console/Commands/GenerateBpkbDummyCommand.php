<?php

namespace App\Console\Commands;

use App\Models\BpkbProcessTrack;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateBpkbDummyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bpkb:dummy {count=15 : Jumlah data dummy yang ingin dibuat}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membuat data dummy proses BPKB tanpa file PDF untuk tampilan dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = (int) $this->argument('count');
        if ($count <= 0) {
            $count = 15;
        }

        $names = [
            'ALDI FRANSISKUS NAINGGOLAN',
            'BUDI SANTOSO',
            'SITI NURHALIZA',
            'AHMAD HIDAYAT',
            'RINA WIJAYA',
            'DEDI KURNIAWAN',
            'SRI WAHYUNI',
            'HENDRA SAPUTRA',
            'DEWI LESTARI',
            'MUHAMMAD RIZKY',
            'AGUS PRASETYO',
            'FITRI HANDAYANI',
            'EKO PRASETYA',
            'MAYA INDAH',
            'DONI KUSUMA',
            'ANDI WIJAYA',
            'NURUL HIDAYAH',
            'RIZAL EFENDI',
            'MEGAWATI SUKARNO',
            'JOKO WIDODO',
        ];

        $letters = ['W', 'X', 'V', 'U', 'T', 'S', 'R', 'Q', 'P', 'N', 'M'];
        $prefixes = ['JMC1E', 'JMG1E', 'JM31E', 'JFA1E', 'JFB1E', 'JFC2E', 'JFD3E', 'JFE4E', 'JFF5E'];
        $statuses = [
            ['status' => 'completed', 'stage' => 'completed', 'error' => null],
            ['status' => 'completed', 'stage' => 'completed', 'error' => null],
            ['status' => 'completed', 'stage' => 'completed', 'error' => null],
            ['status' => 'completed', 'stage' => 'completed', 'error' => null],
            ['status' => 'completed', 'stage' => 'completed', 'error' => null],
            ['status' => 'processing', 'stage' => 'create_pdf', 'error' => null],
            ['status' => 'queued', 'stage' => 'pending', 'error' => null],
            ['status' => 'failed', 'stage' => 'update_stok_unit', 'error' => 'Koneksi database DMS timeout saat update stok unit'],
        ];

        $this->info("Membuat {$count} data dummy BPKB...");

        for ($i = 0; $i < $count; $i++) {
            $prefix = $prefixes[array_rand($prefixes)];
            $serial = str_pad((string) rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
            $noMesin = $prefix . $serial;

            $let = $letters[array_rand($letters)];
            $bpkbNum = str_pad((string) rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
            $noBpkb = $let . '-' . $bpkbNum;

            $st = $statuses[array_rand($statuses)];
            $name = $names[$i % count($names)];
            $createdAt = Carbon::now()->subHours(rand(0, 72))->subMinutes(rand(0, 59));

            BpkbProcessTrack::create([
                'id'            => (string) Str::uuid(),
                'no_mesin'      => $noMesin,
                'no_bpkb'       => $noBpkb,
                'nama_konsumen' => $name,
                'pdf_path'      => null, // Tanpa file PDF
                'image_paths'   => null,
                'stage'         => $st['stage'],
                'status'        => $st['status'],
                'error_message' => $st['error'],
                'created_at'    => $createdAt,
                'updated_at'    => $createdAt,
            ]);
        }

        $this->info("Sukses! {$count} data dummy BPKB telah berhasil dibuat tanpa PDF.");

        return Command::SUCCESS;
    }
}
