<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\BtcGatewayService;

class C1Controller extends Controller
{
    public function getToken(): JsonResponse
    {
        $securityHost = trim((string) config('services.c1.security_ip'));
        $wsdl = 'https://'.$securityHost.'/SAMLSignOnWS?wsdl';

        $client = $this->makeSoapClient($wsdl, true);
        if (!$client) {
            $fallback = $this->proxyLoginViaCurl($securityHost);
            if (($fallback['ok'] ?? false) === true) {
                return response()->json([
                    'success' => true,
                    'token' => $fallback['token'] ?? null,
                    'source' => 'curl_fallback',
                    'raw' => $fallback['raw'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'SOAP client could not be initialized. Check PHP SOAP extension and C1 security host.',
                'fallback_error' => $fallback['error'] ?? null,
            ], 500);
        }

        $params = [
            'String_1' => (string) config('services.c1.security_user'),
            'String_2' => (string) config('services.c1.security_password'),
            'String_3' => (string) config('services.c1.realm', 'sapi'),
        ];

        try {
            $response = $client->__soapCall('proxyLogin', [$params]);
            $token = $this->extractTokenFromSoapResponse($response);

            return response()->json([
                'success' => true,
                'token' => $token,
                'raw' => $response,
            ]);
        } catch (\Throwable $e) {
            $fallback = $this->proxyLoginViaCurl($securityHost);
            if (($fallback['ok'] ?? false) === true) {
                return response()->json([
                    'success' => true,
                    'token' => $fallback['token'] ?? null,
                    'source' => 'curl_fallback',
                    'soap_error' => $e->getMessage(),
                    'raw' => $fallback['raw'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'fallback_error' => $fallback['error'] ?? null,
            ], 500);
        }
    }

    public function subscriberRetrieve(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            try {
                $tokenResponse = $this->fetchToken();
                $token = (string) ($tokenResponse->return ?? '');
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token generation failed: '.$e->getMessage(),
                ], 500);
            }
        }

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'No security token available.',
            ], 422);
        }

        $msisdn = (string) $request->query('msisdn', (string) env('msisdn', '26773717137'));
        $result = $this->subscriberRetrieveViaCurl($token, $msisdn);
        if (!($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'SubscriberRetrieve failed',
                'status' => $result['status'] ?? 0,
                'raw' => $result['raw'] ?? '',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'msisdn' => $msisdn,
            'status' => $result['status'] ?? 200,
            'summary' => $this->summarizeSubscriberRetrieveXml((string) ($result['raw'] ?? '')),
            'raw' => $request->boolean('debug') ? ($result['raw'] ?? '') : null,
        ]);
    }

    public function c1SubscriberUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $payload = $this->buildC1UpdatePayload($request);
        $result = $btc->c1SubscriberUpdateDirect($payload);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => 'SubscriberUpdate',
            'payload' => $payload,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    public function c1AccountUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $payload = $this->buildC1UpdatePayload($request);
        $result = $btc->c1AccountUpdateDirect($payload);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => 'AccountUpdate',
            'payload' => $payload,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    public function c1AddressUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $payload = $this->buildC1UpdatePayload($request);
        $result = $btc->c1AddressUpdateDirect($payload);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => 'AddressUpdate',
            'payload' => $payload,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    public function c1PersonaUpdate(Request $request, BtcGatewayService $btc): JsonResponse
    {
        $payload = $this->buildC1UpdatePayload($request);
        $result = $btc->c1PersonaUpdateDirect($payload);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'api' => 'PersonaUpdate',
            'payload' => $payload,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }

    private function fetchToken(): object
    {
        $securityHost = trim((string) config('services.c1.security_ip'));
        $wsdl = 'https://'.$securityHost.'/SAMLSignOnWS?wsdl';
        $client = $this->makeSoapClient($wsdl, true);
        if (!$client) {
            $fallback = $this->proxyLoginViaCurl($securityHost);
            if (($fallback['ok'] ?? false) === true && !empty($fallback['token'])) {
                return (object) ['return' => (string) $fallback['token']];
            }

            throw new \RuntimeException('SOAP client initialization failed for token endpoint. '.($fallback['error'] ?? ''));
        }

        try {
            $response = $client->__soapCall('proxyLogin', [[
                'String_1' => (string) config('services.c1.security_user'),
                'String_2' => (string) config('services.c1.security_password'),
                'String_3' => (string) config('services.c1.realm', 'sapi'),
            ]]);
        } catch (\Throwable $e) {
            $fallback = $this->proxyLoginViaCurl($securityHost);
            if (($fallback['ok'] ?? false) === true && !empty($fallback['token'])) {
                return (object) ['return' => (string) $fallback['token']];
            }

            throw new \RuntimeException('Token generation failed: '.$e->getMessage().' '.($fallback['error'] ?? ''));
        }

        $token = $this->extractTokenFromSoapResponse($response);
        return (object) ['return' => $token ?? ''];
    }

    private function makeSoapClient(string $wsdl, bool $withSslContext): ?\SoapClient
    {
        if (!class_exists(\SoapClient::class)) {
            return null;
        }

        $options = [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => defined('WSDL_CACHE_NONE') ? constant('WSDL_CACHE_NONE') : 0,
            'connection_timeout' => (int) env('BTC_HTTP_CONNECT_TIMEOUT', 5),
        ];

        if ($withSslContext) {
            $options['stream_context'] = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
        }

        try {
            return new \SoapClient($wsdl, $options);
        } catch (\Throwable) {
            return null;
        }
    }

    private function proxyLoginViaCurl(string $securityHost): array
    {
        if ($securityHost === '') {
            return ['ok' => false, 'error' => 'C1 security host is empty'];
        }

        $user = (string) config('services.c1.security_user');
        $password = (string) config('services.c1.security_password');
        $realm = (string) config('services.c1.realm', 'sapi');

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:auth="https://org.comverse.rtbd.sec/webservice/auth">
  <soapenv:Header/>
  <soapenv:Body>
    <auth:proxyLogin>
      <String_1>{$user}</String_1>
      <String_2>{$password}</String_2>
      <String_3>{$realm}</String_3>
    </auth:proxyLogin>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        $ch = curl_init('https://'.$securityHost.'/SAMLSignOnWS');
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not initialize cURL'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) env('BTC_HTTP_CONNECT_TIMEOUT', 5));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) env('BTC_HTTP_TIMEOUT', 12));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if (defined('CURL_SSLVERSION_TLSv1_1')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, constant('CURL_SSLVERSION_TLSv1_1'));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'error' => $error !== '' ? $error : 'proxyLogin HTTP '.$status,
                'status' => $status,
                'raw' => $body !== false ? (string) $body : '',
            ];
        }

        $token = null;
        if (preg_match('/<([a-zA-Z0-9_]+:)?return>([^<]+)<\/([a-zA-Z0-9_]+:)?return>/', (string) $body, $m) === 1) {
            $token = trim((string) $m[2]);
        }
        if (($token === null || $token === '') && preg_match('/<([a-zA-Z0-9_]+:)?result>(.*?)<\/([a-zA-Z0-9_]+:)?result>/s', (string) $body, $m) === 1) {
            $decoded = html_entity_decode((string) $m[2], ENT_QUOTES | ENT_XML1);
            if (preg_match('/<Token>([^<]+)<\/Token>/', $decoded, $tm) === 1) {
                $token = trim((string) $tm[1]);
            }
        }

        return [
            'ok' => $token !== null && $token !== '',
            'token' => $token,
            'status' => $status,
            'raw' => (string) $body,
            'error' => $token ? null : 'Token not found in response',
        ];
    }

    private function subscriberRetrieveViaCurl(string $token, string $msisdn): array
    {
        $billingHost = trim((string) config('services.c1.billing_ip'));
        $realm = (string) config('services.c1.realm', 'sapi');
        $user = (string) config('services.c1.billing_user');

        if ($billingHost === '' || $user === '') {
            return ['ok' => false, 'error' => 'C1 billing config is incomplete', 'status' => 0, 'raw' => ''];
        }

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.comverse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:SubscriberRetrieve>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$token}</securityToken>
        <userIdName>{$user}</userIdName>
        <subscriberId>
          <subscriberId>
            <value>{$msisdn}</value>
          </subscriberId>
          <subscriberExternalIdType>
            <value>1</value>
          </subscriberExternalIdType>
        </subscriberId>
        <info>
          <attribs>1</attribs>
          <useBillingDB changed="true" set="true"><value>true</value></useBillingDB>
          <subscriberData changed="true" set="true"><value>true</value></subscriberData>
          <balances changed="true" set="true"><value>1</value></balances>
          <externalIds changed="true" set="true"><value>true</value></externalIds>
          <offers changed="true" set="true"><value>true</value></offers>
        </info>
      </com:input>
    </com:SubscriberRetrieve>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        $ch = curl_init('http://'.$billingHost.'/services/SubscriberService');
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not initialize cURL', 'status' => 0, 'raw' => ''];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) env('BTC_HTTP_CONNECT_TIMEOUT', 5));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) env('BTC_HTTP_TIMEOUT', 12));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'error' => $error !== '' ? $error : 'SubscriberRetrieve HTTP '.$status,
                'status' => $status,
                'raw' => $body !== false ? (string) $body : '',
            ];
        }

        return ['ok' => true, 'status' => $status, 'raw' => (string) $body];
    }

    private function extractTokenFromSoapResponse(object $response): ?string
    {
        $direct = trim((string) ($response->return ?? $response->token ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $result = (string) ($response->result ?? '');
        if ($result !== '') {
            $decoded = html_entity_decode($result, ENT_QUOTES | ENT_XML1);
            if (preg_match('/<Token>([^<]+)<\/Token>/', $decoded, $m) === 1) {
                $token = trim((string) $m[1]);
                return $token !== '' ? $token : null;
            }
        }

        return null;
    }

    private function summarizeSubscriberRetrieveXml(string $xml): array
    {
        return [
            'service_internal_id' => $this->extractXmlTag($xml, 'serviceInternalId'),
            'account_internal_id' => $this->extractXmlTag($xml, 'accountInternalId'),
            'subscriber_external_id' => $this->extractXmlTag($xml, 'serviceExternalId'),
            'status_id' => $this->extractXmlTag($xml, 'statusId'),
            'status_reason_id' => $this->extractXmlTag($xml, 'statusReasonId'),
            'rating_state' => $this->extractXmlTag($xml, 'ratingState'),
            'is_active' => $this->extractXmlTag($xml, 'isActive'),
            'first_name' => $this->extractXmlTag($xml, 'serviceFname'),
            'last_name' => $this->extractXmlTag($xml, 'serviceLname'),
            'offers_count' => $this->countXmlTags($xml, 'offerInstances'),
            'balances_count' => $this->countXmlTags($xml, 'balanceInstances'),
            'external_ids_count' => $this->countXmlTags($xml, 'externalIds'),
        ];
    }

    private function extractXmlTag(string $xml, string $tag): ?string
    {
        if ($xml === '') {
            return null;
        }

        $pattern = '/<'.$tag.'(?:\s[^>]*)?>\s*(?:<value[^>]*>)?([^<]*)/i';
        if (preg_match($pattern, $xml, $m) !== 1) {
            return null;
        }

        $value = trim((string) ($m[1] ?? ''));
        return $value === '' ? null : html_entity_decode($value, ENT_QUOTES | ENT_XML1);
    }

    private function countXmlTags(string $xml, string $tag): int
    {
        if ($xml === '') {
            return 0;
        }

        return preg_match_all('/<'.$tag.'(?:\s|>)/i', $xml);
    }

    private function buildC1UpdatePayload(Request $request): array
    {
        return [
            'service_internal_id' => (string) $request->input('service_internal_id', (string) env('service_internal_id', '')),
            'account_internal_id' => (string) $request->input('account_internal_id', (string) env('account_internal_id', '')),
            'persona_internal_id' => (string) $request->input('persona_internal_id', ''),
            'msisdn' => (string) $request->input('msisdn', (string) env('msisdn', '26773717137')),
            'first_name' => (string) $request->input('first_name', (string) env('first_name', '')),
            'last_name' => (string) $request->input('last_name', (string) env('last_name', '')),
            'address' => (string) $request->input('address', (string) env('address_line1', '')),
            'city' => (string) $request->input('city', (string) env('city', '')),
            'email' => (string) $request->input('email', (string) env('email', '')),
            'document_number' => (string) $request->input('document_number', (string) env('document_number', '')),
            'nationality' => (string) $request->input('nationality', (string) env('nationality', '')),
            'dob' => (string) $request->input('dob', (string) env('dob', '')),
            'gender' => (string) $request->input('gender', (string) env('gender', '')),
        ];
    }
}
