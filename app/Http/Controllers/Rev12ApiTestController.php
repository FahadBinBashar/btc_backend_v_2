<?php

namespace App\Http\Controllers;

use App\Services\BtcGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Rev12ApiTestController extends Controller
{
    public function securityToken(BtcGatewayService $btc): JsonResponse
    {
        $token = $btc->c1SecurityTokenDirect();

        return response()->json([
            'success' => $token !== '',
            'token' => $token !== '' ? $token : null,
            'message' => $token !== '' ? 'Token generated' : 'Token generation failed',
        ], $token !== '' ? 200 : 500);
    }

    public function subscriberRetrieve(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $msisdn = (string) $request->input('msisdn', env('msisdn', '26773717137'));
        $result = $btc->c1SubscriberRetrieve($msisdn);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => 'SubscriberRetrieve',
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    public function subscriberResume(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $serviceInternalId = (string) $request->input('service_internal_id', env('service_internal_id', ''));
        $comment = (string) $request->input('comment', 'KYC compliant');
        $result = $btc->c1SubscriberResume($serviceInternalId, $comment);

        return $this->result('SubscriberResume', $result);
    }

    public function subscriberSuspend(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $serviceInternalId = (string) $request->input('service_internal_id', env('service_internal_id', ''));
        $comment = (string) $request->input('comment', 'NON COMPLIANT FOR KYC');
        $result = $btc->c1SubscriberSuspend($serviceInternalId, $comment);

        return $this->result('SubscriberSuspend', $result);
    }

    public function subscriberUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->c1SubscriberUpdateDirect($this->c1Payload($request));
        return $this->result('SubscriberUpdate', $result);
    }

    public function accountUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->c1AccountUpdateDirect($this->c1Payload($request));
        return $this->result('AccountUpdate', $result);
    }

    public function addressUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->c1AddressUpdateDirect($this->c1Payload($request));
        return $this->result('AddressUpdate', $result);
    }

    public function personaUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->c1PersonaUpdateDirect($this->c1Payload($request));
        return $this->result('PersonaUpdate', $result);
    }

    public function updateRatingStatus(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $serviceInternalId = (string) $request->input('service_internal_id', env('service_internal_id', ''));
        $resume = (bool) $request->input('resume', true);
        $result = $btc->c1UpdateRatingStatusDirect($serviceInternalId, $resume);

        return $this->result('UpdateRatingStatus', $result);
    }

    public function bocraCheckMsisdn(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $msisdn = (string) $request->input('msisdn', env('msisdn', '26773717137'));
        $result = $btc->bocraCheckByMsisdn($msisdn);
        return $this->result('CheckRegistrationByMsisdn', $result);
    }

    public function bocraCheckDocument(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $document = (string) $request->input('document_number', env('document_number', ''));
        $result = $btc->bocraCheckByDocument($document);
        return $this->result('CheckRegistrationByDocument', $result);
    }

    public function bocraRegister(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->bocraRegisterSubscriber($this->bocraPayload($request));
        return $this->result('BocraRegister', $result);
    }

    public function bocraUpdateSubscriber(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->bocraUpdateSubscriberPatch($this->bocraPayload($request));
        return $this->result('BocraUpdateSubscriber', $result);
    }

    public function bocraUpdateAddressDocuments(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->bocraUpdateAddressDocumentsPatch($this->bocraPayload($request));
        return $this->result('BocraUpdateAddressDocuments', $result);
    }

    public function smegaCheck(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $msisdn = (string) $request->input('msisdn', env('msisdn', '26773717137'));
        $result = $btc->smegaCheck($msisdn);
        return $this->result('CheckSmega', $result);
    }

    public function smegaRegister(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $result = $btc->smegaRegister($this->smegaPayload($request));
        return $this->result('RegisterSmega', $result);
    }

    public function logTransaction(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $payload = array_merge([
            'journey_id' => (string) $request->input('journey_id', env('journey_id', 'jrn-001')),
            'event_type' => (string) $request->input('event_type', env('event_type', 'API_CALL')),
            'correlation_id' => (string) $request->input('correlation_id', env('correlation_id', '')),
            'actor' => (string) $request->input('actor', env('actor', 'SYSTEM')),
            'action' => (string) $request->input('action', env('action', 'SUBSCRIBER_UPDATE')),
            'outcome' => (string) $request->input('outcome', env('outcome', 'SUCCESS')),
            'msisdn' => (string) $request->input('msisdn', env('msisdn', '26773717137')),
        ], $request->all());

        $result = $btc->logTransaction($payload);
        return $this->result('LogTransaction', $result);
    }

    private function result(string $api, array $result): JsonResponse
    {
        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => $api,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    private function c1Payload(Request $request): array
    {
        return [
            'service_internal_id' => (string) $request->input('service_internal_id', env('service_internal_id', '')),
            'account_internal_id' => (string) $request->input('account_internal_id', env('account_internal_id', '')),
            'persona_internal_id' => (string) $request->input('persona_internal_id', ''),
            'msisdn' => (string) $request->input('msisdn', env('msisdn', '26773717137')),
            'first_name' => (string) $request->input('first_name', env('first_name', '')),
            'last_name' => (string) $request->input('last_name', env('last_name', '')),
            'address' => (string) $request->input('address', env('address_line1', '')),
            'city' => (string) $request->input('city', env('city', '')),
            'email' => (string) $request->input('email', env('email', '')),
            'document_number' => (string) $request->input('document_number', env('document_number', '')),
            'nationality' => (string) $request->input('nationality', env('nationality', '')),
            'dob' => (string) $request->input('dob', env('dob', '')),
            'gender' => (string) $request->input('gender', env('gender', '')),
        ];
    }

    private function bocraPayload(Request $request): array
    {
        return [
            'msisdn' => (string) $request->input('msisdn', env('msisdn', '26773717137')),
            'first_name' => (string) $request->input('first_name', env('first_name', '')),
            'last_name' => (string) $request->input('last_name', env('last_name', '')),
            'country' => (string) $request->input('country', 'BOTSWANA'),
            'dob_iso' => (string) $request->input('dob_iso', env('dob_iso', '')),
            'gender' => (string) $request->input('gender', env('gender', '')),
            'document_number' => (string) $request->input('document_number', env('document_number', '')),
            'document_type' => (string) $request->input('document_type', env('document_type', 'NATIONAL_ID')),
            'physical_address' => (string) $request->input('physical_address', env('address_line1', '')),
            'postal_address' => (string) $request->input('postal_address', env('address_line1', '')),
            'city' => (string) $request->input('city', env('city', '')),
        ];
    }

    private function smegaPayload(Request $request): array
    {
        return [
            'msisdn' => (string) $request->input('msisdn', env('msisdn', '26773717137')),
            'first_name' => (string) $request->input('first_name', env('first_name', '')),
            'last_name' => (string) $request->input('last_name', env('last_name', '')),
            'document_number' => (string) $request->input('document_number', env('document_number', '')),
            'address' => (string) $request->input('address', env('address_line1', '')),
            'city' => (string) $request->input('city', env('city', '')),
            'source_of_income' => (string) $request->input('source_of_income', env('source_of_income', 'SALARY')),
        ];
    }
}

