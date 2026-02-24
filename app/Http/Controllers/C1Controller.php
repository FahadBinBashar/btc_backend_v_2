<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $wsdl = 'http://'.trim((string) config('services.c1.billing_ip')).'/services/SubscriberService?wsdl';
        $client = $this->makeSoapClient($wsdl, false);
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'SOAP client could not be initialized. Check PHP SOAP extension and C1 billing host.',
            ], 500);
        }

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

        $params = [
            'input' => [
                'realm' => (string) config('services.c1.realm', 'sapi'),
                'securityToken' => $token,
                'userIdName' => (string) config('services.c1.billing_user'),
                'subscriberId' => [
                    'subscriberId' => ['value' => $msisdn],
                    'subscriberExternalIdType' => ['value' => 1],
                ],
                'info' => [
                    'attribs' => 1,
                    'useBillingDB' => true,
                    'subscriberData' => true,
                    'balances' => 1,
                    'externalIds' => true,
                    'offers' => true,
                ],
            ],
        ];

        try {
            $response = $client->__soapCall('SubscriberRetrieve', [$params]);

            return response()->json([
                'success' => true,
                'msisdn' => $msisdn,
                'raw' => $response,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
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
}
