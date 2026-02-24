<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\OtpChallenge;
use App\Models\ServiceRequest;
use App\Services\BtcGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SmegaJourneyController extends BaseApiController
{
    public function __construct(private readonly BtcGatewayService $btcGateway)
    {
    }

    public function start(): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()->create([
            'request_type' => 'smega_standalone',
            'status' => 'started',
            'current_step' => 'capture_msisdn',
            'otp_skipped' => false,
            'metadata' => [
                'started_at' => now()->toISOString(),
            ],
        ]);

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'status' => $serviceRequest->status,
            'current_step' => $serviceRequest->current_step,
        ], 201);
    }

    public function captureMsisdn(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('SMEGA journey not found.', 404);
        }

        $msisdn = $this->normalizeMsisdn($payload['msisdn']);
        if ($msisdn === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        $c1 = $this->btcGateway->c1SubscriberRetrieve($msisdn);
        $compliant = (bool) ($c1['ok'] ?? false) && (bool) ($c1['exists'] ?? false);

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['c1_lookup'] = [
            'ok' => (bool) ($c1['ok'] ?? false),
            'exists' => (bool) ($c1['exists'] ?? false),
            'service_internal_id' => $c1['service_internal_id'] ?? null,
            'status' => $c1['status'] ?? null,
            'error' => $c1['error'] ?? null,
        ];
        $metadata['compliant'] = $compliant;

        $serviceRequest->msisdn = $msisdn;
        $serviceRequest->status = $compliant ? 'compliant' : 'non_compliant';
        $serviceRequest->current_step = $compliant ? 'otp_verification' : 'inline_kyc';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'compliant' => $compliant,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function completeInlineKyc(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'metamap_verified' => ['required', 'boolean'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('SMEGA journey not found.', 404);
        }

        if ($payload['metamap_verified'] !== true) {
            $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
            $c1Lookup = is_array($metadata['c1_lookup'] ?? null) ? $metadata['c1_lookup'] : [];
            $serviceInternalId = (string) ($c1Lookup['service_internal_id'] ?? '');
            if ($serviceInternalId !== '') {
                $this->btcGateway->c1SubscriberSuspend($serviceInternalId, 'Inline KYC failed');
            }

            $serviceRequest->status = 'failed_metamap';
            $serviceRequest->save();
            return $this->fail('MetaMap failed. Journey blocked.', 422);
        }

        $bocra = $this->btcGateway->bocraCheckByMsisdn('267'.(string) $serviceRequest->msisdn);
        if (!(bool) ($bocra['compliant'] ?? false)) {
            $docCheck = $this->btcGateway->bocraCheckByDocument((string) ($payload['document_number'] ?? ''));
            $register = $this->btcGateway->bocraRegisterSubscriber([
                'msisdn' => '267'.(string) $serviceRequest->msisdn,
                'first_name' => (string) ($payload['first_name'] ?? ''),
                'last_name' => (string) ($payload['last_name'] ?? ''),
                'gender' => (string) ($payload['gender'] ?? 'MALE'),
                'document_number' => (string) ($payload['document_number'] ?? ''),
                'document_type' => (string) ($payload['document_type'] ?? 'NATIONAL_ID'),
                'physical_address' => (string) ($payload['address'] ?? ''),
                'postal_address' => (string) ($payload['address'] ?? ''),
                'city' => (string) ($payload['city'] ?? ''),
            ]);
            $bocra['doc_check'] = $docCheck;
            $bocra['register'] = $register;
            $bocra['compliant'] = (bool) ($register['ok'] ?? false);
        }

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $c1Lookup = is_array($metadata['c1_lookup'] ?? null) ? $metadata['c1_lookup'] : [];
        $c1Updates = $this->btcGateway->c1ApplyConditionalUpdates([
            'service_internal_id' => $c1Lookup['service_internal_id'] ?? null,
            'msisdn' => '267'.(string) $serviceRequest->msisdn,
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'address' => (string) ($payload['address'] ?? ''),
            'city' => (string) ($payload['city'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'nationality' => (string) ($payload['nationality'] ?? ''),
            'document_number' => (string) ($payload['document_number'] ?? ''),
            'dob' => (string) ($payload['dob'] ?? ''),
            'gender' => (string) ($payload['gender'] ?? ''),
            'resume' => true,
        ]);

        $lifecycle = null;
        $serviceInternalId = (string) ($c1Lookup['service_internal_id'] ?? '');
        if ($serviceInternalId !== '') {
            $lifecycle = $this->btcGateway->c1SubscriberResume($serviceInternalId, 'SMEGA inline KYC complete');
        }

        $metadata['inline_kyc'] = [
            'metamap_verified' => true,
            'bocra' => $bocra,
            'c1_updates' => $c1Updates,
            'c1_lifecycle' => $lifecycle,
        ];

        $serviceRequest->status = 'compliant';
        $serviceRequest->current_step = 'otp_verification';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'status' => $serviceRequest->status,
            'current_step' => $serviceRequest->current_step,
        ]);
    }

    public function sendOtp(string $requestId): JsonResponse
    {
        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest || !$serviceRequest->msisdn) {
            return $this->fail('SMEGA journey not found or missing MSISDN.', 404);
        }

        $code = (string) env('MOCK_OTP_CODE', '123456');
        $challenge = OtpChallenge::query()->create([
            'msisdn' => (string) $serviceRequest->msisdn,
            'code_hash' => Hash::make($code),
            'channel' => 'sms',
            'status' => 'sent',
            'expires_at' => now()->addMinutes(5),
        ]);

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'challenge_id' => (string) $challenge->id,
            'debug_code' => app()->environment(['local', 'testing']) ? $code : null,
        ], 201);
    }

    public function verifyOtp(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest || !$serviceRequest->msisdn) {
            return $this->fail('SMEGA journey not found or missing MSISDN.', 404);
        }

        $challenge = OtpChallenge::query()
            ->where('id', (int) $payload['challenge_id'])
            ->where('msisdn', (string) $serviceRequest->msisdn)
            ->where('status', 'sent')
            ->first();

        if (!$challenge || !Hash::check((string) $payload['code'], (string) $challenge->code_hash)) {
            return $this->fail('Invalid OTP code.', 422);
        }

        $challenge->status = 'verified';
        $challenge->verified_at = now();
        $challenge->save();

        $serviceRequest->status = 'otp_verified';
        $serviceRequest->current_step = 'smega_gate';
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'status' => $serviceRequest->status,
            'current_step' => $serviceRequest->current_step,
        ]);
    }

    public function complete(Request $request, string $requestId): JsonResponse
    {
        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('SMEGA journey not found.', 404);
        }

        $check = $this->btcGateway->smegaCheck((string) $serviceRequest->msisdn);
        $exists = $this->smegaProfileExists($check['body'] ?? null);
        $register = null;

        if (!$exists) {
            $register = $this->btcGateway->smegaRegister([
                'msisdn' => '267'.(string) $serviceRequest->msisdn,
            ]);
        }

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['smega'] = [
            'check' => $check,
            'exists' => $exists,
            'register' => $register,
        ];

        $serviceRequest->status = 'completed';
        $serviceRequest->current_step = 'done';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        AuditLog::query()->create([
            'service_request_id' => $serviceRequest->id,
            'action' => 'LOG_TRANSACTION',
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => [
                'journey_type' => 'smega_standalone',
                'msisdn' => '****'.substr((string) $serviceRequest->msisdn, -4),
                'smega' => $metadata['smega'],
            ],
        ]);

        $externalLog = $this->btcGateway->logTransaction([
            'journey_id' => 'jrn-'.$serviceRequest->id,
            'event_type' => 'API_CALL',
            'correlation_id' => (string) ($request->header('x-correlation-id') ?? ('jrn-'.$serviceRequest->id)),
            'actor' => 'SYSTEM',
            'action' => 'SMEGA_COMPLETE',
            'outcome' => 'SUCCESS',
            'msisdn' => '****'.substr((string) $serviceRequest->msisdn, -4),
            'api_called' => 'SMEGA_JOURNEY_COMPLETE',
            'request_payload' => ['request_id' => $serviceRequest->id],
            'response_payload' => $metadata['smega'],
            'status_code' => '200',
        ]);

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'status' => 'SMEGA Complete',
            'profile_exists' => $exists,
            'registered' => $register ? (bool) ($register['ok'] ?? false) : false,
            'external_log_ok' => (bool) ($externalLog['ok'] ?? false),
        ]);
    }

    private function findJourney(string $requestId): ?ServiceRequest
    {
        return ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'smega_standalone')
            ->first();
    }

    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '267') && strlen($digits) >= 11) {
            $digits = substr($digits, -8);
        }

        return strlen($digits) === 8 ? $digits : null;
    }

    private function smegaProfileExists(mixed $responseBody): bool
    {
        $text = '';
        if (is_array($responseBody)) {
            $encoded = json_encode($responseBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = is_string($encoded) ? strtolower($encoded) : '';
        } else {
            $text = strtolower((string) $responseBody);
        }

        if ($text === '') {
            return false;
        }

        if (str_contains($text, 'not exists') || str_contains($text, 'does not exist') || str_contains($text, 'absent')) {
            return false;
        }

        return str_contains($text, 'exists');
    }
}
