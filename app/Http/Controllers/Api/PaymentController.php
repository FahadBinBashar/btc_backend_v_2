<?php

namespace App\Http\Controllers\Api;

use App\Models\PaymentTransaction;
use App\Models\ServiceRequest;
use App\Services\BtcGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends BaseApiController
{
    public function __construct(private readonly BtcGatewayService $btcGateway)
    {
    }

    public function record(Request $request)
    {
        $payload = $request->validate([
            'service_request_id' => ['nullable', 'integer'],
            'msisdn' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['required', 'string', 'max:50'],
            'payment_type' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'voucher_code' => ['nullable', 'string', 'max:100'],
            'customer_care_user_id' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', 'string', 'max:100'],
            'plan_name' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $requestId = $payload['service_request_id'] ?? null;
        if ($requestId !== null) {
            $exists = ServiceRequest::query()->where('id', $requestId)->exists();
            if (!$exists) {
                return $this->fail('service_request_id is invalid.', 422);
            }
        }

        $provider = strtolower((string) env('PAYMENT_PROVIDER', 'mock'));
        $reference = 'BTC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        $gatewayResponse = null;
        $status = 'pending';

        if (in_array($provider, ['dpo', 'paygate'], true)) {
            $gatewayResponse = $this->btcGateway->dpoInitiatePayment([
                'reference' => $reference,
                'amount' => $payload['amount'],
                'currency' => $payload['currency'] ?? 'BWP',
                'msisdn' => $payload['msisdn'] ?? null,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $status = ($gatewayResponse['ok'] ?? false) ? 'processing' : 'failed';
        } else {
            $gatewayResponse = [
                'ok' => true,
                'provider' => 'mock',
                'message' => 'Payment recorded without external provider call.',
            ];
            $status = 'processing';
        }

        $transaction = PaymentTransaction::query()->create([
            'service_request_id' => $requestId,
            'msisdn' => $payload['msisdn'] ?? null,
            'payment_method' => $payload['payment_method'],
            'payment_type' => $payload['payment_type'] ?? null,
            'amount' => $payload['amount'],
            'currency' => $payload['currency'] ?? 'BWP',
            'status' => $status,
            'voucher_code' => $payload['voucher_code'] ?? null,
            'customer_care_user_id' => $payload['customer_care_user_id'] ?? null,
            'service_type' => $payload['service_type'] ?? null,
            'plan_name' => $payload['plan_name'] ?? null,
            'metadata' => array_filter([
                'reference' => $reference,
                'provider' => $provider,
                'gateway' => $gatewayResponse,
                'input_metadata' => $payload['metadata'] ?? null,
            ], static fn ($value) => $value !== null),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return $this->ok([
            'message' => 'Payment transaction recorded',
            'transaction_id' => (string) $transaction->id,
            'status' => $transaction->status,
            'reference' => $reference,
            'provider' => $provider,
            'gateway_ok' => (bool) ($gatewayResponse['ok'] ?? false),
            'gateway_response' => $gatewayResponse,
        ], 201);
    }
}
