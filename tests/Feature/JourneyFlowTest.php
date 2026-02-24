<?php

namespace Tests\Feature;

use App\Services\BtcGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JourneyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_kyc_with_smega_short_track_flow_completes(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('c1SubscriberRetrieve')->once()->andReturn(['ok' => true, 'exists' => true]);
            $mock->shouldReceive('smegaCheck')->once()->andReturn(['ok' => true, 'body' => 'not exists']);
            $mock->shouldReceive('smegaRegister')->once()->andReturn(['ok' => true, 'status' => 200]);
            $mock->shouldReceive('c1ApplyConditionalUpdates')->once()->andReturn(['ok' => true, 'steps' => []]);
            $mock->shouldReceive('logTransaction')->once()->andReturn(['ok' => true, 'status' => 200]);
        });

        $start = $this->postJson('/api/kyc/start', [
            'smega_selected' => true,
            'source_of_income' => 'SALARY',
        ])->assertCreated();

        $requestId = (string) $start->json('request_id');

        $this->postJson("/api/kyc/{$requestId}/msisdn", ['msisdn' => '26773717137'])
            ->assertOk()
            ->assertJsonPath('track', 'short_track');

        $send = $this->postJson("/api/kyc/{$requestId}/otp/send")->assertCreated();
        $challengeId = (int) $send->json('challenge_id');
        $code = (string) $send->json('debug_code');

        $this->postJson("/api/kyc/{$requestId}/otp/verify", [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk();

        $this->postJson("/api/kyc/{$requestId}/profile", [
            'city' => 'Gaborone',
            'postal_address' => 'Plot 101',
        ])->assertOk();

        $this->postJson("/api/kyc/{$requestId}/complete")
            ->assertOk()
            ->assertJsonPath('kyc_status', 'complete')
            ->assertJsonPath('smega_status', 'registered');
    }

    public function test_kyc_standalone_full_kyc_requires_metamap_verification(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('c1SubscriberRetrieve')->once()->andReturn(['ok' => false, 'exists' => false]);
            $mock->shouldReceive('bocraCheckByMsisdn')->once()->andReturn(['ok' => true, 'compliant' => true]);
            $mock->shouldReceive('c1ApplyConditionalUpdates')->once()->andReturn(['ok' => true, 'steps' => []]);
            $mock->shouldReceive('logTransaction')->once()->andReturn(['ok' => true, 'status' => 200]);
        });

        $start = $this->postJson('/api/kyc/start', [
            'smega_selected' => false,
        ])->assertCreated();

        $requestId = (string) $start->json('request_id');

        $this->postJson("/api/kyc/{$requestId}/msisdn", ['msisdn' => '26773717137'])
            ->assertOk()
            ->assertJsonPath('track', 'full_kyc');

        $send = $this->postJson("/api/kyc/{$requestId}/otp/send")->assertCreated();
        $challengeId = (int) $send->json('challenge_id');
        $code = (string) $send->json('debug_code');

        $this->postJson("/api/kyc/{$requestId}/otp/verify", [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk();

        $this->postJson("/api/kyc/{$requestId}/profile", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'id_type' => 'NATIONAL_ID',
            'id_number' => '935512806',
            'city' => 'Gaborone',
        ])->assertOk();

        $this->postJson("/api/kyc/{$requestId}/metamap/gate", ['verified' => false])
            ->assertStatus(422);

        $this->postJson("/api/kyc/{$requestId}/metamap/gate", ['verified' => true])
            ->assertOk();

        $this->postJson("/api/kyc/{$requestId}/complete")
            ->assertOk()
            ->assertJsonPath('journey_type', 'kyc_standalone')
            ->assertJsonPath('smega_status', 'skipped');
    }

    public function test_smega_standalone_non_compliant_runs_inline_kyc_then_completes(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('c1SubscriberRetrieve')->once()->andReturn(['ok' => false, 'exists' => false]);
            $mock->shouldReceive('bocraCheckByMsisdn')->once()->andReturn(['ok' => true, 'compliant' => true]);
            $mock->shouldReceive('c1ApplyConditionalUpdates')->once()->andReturn(['ok' => true, 'steps' => []]);
            $mock->shouldReceive('smegaCheck')->once()->andReturn(['ok' => true, 'body' => 'not exists']);
            $mock->shouldReceive('smegaRegister')->once()->andReturn(['ok' => true]);
            $mock->shouldReceive('logTransaction')->once()->andReturn(['ok' => true, 'status' => 200]);
        });

        $start = $this->postJson('/api/smega/start')->assertCreated();
        $requestId = (string) $start->json('request_id');

        $this->postJson("/api/smega/{$requestId}/msisdn", ['msisdn' => '26773717137'])
            ->assertOk()
            ->assertJsonPath('status', 'non_compliant');

        $this->postJson("/api/smega/{$requestId}/inline-kyc/complete", [
            'metamap_verified' => true,
        ])->assertOk();

        $send = $this->postJson("/api/smega/{$requestId}/otp/send")->assertCreated();
        $challengeId = (int) $send->json('challenge_id');
        $code = (string) $send->json('debug_code');

        $this->postJson("/api/smega/{$requestId}/otp/verify", [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk();

        $this->postJson("/api/smega/{$requestId}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'SMEGA Complete')
            ->assertJsonPath('registered', true);
    }
}
