<?php

namespace App\Services;

use Illuminate\Support\Str;

class BtcGatewayService
{
    public function c1SubscriberRetrieve(string $msisdn): array
    {
        $billingHost = trim((string) config('services.c1.billing_ip', ''));
        $securityToken = $this->c1SecurityToken();
        $user = trim((string) config('services.c1.billing_user', ''));
        $realm = trim((string) config('services.c1.realm', 'sapi'));

        if ($billingHost === '' || $user === '') {
            return ['ok' => false, 'error' => 'C1 billing config is incomplete'];
        }

        $xml = $this->buildC1SubscriberRetrieveXml($realm, $securityToken, $user, $msisdn);

        $response = $this->curlRequest(
            'POST',
            'http://'.$billingHost.'/services/SubscriberService',
            ['Content-Type: text/xml;charset=UTF-8'],
            $xml
        );

        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => 'C1 billing request failed',
                'status' => $response['status'],
                'body' => $response['body'],
            ];
        }

        $body = $response['body'];
        $serviceInternalId = $this->extractXmlValue($body, 'serviceInternalId');
        $resultCode = $this->extractXmlValue($body, 'resultCode')
            ?? $this->extractXmlValue($body, 'responseCode');
        $exists = $serviceInternalId !== null
            || (is_string($resultCode) && in_array($resultCode, ['0', '00', '200'], true));

        return [
            'ok' => true,
            'exists' => $exists,
            'service_internal_id' => $serviceInternalId,
            'result_code' => $resultCode,
            'raw' => $body,
        ];
    }

    public function dpoInitiatePayment(array $payload): array
    {
        $url = trim((string) config('services.dpo.paygate_url', ''));
        $id = trim((string) config('services.dpo.id', ''));
        $secret = trim((string) config('services.dpo.secret', ''));

        if ($url === '' || $id === '' || $secret === '') {
            return ['ok' => false, 'error' => 'DPO config is incomplete'];
        }

        $requestData = [
            'paygate_id' => $id,
            'reference' => (string) ($payload['reference'] ?? Str::uuid()->toString()),
            'amount' => (string) ($payload['amount'] ?? ''),
            'currency' => (string) ($payload['currency'] ?? 'BWP'),
            'msisdn' => (string) ($payload['msisdn'] ?? ''),
            'metadata' => $payload['metadata'] ?? [],
        ];

        $signature = hash('sha256', $requestData['reference'].$requestData['amount'].$secret);
        $requestData['signature'] = $signature;

        $response = $this->curlRequest(
            'POST',
            $url,
            ['Accept: application/json', 'Content-Type: application/json'],
            json_encode($requestData, JSON_UNESCAPED_SLASHES)
        );

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $this->decodeJsonOrRaw($response['body']),
            'request' => $requestData,
        ];
    }

    public function smegaCheck(string $msisdn): array
    {
        $host = trim((string) config('services.smega.check_ip', ''));
        $login = trim((string) config('services.smega.check_user', config('services.smega.registration_user', '')));
        $password = trim((string) config('services.smega.check_password', ''));

        if ($host === '' || $login === '' || $password === '') {
            return ['ok' => false, 'error' => 'SMEGA config is incomplete'];
        }

        $query = http_build_query([
            'LOGIN' => $login,
            'PASSWORD' => $password,
            'REQUEST_GATEWAY_CODE' => 'USSD',
            'REQUEST_GATEWAY_TYPE' => 'USSD',
            'requestText' => 'CHECK '.$msisdn,
        ]);

        $response = $this->curlRequest('GET', 'http://'.$host.'/TxnWebapp/JsonSelector?'.$query);

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $this->decodeJsonOrRaw($response['body']),
        ];
    }

    public function smegaRegister(array $payload): array
    {
        $host = trim((string) config('services.smega.registration_ip', ''));
        $login = trim((string) config('services.smega.registration_user', ''));
        $password = trim((string) config('services.smega.check_password', ''));

        if ($host === '' || $login === '' || $password === '') {
            return ['ok' => false, 'error' => 'SMEGA registration config is incomplete'];
        }

        $command = [
            'TYPE' => 'RSUBREG',
            'MSISDN' => (string) ($payload['msisdn'] ?? ''),
            'FNAME' => (string) ($payload['first_name'] ?? ''),
            'LNAME' => (string) ($payload['last_name'] ?? ''),
            'IDNUMBER' => (string) ($payload['document_number'] ?? ''),
            'ADDRESS' => (string) ($payload['address'] ?? ''),
            'CITY' => (string) ($payload['city'] ?? ''),
            'REGTYPEID' => 'FULL_KYC',
            'INCOME_SOURCE' => (string) ($payload['source_of_income'] ?? ''),
        ];

        $query = http_build_query([
            'LOGIN' => $login,
            'PASSWORD' => $password,
            'REQUEST_GATEWAY_CODE' => 'USSD',
            'REQUEST_GATEWAY_TYPE' => 'USSD',
        ]);

        $response = $this->curlRequest(
            'POST',
            'http://'.$host.'/TxnWebapp/JsonSelector?'.$query,
            ['Content-Type: application/json', 'Accept: application/json'],
            json_encode(['COMMAND' => $command], JSON_UNESCAPED_SLASHES)
        );

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $this->decodeJsonOrRaw($response['body']),
        ];
    }

    public function bocraCheckByMsisdn(string $msisdn): array
    {
        $host = trim((string) config('services.bocra.sandbox_url', ''));
        $apiKey = trim((string) config('services.bocra.api_key', ''));

        if ($host === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'BOCRA config is incomplete'];
        }

        $baseUrl = str_starts_with($host, 'http://') || str_starts_with($host, 'https://')
            ? rtrim($host, '/')
            : 'https://'.rtrim($host, '/');

        $response = $this->curlRequest(
            'GET',
            $baseUrl.'/api/v1/phone_numbers/msisdn/'.rawurlencode($msisdn),
            ['x-api-key: '.$apiKey, 'Accept: application/json']
        );

        $parsedBody = $this->decodeJsonOrRaw($response['body']);
        $compliant = false;

        if ($response['ok']) {
            if (is_array($parsedBody)) {
                $compliant = count($parsedBody) > 0;
            } else {
                $compliant = trim((string) $parsedBody) !== '';
            }
        }

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'compliant' => $compliant,
            'body' => $parsedBody,
        ];
    }

    private function c1SecurityToken(): string
    {
        $securityHost = trim((string) config('services.c1.security_ip', ''));
        $user = trim((string) config('services.c1.security_user', ''));
        $password = trim((string) config('services.c1.security_password', ''));
        $realm = trim((string) config('services.c1.realm', 'sapi'));

        if ($securityHost === '' || $user === '' || $password === '') {
            return '';
        }

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:auth="https://org.converse.rtbd.sec/webservice/auth">
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

        $response = $this->curlRequest(
            'POST',
            'https://'.$securityHost.'/SAMLSignOnWS?wsdl',
            ['Content-Type: text/xml;charset=UTF-8'],
            $xml
        );

        if (!$response['ok']) {
            return '';
        }

        return (string) ($this->extractXmlValue($response['body'], 'return') ?? '');
    }

    private function buildC1SubscriberRetrieveXml(string $realm, string $securityToken, string $user, string $msisdn): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:SubscriberRetrieve>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <subscriberId>
          <subscriberId>
            <value>{$msisdn}</value>
          </subscriberId>
          <subscriberExternalIdType>
            <value>1</value>
          </subscriberExternalIdType>
        </subscriberId>
      </com:input>
    </com:SubscriberRetrieve>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function extractXmlValue(string $xml, string $tag): ?string
    {
        if ($xml === '' || $tag === '') {
            return null;
        }

        $pattern = '/<([a-zA-Z0-9_]+:)?'.preg_quote($tag, '/').'>\s*([^<]+)\s*<\/([a-zA-Z0-9_]+:)?'.preg_quote($tag, '/').'>/';
        if (preg_match($pattern, $xml, $matches) === 1) {
            return trim((string) $matches[2]);
        }

        return null;
    }

    private function decodeJsonOrRaw(string $body): mixed
    {
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    private function curlRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not initialize cURL'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (str_starts_with($url, 'https://')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return ['ok' => false, 'status' => $statusCode, 'body' => '', 'error' => $curlError !== '' ? $curlError : null];
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status' => $statusCode,
            'body' => (string) $responseBody,
            'error' => null,
        ];
    }
}
