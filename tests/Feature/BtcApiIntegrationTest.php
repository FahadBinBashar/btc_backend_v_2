<?php

namespace Tests\Feature;

use App\Services\BtcGatewayService;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BtcApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    public function test_payment_record_calls_dpo_gateway_when_provider_is_dpo(): void
    {
        putenv('PAYMENT_PROVIDER=dpo');
        $_ENV['PAYMENT_PROVIDER'] = 'dpo';
        $_SERVER['PAYMENT_PROVIDER'] = 'dpo';

        config([
            'services.dpo.paygate_url' => 'https://paygate.example.test/initiate',
            'services.dpo.id' => '1046979100035',
            'services.dpo.secret' => 'test-secret',
        ]);

        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldReceive('dpoInitiatePayment')
                ->once()
                ->andReturn([
                    'ok' => true,
                    'status' => 200,
                    'body' => [
                        'result' => 'ok',
                        'gateway_reference' => 'PG-123',
                    ],
                ]);
        });

        $response = $this->postJson('/api/payments/record', [
            'payment_method' => 'card',
            'amount' => 25.50,
            'currency' => 'BWP',
            'msisdn' => '73717137',
            'service_type' => 'esim_purchase',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'dpo')
            ->assertJsonPath('gateway_ok', true);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_method' => 'card',
            'currency' => 'BWP',
            'status' => 'processing',
        ]);
    }

    public function test_subscriber_lookup_uses_local_fallback_when_c1_is_not_ready(): void
    {
        Subscriber::query()->create([
            'msisdn' => '73717137',
            'is_whitelisted' => true,
        ]);

        config([
            'services.c1.billing_ip' => null,
            'services.c1.billing_user' => null,
        ]);

        $response = $this->postJson('/api/subscriber-lookup', [
            'msisdn' => '26773717137',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('exists', true)
            ->assertJsonPath('source', 'local_fallback');
    }

    public function test_otp_send_and_verify_mock_flow(): void
    {
        putenv('OTP_PROVIDER=mock');
        $_ENV['OTP_PROVIDER'] = 'mock';
        $_SERVER['OTP_PROVIDER'] = 'mock';

        $send = $this->postJson('/api/otp/send', [
            'msisdn' => '26773717137',
        ]);

        $send->assertCreated()
            ->assertJsonPath('success', true);

        $challengeId = (int) $send->json('challenge_id');
        $code = (string) $send->json('debug_code');

        $verify = $this->postJson('/api/otp/verify', [
            'msisdn' => '73717137',
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);

        $verify->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OTP verified');

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $challengeId,
            'status' => 'verified',
        ]);
    }

    public function test_kyc_number_selects_short_track_when_c1_profile_is_valid(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldReceive('c1SubscriberRetrieve')
                ->once()
                ->andReturn([
                    'ok' => true,
                    'exists' => true,
                    'service_internal_id' => '8797264',
                ]);
        });

        $start = $this->postJson('/api/kyc-compliance/start');
        $requestId = (string) $start->json('request_id');

        $this->postJson("/api/kyc-compliance/{$requestId}/terms", ['accepted' => true])
            ->assertOk();

        $number = $this->postJson("/api/kyc-compliance/{$requestId}/number", [
            'msisdn' => '26773717137',
        ]);

        $number->assertOk()
            ->assertJsonPath('track', 'short_track')
            ->assertJsonPath('valid_profile', true)
            ->assertJsonPath('current_step', 'otp_verify');
    }

    public function test_kyc_number_selects_full_kyc_when_profile_is_missing(): void
    {
        $this->mock(BtcGatewayService::class, function ($mock) {
            $mock->shouldReceive('c1SubscriberRetrieve')
                ->once()
                ->andReturn([
                    'ok' => false,
                    'error' => 'C1 unavailable',
                ]);
        });

        $start = $this->postJson('/api/kyc-compliance/start');
        $requestId = (string) $start->json('request_id');

        $this->postJson("/api/kyc-compliance/{$requestId}/terms", ['accepted' => true])
            ->assertOk();

        $number = $this->postJson("/api/kyc-compliance/{$requestId}/number", [
            'msisdn' => '26773717137',
        ]);

        $number->assertOk()
            ->assertJsonPath('track', 'full_kyc')
            ->assertJsonPath('valid_profile', false)
            ->assertJsonPath('current_step', 'registration');
    }
}
