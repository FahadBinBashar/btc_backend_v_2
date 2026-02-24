<?php

namespace App\Http\Controllers\Api;

use App\Services\BtcGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationHealthController extends BaseApiController
{
    public function __construct(private readonly BtcGatewayService $btcGateway)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rawMsisdn = (string) ($request->query('msisdn') ?? env('msisdn', '26773717137'));
        $fullMsisdn = preg_replace('/\D+/', '', $rawMsisdn) ?? '';
        $c1Msisdn = strlen($fullMsisdn) > 8 ? substr($fullMsisdn, -8) : $fullMsisdn;
        $logProbe = filter_var($request->query('log_probe', false), FILTER_VALIDATE_BOOLEAN);

        $c1 = $this->btcGateway->c1SubscriberRetrieve($c1Msisdn);
        $bocra = $this->btcGateway->bocraCheckByMsisdn($fullMsisdn);
        $smega = $this->btcGateway->smegaCheck($fullMsisdn);

        $logging = [
            'probe_enabled' => $logProbe,
            'ok' => null,
            'status' => null,
            'error' => null,
        ];

        if ($logProbe) {
            $logResult = $this->btcGateway->logTransaction([
                'journey_id' => 'health-check',
                'event_type' => 'HEALTH_CHECK',
                'correlation_id' => 'health-'.now()->timestamp,
                'actor' => 'SYSTEM',
                'action' => 'INTEGRATION_HEALTH',
                'outcome' => 'SUCCESS',
                'msisdn' => '****'.substr($fullMsisdn, -4),
                'api_called' => 'health/integrations',
                'request_payload' => [],
                'response_payload' => [],
                'status_code' => '200',
            ]);

            $logging = [
                'probe_enabled' => true,
                'ok' => (bool) ($logResult['ok'] ?? false),
                'status' => $logResult['status'] ?? null,
                'error' => $logResult['error'] ?? null,
            ];
        }

        $checks = [
            'c1' => [
                'ok' => (bool) ($c1['ok'] ?? false),
                'status' => $c1['status'] ?? null,
                'error' => $c1['error'] ?? null,
            ],
            'bocra' => [
                'ok' => (bool) ($bocra['ok'] ?? false),
                'status' => $bocra['status'] ?? null,
                'error' => $bocra['error'] ?? null,
            ],
            'smega' => [
                'ok' => (bool) ($smega['ok'] ?? false),
                'status' => $smega['status'] ?? null,
                'error' => $smega['error'] ?? null,
            ],
            'logging' => $logging,
        ];

        $healthy = $checks['c1']['ok'] && $checks['bocra']['ok'] && $checks['smega']['ok'];

        return $this->ok([
            'healthy' => $healthy,
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
        ]);
    }
}

