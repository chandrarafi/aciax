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
}
