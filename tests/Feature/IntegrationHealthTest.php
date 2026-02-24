<?php

namespace Tests\Feature;

use App\Services\BtcGatewayService;
use Tests\TestCase;

class IntegrationHealthTest extends TestCase
{
    public function test_integration_health_endpoint_returns_combined_status(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldReceive('c1SubscriberRetrieve')->once()->andReturn(['ok' => true, 'status' => 200]);
            $mock->shouldReceive('bocraCheckByMsisdn')->once()->andReturn(['ok' => true, 'status' => 200]);
            $mock->shouldReceive('smegaCheck')->once()->andReturn(['ok' => true, 'status' => 200]);
        });

        $response = $this->getJson('/api/health/integrations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.c1.ok', true)
            ->assertJsonPath('checks.bocra.ok', true)
            ->assertJsonPath('checks.smega.ok', true);
    }
}

