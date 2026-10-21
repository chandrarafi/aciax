<?php

namespace Tests\Feature;

use Tests\TestCase;

class BastdDetailApiTest extends TestCase
{
    private function getHeaders(): array
    {
        return [
            'SECRET-KEY' => config('app.secret_key', 'U2FsdGVkX19jfmhRGNeUJ2TITVhrP0hzNJ9HNv81qSs='),
        ];
    }

    public function test_bastd_detail_unauthorized_without_secret_key(): void
    {
        $response = $this->getJson('/api/bastd-detail');
        $response->assertStatus(401);
    }

    public function test_bastd_detail_index(): void
    {
        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bastd-detail?per_page=5');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    public function test_bastd_detail_filter_no_mesin(): void
    {
        $response = $this->withHeaders($this->getHeaders())
            ->getJson('/api/bastd-detail?no_mesin=KC81E1062655&all=true');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'total',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }
}
