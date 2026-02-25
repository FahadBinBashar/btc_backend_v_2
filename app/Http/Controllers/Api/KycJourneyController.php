<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\OtpChallenge;
use App\Models\RegistrationProfile;
use App\Models\ServiceRequest;
use App\Services\BtcGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class KycJourneyController extends BaseApiController
{
    public function __construct(private readonly BtcGatewayService $btcGateway)
    {
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'smega_selected' => ['nullable', 'boolean'],
            'source_of_income' => ['nullable', 'string', 'max:100'],
        ]);

        $serviceRequest = ServiceRequest::query()->create([
            'request_type' => 'kyc_journey',
            'status' => 'started',
            'current_step' => 'capture_msisdn',
            'otp_skipped' => false,
            'metadata' => [
                'smega_selected' => (bool) ($payload['smega_selected'] ?? false),
                'source_of_income' => $payload['source_of_income'] ?? null,
                'journey_type' => ((bool) ($payload['smega_selected'] ?? false)) ? 'kyc_with_smega' : 'kyc_standalone',
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
            'terms_accepted' => ['required', 'boolean'],
            'msisdn' => ['required', 'string', 'max:20'],
            'smega_selected' => ['nullable', 'boolean'],
            'source_of_income' => ['nullable', 'string', 'max:100'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC journey not found.', 404);
        }

        $msisdn = $this->normalizeMsisdn($payload['msisdn']);
        if ($msisdn === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        if ((bool) $payload['terms_accepted'] !== true) {
            return $this->fail('Terms must be accepted before continuing.', 422);
        }

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['terms_accepted'] = true;
        if (array_key_exists('smega_selected', $payload)) {
            $metadata['smega_selected'] = (bool) $payload['smega_selected'];
            $metadata['journey_type'] = $metadata['smega_selected'] ? 'kyc_with_smega' : 'kyc_standalone';
        }
        if (!empty($payload['source_of_income'])) {
            $metadata['source_of_income'] = $payload['source_of_income'];
        }

        $serviceRequest->msisdn = $msisdn;
        $serviceRequest->status = 'awaiting_otp';
        $serviceRequest->current_step = 'otp_verification';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'terms_accepted' => true,
            'smega_selected' => (bool) ($metadata['smega_selected'] ?? false),
            'current_step' => $serviceRequest->current_step,
        ]);
    }

    public function sendOtp(Request $request, string $requestId): JsonResponse
    {
        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest || !$serviceRequest->msisdn) {
            return $this->fail('KYC journey not found or missing MSISDN.', 404);
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
            'expires_at' => optional($challenge->expires_at)->toISOString(),
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
            return $this->fail('KYC journey not found or missing MSISDN.', 404);
        }

        $challenge = OtpChallenge::query()
            ->where('id', (int) $payload['challenge_id'])
            ->where('msisdn', (string) $serviceRequest->msisdn)
            ->where('status', 'sent')
            ->first();

        if (!$challenge) {
            return $this->fail('No active OTP challenge found.', 404);
        }

        if ($challenge->expires_at && now()->greaterThan($challenge->expires_at)) {
            $challenge->status = 'expired';
            $challenge->save();
            return $this->fail('OTP expired.', 422);
        }

        if (!Hash::check((string) $payload['code'], (string) $challenge->code_hash)) {
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

        $c1 = $this->btcGateway->c1SubscriberRetrieve((string) $serviceRequest->msisdn);
        $profileValid = (bool) ($c1['ok'] ?? false) && (bool) ($c1['exists'] ?? false);
        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['track'] = $profileValid ? 'short_track' : 'full_kyc';
        $metadata['profile_valid'] = $profileValid;
        $metadata['c1_lookup'] = [
            'ok' => (bool) ($c1['ok'] ?? false),
            'exists' => (bool) ($c1['exists'] ?? false),
            'service_internal_id' => $c1['service_internal_id'] ?? null,
            'status' => $c1['status'] ?? null,
            'error' => $c1['error'] ?? null,
        ];

        $serviceRequest->status = 'otp_verified';
        $serviceRequest->current_step = 'profile_capture';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'track' => (string) ($metadata['track'] ?? 'full_kyc'),
            'profile_valid' => $profileValid,
            'c1_lookup_ok' => (bool) ($c1['ok'] ?? false),
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function saveProfile(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'plot_number' => ['nullable', 'string', 'max:50'],
            'ward' => ['nullable', 'string', 'max:50'],
            'village' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:200'],
            'postal_address' => ['nullable', 'string', 'max:200'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'account_internal_id' => ['nullable', 'string', 'max:100'],
            'persona_internal_id' => ['nullable', 'string', 'max:100'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC journey not found.', 404);
        }

        RegistrationProfile::query()->updateOrCreate(
            ['service_request_id' => $serviceRequest->id],
            [
                'plot_number' => $payload['plot_number'] ?? null,
                'ward' => $payload['ward'] ?? null,
                'village' => $payload['village'] ?? null,
                'city' => $payload['city'] ?? null,
                'postal_address' => $payload['postal_address'] ?? null,
            ]
        );

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['full_profile'] = [
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'id_type' => $payload['id_type'] ?? null,
            'id_number' => $payload['id_number'] ?? null,
            'nationality' => $payload['nationality'] ?? null,
            'gender' => $payload['gender'] ?? null,
            'dob' => $payload['dob'] ?? null,
            'email' => $payload['email'] ?? null,
            'city' => $payload['city'] ?? null,
            'address' => $payload['address'] ?? ($payload['postal_address'] ?? null),
            'postal_address' => $payload['postal_address'] ?? null,
            'account_internal_id' => $payload['account_internal_id'] ?? null,
            'persona_internal_id' => $payload['persona_internal_id'] ?? null,
        ];

        $serviceRequest->status = 'profile_captured';
        $serviceRequest->current_step = 'metamap_verification';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'track' => $this->resolveTrack($serviceRequest),
            'current_step' => $serviceRequest->current_step,
        ]);
    }

    public function metamapGate(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'verified' => ['required', 'boolean'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'verification_id' => ['nullable', 'string', 'max:255'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC journey not found.', 404);
        }

        $track = $this->resolveTrack($serviceRequest);
        if ($track === 'short_track') {
            $serviceRequest->current_step = 'bocra_gate';
            $serviceRequest->save();
            return $this->ok([
                'request_id' => (string) $serviceRequest->id,
                'message' => 'MetaMap skipped for short track',
                'current_step' => 'bocra_gate',
            ]);
        }

        if ($payload['verified'] !== true) {
            $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
            $c1Lookup = is_array($metadata['c1_lookup'] ?? null) ? $metadata['c1_lookup'] : [];
            $serviceInternalId = (string) ($c1Lookup['service_internal_id'] ?? '');
            if ($serviceInternalId !== '') {
                $this->btcGateway->c1SubscriberSuspend($serviceInternalId, 'MetaMap failed');
            }

            $serviceRequest->status = 'failed_metamap';
            $serviceRequest->save();
            return $this->fail('MetaMap verification failed. Journey blocked.', 422);
        }

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $metadata['metamap'] = [
            'verified' => true,
            'session_id' => $payload['session_id'] ?? null,
            'verification_id' => $payload['verification_id'] ?? null,
        ];

        $serviceRequest->status = 'metamap_verified';
        $serviceRequest->current_step = 'bocra_gate';
        $serviceRequest->metadata = $metadata;
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function complete(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'apply_rating_status' => ['nullable', 'boolean'],
        ]);

        $serviceRequest = $this->findJourney($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC journey not found.', 404);
        }

        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $track = $this->resolveTrack($serviceRequest);
        $profile = is_array($metadata['full_profile'] ?? null) ? $metadata['full_profile'] : [];
        $c1Lookup = is_array($metadata['c1_lookup'] ?? null) ? $metadata['c1_lookup'] : [];
        $fullMsisdn = '267'.(string) $serviceRequest->msisdn;
        $documentNumber = (string) ($profile['id_number'] ?? '');

        $bocraMsisdn = $this->btcGateway->bocraCheckByMsisdn($fullMsisdn);
        $bocraDocument = $documentNumber !== ''
            ? $this->btcGateway->bocraCheckByDocument($documentNumber)
            : ['ok' => false, 'exists' => false, 'error' => 'Document number is required for BOCRA document check'];

        $initialCompliant = (bool) ($bocraMsisdn['compliant'] ?? false) || (bool) ($bocraDocument['exists'] ?? false);
        $bocra = [
            'msisdn_check' => $bocraMsisdn,
            'document_check' => $bocraDocument,
            'initial_compliant' => $initialCompliant,
            'compliant' => $initialCompliant,
            'actions' => [],
        ];

        $smegaSelected = (bool) ($metadata['smega_selected'] ?? false);
        $smega = ['requested' => $smegaSelected, 'ok' => true, 'action' => 'skipped'];
        $c1Updates = ['ok' => true, 'skipped' => true, 'reason' => 'Skipped for already-compliant subscriber'];
        $ratingUpdate = ['ok' => true, 'skipped' => true];
        $lifecycle = ['ok' => true, 'skipped' => true];
        $serviceInternalId = (string) ($c1Lookup['service_internal_id'] ?? '');

        if (!$initialCompliant) {
            if (empty($profile) || $documentNumber === '') {
                return $this->fail('Non-compliant subscriber requires full KYC profile (including document number) before completion.', 422);
            }

            $bocraPayload = [
                'msisdn' => $fullMsisdn,
                'first_name' => (string) ($profile['first_name'] ?? ''),
                'last_name' => (string) ($profile['last_name'] ?? ''),
                'gender' => (string) ($profile['gender'] ?? 'MALE'),
                'document_number' => $documentNumber,
                'document_type' => (string) ($profile['id_type'] ?? 'NATIONAL_ID'),
                'physical_address' => (string) ($profile['address'] ?? ''),
                'postal_address' => (string) (($profile['postal_address'] ?? '') !== '' ? $profile['postal_address'] : ($profile['address'] ?? '')),
                'city' => (string) ($profile['city'] ?? ''),
            ];

            if ((bool) ($bocraDocument['exists'] ?? false)) {
                $bocra['actions']['update_subscriber'] = $this->btcGateway->bocraUpdateSubscriberPatch($bocraPayload);
                $bocra['actions']['update_address_documents'] = $this->btcGateway->bocraUpdateAddressDocumentsPatch($bocraPayload);
            } else {
                $bocra['actions']['register'] = $this->btcGateway->bocraRegisterSubscriber($bocraPayload);
            }

            $bocraRecheck = $this->btcGateway->bocraCheckByMsisdn($fullMsisdn);
            $bocra['post_update_msisdn_check'] = $bocraRecheck;
            $bocra['compliant'] = (bool) ($bocraRecheck['compliant'] ?? false);

            $c1Updates = $this->btcGateway->c1ApplyConditionalUpdates([
                'service_internal_id' => $c1Lookup['service_internal_id'] ?? null,
                'account_internal_id' => $profile['account_internal_id'] ?? null,
                'persona_internal_id' => $profile['persona_internal_id'] ?? null,
                'msisdn' => $fullMsisdn,
                'first_name' => $profile['first_name'] ?? '',
                'last_name' => $profile['last_name'] ?? '',
                'address' => $profile['address'] ?? ($profile['postal_address'] ?? ''),
                'city' => $profile['city'] ?? '',
                'email' => $profile['email'] ?? '',
                'nationality' => $profile['nationality'] ?? '',
                'document_number' => $profile['id_number'] ?? '',
                'dob' => $profile['dob'] ?? '',
                'gender' => $profile['gender'] ?? '',
                'resume' => (bool) ($bocra['compliant'] ?? false),
            ]);

            if ($serviceInternalId !== '') {
                $lifecycle = (bool) ($bocra['compliant'] ?? false)
                    ? $this->btcGateway->c1SubscriberResume($serviceInternalId, 'KYC complete')
                    : $this->btcGateway->c1SubscriberSuspend($serviceInternalId, 'KYC non-compliant after BOCRA checks');

                if ((bool) ($payload['apply_rating_status'] ?? false)) {
                    $ratingUpdate = $this->btcGateway->c1UpdateRatingStatusDirect(
                        $serviceInternalId,
                        (bool) ($bocra['compliant'] ?? false)
                    );
                }
            } else {
                $lifecycle = ['ok' => false, 'error' => 'Missing service_internal_id for C1 lifecycle call'];
            }
        }

        if ($smegaSelected) {
            $check = $this->btcGateway->smegaCheck((string) $serviceRequest->msisdn);
            $exists = $this->smegaProfileExists($check['body'] ?? null);
            if ($exists) {
                $smega = ['requested' => true, 'ok' => true, 'action' => 'already_exists', 'check' => $check];
            } else {
                $register = $this->btcGateway->smegaRegister([
                    'msisdn' => '267'.(string) $serviceRequest->msisdn,
                    'first_name' => $profile['first_name'] ?? '',
                    'last_name' => $profile['last_name'] ?? '',
                    'document_number' => $profile['id_number'] ?? '',
                    'address' => $profile['address'] ?? '',
                    'city' => $profile['city'] ?? '',
                    'source_of_income' => $metadata['source_of_income'] ?? '',
                ]);
                $smega = ['requested' => true, 'ok' => (bool) ($register['ok'] ?? false), 'action' => 'registered', 'check' => $check, 'register' => $register];
            }
        }

        $metadata['bocra'] = $bocra;
        $metadata['smega'] = $smega;
        $metadata['c1_updates'] = $c1Updates;
        $metadata['c1_lifecycle'] = $lifecycle;
        $metadata['c1_rating_status'] = $ratingUpdate;

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
                'correlation_id' => (string) ($request->header('x-correlation-id') ?? ('jrn-'.$serviceRequest->id)),
                'journey_type' => $metadata['journey_type'] ?? 'kyc_standalone',
                'track' => $track,
                'msisdn' => $this->maskMsisdn((string) $serviceRequest->msisdn),
                'bocra' => $bocra,
                'smega' => $smega,
                'c1_updates' => $c1Updates,
                'c1_lifecycle' => $lifecycle,
                'c1_rating_status' => $ratingUpdate,
            ],
        ]);

        $externalLog = $this->btcGateway->logTransaction([
            'journey_id' => 'jrn-'.$serviceRequest->id,
            'event_type' => 'API_CALL',
            'correlation_id' => (string) ($request->header('x-correlation-id') ?? ('jrn-'.$serviceRequest->id)),
            'actor' => 'SYSTEM',
            'action' => 'KYC_COMPLETE',
            'outcome' => 'SUCCESS',
            'msisdn' => $this->maskMsisdn((string) $serviceRequest->msisdn),
            'api_called' => 'KYC_JOURNEY_COMPLETE',
            'request_payload' => ['track' => $track],
            'response_payload' => ['bocra' => $bocra, 'smega' => $smega, 'c1_updates' => $c1Updates, 'c1_rating_status' => $ratingUpdate],
            'status_code' => '200',
        ]);

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'journey_type' => $metadata['journey_type'] ?? 'kyc_standalone',
            'track' => $track,
            'kyc_status' => (bool) ($bocra['compliant'] ?? false) ? 'complete' : 'non_compliant',
            'compliance' => [
                'initial_compliant' => (bool) ($bocra['initial_compliant'] ?? false),
                'final_compliant' => (bool) ($bocra['compliant'] ?? false),
            ],
            'smega_status' => $smega['action'] ?? 'skipped',
            'c1_updates_ok' => (bool) ($c1Updates['ok'] ?? false),
            'c1_lifecycle_ok' => (bool) ($lifecycle['ok'] ?? false),
            'c1_rating_status_ok' => (bool) ($ratingUpdate['ok'] ?? false),
            'external_log_ok' => (bool) ($externalLog['ok'] ?? false),
        ]);
    }

    private function findJourney(string $requestId): ?ServiceRequest
    {
        return ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'kyc_journey')
            ->first();
    }

    private function resolveTrack(ServiceRequest $serviceRequest): string
    {
        $metadata = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        $track = (string) ($metadata['track'] ?? 'full_kyc');
        return in_array($track, ['short_track', 'full_kyc'], true) ? $track : 'full_kyc';
    }

    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '267') && strlen($digits) >= 11) {
            $digits = substr($digits, -8);
        }

        return strlen($digits) === 8 ? $digits : null;
    }

    private function maskMsisdn(string $msisdn): string
    {
        $last4 = substr($msisdn, -4);
        return str_repeat('*', max(strlen($msisdn) - 4, 0)).$last4;
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
