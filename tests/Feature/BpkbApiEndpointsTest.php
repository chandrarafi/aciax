<?php

namespace Tests\Feature;

use App\Models\BpkbProcessTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BpkbApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function getHeaders(): array
    {
        return [
            'SECRET-KEY' => config('app.secret_key', 'U2FsdGVkX19jfmhRGNeUJ2TITVhrP0hzNJ9HNv81qSs='),
        ];
    }

    public function test_activity_today_endpoint(): void
    {
        BpkbProcessTrack::create([
            'no_mesin' => 'JME1E2977865',
            'no_bpkb'  => 'W-02371856',
            'stage'    => 'completed',
            'status'   => 'completed',
        ]);

        BpkbProcessTrack::create([
            'no_mesin' => 'JME1E2977866',
            'no_bpkb'  => 'W-02371857',
            'stage'    => 'pending',
            'status'   => 'queued',
        ]);

        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/activity/today');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'count',
                'target',
                'date',
            ])
            ->assertJson([
                'count'  => 1,
                'target' => 100,
                'date'   => now()->toDateString(),
            ]);
    }

    public function test_recent_bpkb_endpoint_with_default_limit(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            BpkbProcessTrack::create([
                'no_mesin' => "JME1E297786{$i}",
                'no_bpkb'  => "W-0237185{$i}",
                'stage'    => 'completed',
                'status'   => 'completed',
            ]);
        }

        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bpkb/recent');

        $response->assertStatus(200)
            ->assertJsonCount(5);
    }

    public function test_recent_bpkb_endpoint_with_custom_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            BpkbProcessTrack::create([
                'no_mesin' => "JME1E297786{$i}",
                'no_bpkb'  => "W-0237185{$i}",
                'stage'    => 'completed',
                'status'   => 'completed',
            ]);
        }

        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bpkb/recent?limit=3');

        $response->assertStatus(200)
            ->assertJsonCount(3)
            ->assertJsonStructure([
                '*' => [
                    'track_id',
                    'nobpkb',
                    'nomesin',
                    'status',
                    'created_at',
                ],
            ]);
    }

    public function test_download_bpkb_pdf(): void
    {
        \Illuminate\Support\Facades\Storage::fake('secure');
        $pdfPath = 'bpkb/pdf/test.pdf';
        \Illuminate\Support\Facades\Storage::disk('secure')->put($pdfPath, 'dummy pdf content');

        $track = BpkbProcessTrack::create([
            'no_mesin'  => 'JME1E2977861',
            'no_bpkb'   => 'W-02371851',
            'stage'     => 'completed',
            'status'    => 'completed',
            'pdf_path'  => $pdfPath,
        ]);

        $response = $this->get("/bpkb/download/{$track->id}");
        $response->assertStatus(200);
    }

    public function test_by_dealer_endpoint(): void
    {
        BpkbProcessTrack::create([
            'no_mesin'         => 'JME1E2977871',
            'no_bpkb'          => 'W-02371871',
            'nama_konsumen'    => 'Budi Santoso',
            'fk_dealer'        => 'DLR01',
            'tgl_mohon_samsat' => '2026-04-10',
            'stage'            => 'completed',
            'status'           => 'completed',
        ]);

        BpkbProcessTrack::create([
            'no_mesin'         => 'JME1E2977872',
            'no_bpkb'          => 'W-02371872',
            'nama_konsumen'    => 'Siti Rahma',
            'fk_dealer'        => 'DLR01',
            'tgl_mohon_samsat' => '2026-04-10',
            'stage'            => 'completed',
            'status'           => 'completed',
        ]);

        BpkbProcessTrack::create([
            'no_mesin'         => 'JME1E2977873',
            'no_bpkb'          => 'W-02371873',
            'nama_konsumen'    => 'Agus Pratama',
            'fk_dealer'        => 'DLR02',
            'tgl_mohon_samsat' => '2026-04-11',
            'stage'            => 'pending',
            'status'           => 'queued',
        ]);

        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bpkb/by-dealer');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'summary' => [
                    'total_dealers',
                    'total_records',
                    'total_completed',
                    'total_processing',
                    'total_queued',
                    'total_failed',
                ],
                'data' => [
                    '*' => [
                        'fk_dealer',
                        'nm_dealer',
                        'total',
                        'dates' => [
                            '*' => [
                                'tgl_mohon_samsat',
                                'total',
                                'details',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_retry_with_corrected_no_mesin(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('secure');
        $pdfPath = 'bpkb/pdf/test_retry.pdf';
        \Illuminate\Support\Facades\Storage::disk('secure')->put($pdfPath, 'dummy pdf content');

        $track = BpkbProcessTrack::create([
            'no_mesin'  => 'JMF2E1833648',
            'no_bpkb'   => 'X-00338213',
            'stage'     => 'failed',
            'status'    => 'failed',
            'pdf_path'  => $pdfPath,
        ]);

        $response = $this->withHeaders($this->getHeaders())
            ->postJson("/api/bpkb/retry/{$track->id}", [
                'nomesin' => 'JMF2E1838648',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data'   => [
                    'track_id' => (string) $track->id,
                    'no_mesin' => 'JMF2E1838648',
                    'no_bpkb'  => 'X-00338213',
                ],
            ]);

        $track->refresh();
        $this->assertEquals('JMF2E1838648', $track->no_mesin);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessBpkbJob::class);
    }

    public function test_process_bpkb_job_fails_when_stokunit_not_found_but_preserves_pdf(): void
    {
        \Illuminate\Support\Facades\Storage::fake('secure');
        $pdfPath = 'bpkb/pdf/sample.pdf';
        \Illuminate\Support\Facades\Storage::disk('secure')->put($pdfPath, 'sample pdf content');

        $track = BpkbProcessTrack::create([
            'no_mesin'  => 'NONEXISTENT999',
            'no_bpkb'   => 'X-99999999',
            'stage'     => 'pending',
            'status'    => 'queued',
            'pdf_path'  => $pdfPath,
        ]);

        $job = new \App\Jobs\ProcessBpkbJob($track);

        try {
            $job->handle();
            $this->fail('Expected exception was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Data stok unit untuk no. mesin', $e->getMessage());
        }

        $track->refresh();
        $this->assertEquals('failed', $track->status);
        $this->assertStringContainsString('tidak ditemukan di database H1_DOS.stokunit', $track->error_message);
        $this->assertEquals($pdfPath, $track->pdf_path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('secure')->exists($pdfPath));
    }

    public function test_monitoring_endpoint_returns_nm_dealer(): void
    {
        $track = BpkbProcessTrack::create([
            'no_mesin'         => 'JME1E2977899',
            'no_bpkb'          => 'W-02371899',
            'nama_konsumen'    => 'John Doe',
            'fk_dealer'        => '01001',
            'tgl_mohon_samsat' => '2026-03-15',
            'stage'            => 'completed',
            'status'           => 'completed',
        ]);

        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bpkb/monitoring');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'stats',
                'pagination',
                'data' => [
                    '*' => [
                        'track_id',
                        'no_mesin',
                        'no_bpkb',
                        'nama_konsumen',
                        'fk_dealer',
                        'nm_dealer',
                        'tgl_mohon_samsat',
                        'stage',
                        'status',
                    ],
                ],
            ]);

        $item = collect($response->json('data'))->firstWhere('track_id', (string) $track->id);
        $this->assertNotNull($item);
        $this->assertEquals('01001', $item['fk_dealer']);
        $this->assertArrayHasKey('nm_dealer', $item);
    }
}
