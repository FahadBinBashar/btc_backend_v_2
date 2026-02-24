<?php

namespace App\Http\Controllers\Api;

use App\Models\OtpChallenge;
use App\Services\BtcGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OtpController extends BaseApiController
{
    public function __construct(private readonly BtcGatewayService $btcGateway)
    {
    }

    public function send(Request $request)
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
            'channel' => ['nullable', 'in:sms,whatsapp,email'],
        ]);

        $msisdn = $this->normalizeMsisdn($payload['msisdn']);
        if ($msisdn === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        $provider = strtolower((string) env('OTP_PROVIDER', 'mock'));
        $channel = (string) ($payload['channel'] ?? 'sms');
        $plainCode = $provider === 'mock'
            ? (string) env('MOCK_OTP_CODE', '123456')
            : (string) random_int(100000, 999999);

        $challenge = OtpChallenge::query()->create([
            'msisdn' => $msisdn,
            'code_hash' => Hash::make($plainCode),
            'channel' => $channel,
            'status' => 'sent',
            'expires_at' => now()->addMinutes(5),
        ]);

        $delivery = [
            'ok' => true,
            'provider' => $provider,
        ];

        if ($provider === 'smega') {
            $delivery = $this->btcGateway->smegaCheck($msisdn);
            if (($delivery['ok'] ?? false) !== true) {
                $challenge->status = 'failed';
                $challenge->save();
            }
        }

        $response = [
            'message' => 'OTP sent',
            'challenge_id' => (string) $challenge->id,
            'expires_at' => optional($challenge->expires_at)->toISOString(),
            'delivery' => $delivery,
        ];

        if (app()->environment(['local', 'testing'])) {
            $response['debug_code'] = $plainCode;
        }

        return $this->ok($response, 201);
    }

    public function verify(Request $request)
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'min:4', 'max:10'],
            'challenge_id' => ['nullable', 'integer'],
        ]);

        $msisdn = $this->normalizeMsisdn($payload['msisdn']);
        if ($msisdn === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        $challengeQuery = OtpChallenge::query()
            ->where('msisdn', $msisdn)
            ->where('status', 'sent');

        if (!empty($payload['challenge_id'])) {
            $challengeQuery->where('id', (int) $payload['challenge_id']);
        }

        $challenge = $challengeQuery->latest('id')->first();
        if (!$challenge) {
            return $this->fail('No active OTP challenge found.', 404);
        }

        if ($challenge->expires_at && now()->greaterThan($challenge->expires_at)) {
            $challenge->status = 'expired';
            $challenge->save();
            return $this->fail('OTP expired.', 422);
        }

        if (!Hash::check((string) $payload['code'], $challenge->code_hash)) {
            $challenge->attempts = (int) $challenge->attempts + 1;
            if ((int) $challenge->attempts >= 3) {
                $challenge->status = 'failed';
            }
            $challenge->save();

            return $this->fail('Invalid OTP code.', 422);
        }

        $challenge->status = 'verified';
        $challenge->verified_at = now();
        $challenge->save();

        return $this->ok([
            'message' => 'OTP verified',
            'challenge_id' => (string) $challenge->id,
            'verified_at' => optional($challenge->verified_at)->toISOString(),
        ]);
    }

    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '267') && strlen($digits) > 8) {
            $digits = substr($digits, -8);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }
}
