<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_api_routes_are_registered(): void
    {
        config(['app.url' => 'http://localhost']);
        $response = $this->postJson('/api/otp/send', []);

        $response->assertStatus(422);
    }
}
