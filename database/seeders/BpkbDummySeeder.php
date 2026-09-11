<?php

namespace Database\Seeders;

use App\Models\BpkbProcessTrack;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BpkbDummySeeder extends Seeder
{
    /**
     * Seed dummy BPKB process tracks without PDF files for dashboard preview.
     */
    public function run(): void
    {
        $dummyData = [
            [
                'no_mesin'       => 'JMC1E1782297',
                'no_bpkb'        => 'W-04269957',
                'nama_konsumen'  => 'ALDI FRANSISKUS NAINGGOLAN',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subMinutes(15),
            ],
            [
                'no_mesin'       => 'JMG1E1875575',
                'no_bpkb'        => 'X-00322153',
                'nama_konsumen'  => 'BUDI SANTOSO',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subHours(1)->subMinutes(10),
            ],
            [
                'no_mesin'       => 'JM31E1234567',
                'no_bpkb'        => 'V-09182371',
                'nama_konsumen'  => 'SITI NURHALIZA',
                'status'         => 'processing',
                'stage'          => 'create_pdf',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subMinutes(5),
            ],
            [
                'no_mesin'       => 'JFA1E9988776',
                'no_bpkb'        => 'U-11223344',
                'nama_konsumen'  => 'AHMAD HIDAYAT',
                'status'         => 'queued',
                'stage'          => 'pending',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subMinutes(2),
            ],
            [
                'no_mesin'       => 'JFB1E5544332',
                'no_bpkb'        => 'W-08273615',
                'nama_konsumen'  => 'RINA WIJAYA',
                'status'         => 'failed',
                'stage'          => 'update_stok_unit',
                'error_message'  => 'Koneksi database DMS timeout saat update stok unit',
                'created_at'     => Carbon::now()->subHours(2),
            ],
            [
                'no_mesin'       => 'JFC2E7766554',
                'no_bpkb'        => 'T-04928173',
                'nama_konsumen'  => 'DEDI KURNIAWAN',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subHours(3),
            ],
            [
                'no_mesin'       => 'JFD3E1122334',
                'no_bpkb'        => 'S-01928374',
                'nama_konsumen'  => 'SRI WAHYUNI',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subHours(4),
            ],
            [
                'no_mesin'       => 'JFE4E9988112',
                'no_bpkb'        => 'R-08271635',
                'nama_konsumen'  => 'HENDRA SAPUTRA',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subHours(5),
            ],
            [
                'no_mesin'       => 'JFF5E4455667',
                'no_bpkb'        => 'Q-03829104',
                'nama_konsumen'  => 'DEWI LESTARI',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::yesterday()->setHour(14)->setMinute(20),
            ],
            [
                'no_mesin'       => 'JFG6E8877665',
                'no_bpkb'        => 'P-09283746',
                'nama_konsumen'  => 'MUHAMMAD RIZKY',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::yesterday()->setHour(11)->setMinute(15),
            ],
            [
                'no_mesin'       => 'JFH7E2233445',
                'no_bpkb'        => 'N-01827364',
                'nama_konsumen'  => 'AGUS PRASETYO',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subDays(2)->setHour(15)->setMinute(45),
            ],
            [
                'no_mesin'       => 'JFJ8E6677889',
                'no_bpkb'        => 'M-07382910',
                'nama_konsumen'  => 'FITRI HANDAYANI',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subDays(3)->setHour(9)->setMinute(30),
            ],
            [
                'no_mesin'       => 'JFK9E1133557',
                'no_bpkb'        => 'L-05928174',
                'nama_konsumen'  => 'EKO PRASETYA',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subDays(4)->setHour(16)->setMinute(10),
            ],
            [
                'no_mesin'       => 'JFL0E7799113',
                'no_bpkb'        => 'K-03819284',
                'nama_konsumen'  => 'MAYA INDAH',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subDays(5)->setHour(13)->setMinute(25),
            ],
            [
                'no_mesin'       => 'JFM1E3355779',
                'no_bpkb'        => 'J-02819385',
                'nama_konsumen'  => 'DONI KUSUMA',
                'status'         => 'completed',
                'stage'          => 'completed',
                'error_message'  => null,
                'created_at'     => Carbon::now()->subDays(6)->setHour(10)->setMinute(50),
            ],
        ];

        foreach ($dummyData as $item) {
            BpkbProcessTrack::create([
                'id'            => (string) Str::uuid(),
                'no_mesin'      => $item['no_mesin'],
                'no_bpkb'       => $item['no_bpkb'],
                'nama_konsumen' => $item['nama_konsumen'],
                'pdf_path'      => null, // Tanpa file PDF fisik sesuai permintaan
                'image_paths'   => null,
                'stage'         => $item['stage'],
                'status'        => $item['status'],
                'error_message' => $item['error_message'],
                'created_at'    => $item['created_at'],
                'updated_at'    => $item['created_at'],
            ]);
        }

        $this->command->info(count($dummyData) . ' data dummy BPKB berhasil dibuat tanpa PDF.');
    }
}
