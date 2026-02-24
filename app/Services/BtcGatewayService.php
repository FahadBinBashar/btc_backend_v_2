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

    public function bocraCheckByDocument(string $documentNumber): array
    {
        $host = trim((string) config('services.bocra.sandbox_url', ''));
        $apiKey = trim((string) config('services.bocra.api_key', ''));

        if ($host === '' || $apiKey === '' || trim($documentNumber) === '') {
            return ['ok' => false, 'error' => 'BOCRA document check config is incomplete'];
        }

        $baseUrl = str_starts_with($host, 'http://') || str_starts_with($host, 'https://')
            ? rtrim($host, '/')
            : 'https://'.rtrim($host, '/');

        $response = $this->curlRequest(
            'GET',
            $baseUrl.'/api/v1/natural_subscribers/'.rawurlencode($documentNumber),
            ['x-api-key: '.$apiKey, 'Accept: application/json']
        );

        $body = $this->decodeJsonOrRaw($response['body']);
        $exists = false;
        if ($response['ok']) {
            $exists = is_array($body) ? count($body) > 0 : trim((string) $body) !== '';
        }

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'exists' => $exists,
            'body' => $body,
        ];
    }

    public function bocraRegisterSubscriber(array $payload): array
    {
        $host = trim((string) config('services.bocra.sandbox_url', ''));
        $apiKey = trim((string) config('services.bocra.api_key', ''));

        if ($host === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'BOCRA registration config is incomplete'];
        }

        $baseUrl = str_starts_with($host, 'http://') || str_starts_with($host, 'https://')
            ? rtrim($host, '/')
            : 'https://'.rtrim($host, '/');

        $request = [
            'msisdn' => (string) ($payload['msisdn'] ?? ''),
            'firstName' => (string) ($payload['first_name'] ?? ''),
            'lastName' => (string) ($payload['last_name'] ?? ''),
            'country' => (string) ($payload['country'] ?? 'BOTSWANA'),
            'dateOfBirth' => (string) ($payload['dob_iso'] ?? ''),
            'sex' => (string) ($payload['gender'] ?? ''),
            'documents' => [[
                'documentNumber' => (string) ($payload['document_number'] ?? ''),
                'documentType' => (string) ($payload['document_type'] ?? 'NATIONAL_ID'),
                'dateOfIssue' => (string) ($payload['document_issue_date'] ?? ''),
                'expiryDate' => (string) ($payload['document_expiry_date'] ?? ''),
            ]],
            'addresses' => [[
                'plotWardBox' => (string) ($payload['physical_address'] ?? ''),
                'cityTown' => (string) ($payload['city'] ?? ''),
                'addressType' => 'PHYSICAL',
            ], [
                'plotWardBox' => (string) ($payload['postal_address'] ?? ''),
                'cityTown' => (string) ($payload['city'] ?? ''),
                'addressType' => 'POSTAL',
            ]],
        ];

        $response = $this->curlRequest(
            'POST',
            $baseUrl.'/api/v1/natural_subscribers/update_address_documents',
            ['x-api-key: '.$apiKey, 'Accept: application/json', 'Content-Type: application/json'],
            json_encode($request, JSON_UNESCAPED_SLASHES)
        );

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $this->decodeJsonOrRaw($response['body']),
            'request' => $request,
        ];
    }

    public function c1ApplyConditionalUpdates(array $payload): array
    {
        $serviceInternalId = trim((string) ($payload['service_internal_id'] ?? ''));
        if ($serviceInternalId === '') {
            return ['ok' => false, 'error' => 'service_internal_id is required for C1 updates'];
        }

        $steps = [];
        $allOk = true;

        $subscriberUpdate = $this->c1SubscriberUpdate($payload);
        $steps['subscriber_update'] = $subscriberUpdate;
        $allOk = $allOk && (bool) ($subscriberUpdate['ok'] ?? false);

        if (!empty($payload['address']) || !empty($payload['city'])) {
            $addressUpdate = $this->c1AddressUpdate($payload);
            $steps['address_update'] = $addressUpdate;
            $allOk = $allOk && (bool) ($addressUpdate['ok'] ?? false);
        }

        if (!empty($payload['account_internal_id'])) {
            $accountUpdate = $this->c1AccountBaseUpdate($payload);
            $steps['account_update'] = $accountUpdate;
            $allOk = $allOk && (bool) ($accountUpdate['ok'] ?? false);
        }

        if (!empty($payload['persona_internal_id'])) {
            $personaUpdate = $this->c1PersonaUpdate($payload);
            $steps['persona_update'] = $personaUpdate;
            $allOk = $allOk && (bool) ($personaUpdate['ok'] ?? false);
        }

        $ratingUpdate = $this->c1UpdateRatingStatus(
            $serviceInternalId,
            (bool) ($payload['resume'] ?? true)
        );
        $steps['rating_status'] = $ratingUpdate;
        $allOk = $allOk && (bool) ($ratingUpdate['ok'] ?? false);

        return [
            'ok' => $allOk,
            'steps' => $steps,
        ];
    }

    public function c1SubscriberResume(string $serviceInternalId, string $comment = 'KYC compliant'): array
    {
        return $this->c1SubscriberLifecycle($serviceInternalId, true, $comment);
    }

    public function c1SubscriberSuspend(string $serviceInternalId, string $comment = 'NON COMPLIANT FOR KYC'): array
    {
        return $this->c1SubscriberLifecycle($serviceInternalId, false, $comment);
    }

    public function logTransaction(array $payload): array
    {
        $logUrl = trim((string) config('services.middleware.log_url', ''));
        if ($logUrl === '') {
            return ['ok' => false, 'error' => 'Middleware log URL is not configured'];
        }

        if (!str_starts_with($logUrl, 'http://') && !str_starts_with($logUrl, 'https://')) {
            $logUrl = 'http://'.ltrim($logUrl, '/');
        }

        $request = [
            'journey_id' => (string) ($payload['journey_id'] ?? ''),
            'event_type' => (string) ($payload['event_type'] ?? 'API_CALL'),
            'correlation_id' => (string) ($payload['correlation_id'] ?? ''),
            'actor' => (string) ($payload['actor'] ?? 'SYSTEM'),
            'action' => (string) ($payload['action'] ?? ''),
            'outcome' => (string) ($payload['outcome'] ?? 'SUCCESS'),
            'timestamp' => (string) ($payload['timestamp'] ?? now()->toISOString()),
            'msisdn' => (string) ($payload['msisdn'] ?? ''),
            'api_called' => (string) ($payload['api_called'] ?? ''),
            'request_payload' => $payload['request_payload'] ?? '',
            'response_payload' => $payload['response_payload'] ?? '',
            'status_code' => (string) ($payload['status_code'] ?? ''),
            'error_code' => (string) ($payload['error_code'] ?? ''),
            'error_message' => (string) ($payload['error_message'] ?? ''),
        ];

        $response = $this->curlRequest(
            'POST',
            $logUrl,
            ['Accept: application/json', 'Content-Type: application/json'],
            json_encode($request, JSON_UNESCAPED_SLASHES)
        );

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $this->decodeJsonOrRaw($response['body']),
            'request' => $request,
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

    private function c1SubscriberUpdate(array $payload): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $serviceInternalId = trim((string) ($payload['service_internal_id'] ?? ''));
        $msisdn = trim((string) ($payload['msisdn'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        $nationality = trim((string) ($payload['nationality'] ?? ''));
        $dob = trim((string) ($payload['dob'] ?? ''));
        $gender = trim((string) ($payload['gender'] ?? ''));

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:SubscriberUpdate>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <subscriber>
          <serviceInternalId changed="true" set="true"><value>{$serviceInternalId}</value></serviceInternalId>
          <serviceCompany changed="true" set="true"><value>BTC</value></serviceCompany>
          <serviceFname changed="true" set="true"><value>{$firstName}</value></serviceFname>
          <serviceLname changed="true" set="true"><value>{$lastName}</value></serviceLname>
          <servicePhone changed="true" set="true"><value>{$msisdn}</value></servicePhone>
          <streetName changed="true" set="true"><value>{$address}</value></streetName>
        </subscriber>
        <personas>
          <person>
            <addressline1 changed="true" set="true"><value>{$address}</value></addressline1>
            <city changed="true" set="true"><value>{$city}</value></city>
            <dateOfBirth changed="true" set="true"><value>{$dob}</value></dateOfBirth>
            <email changed="true" set="true"><value>{$email}</value></email>
            <firstName changed="true" set="true"><value>{$firstName}</value></firstName>
            <gender changed="true" set="true"><value>{$gender}</value></gender>
            <lastName changed="true" set="true"><value>{$lastName}</value></lastName>
            <nationalId changed="true" set="true"><value>{$documentNumber}</value></nationalId>
            <nationality changed="true" set="true"><value>{$nationality}</value></nationality>
            <mobile changed="true" set="true"><value>{$msisdn}</value></mobile>
          </person>
        </personas>
        <autoCommitOrder>1</autoCommitOrder>
      </com:input>
    </com:SubscriberUpdate>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/SubscriberService', $xml);
    }

    private function c1AddressUpdate(array $payload): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $accountInternalId = trim((string) ($payload['account_internal_id'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));

        if ($accountInternalId === '') {
            return ['ok' => true, 'status' => 200, 'body' => 'address update skipped (no account_internal_id)'];
        }

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:AccountBaseUpdate>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <account>
          <accountInternalId changed="true" set="true"><value>{$accountInternalId}</value></accountInternalId>
          <billAddress1 changed="true" set="true"><value>{$address}</value></billAddress1>
          <billCity changed="true" set="true"><value>{$city}</value></billCity>
          <custAddress1 changed="true" set="true"><value>{$address}</value></custAddress1>
          <custCity changed="true" set="true"><value>{$city}</value></custCity>
        </account>
      </com:input>
    </com:AccountBaseUpdate>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/AccountService', $xml);
    }

    private function c1AccountBaseUpdate(array $payload): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $accountInternalId = trim((string) ($payload['account_internal_id'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $msisdn = trim((string) ($payload['msisdn'] ?? ''));
        $gender = trim((string) ($payload['gender'] ?? ''));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:AccountBaseUpdate>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <account>
          <accountInternalId changed="true" set="true"><value>{$accountInternalId}</value></accountInternalId>
          <billAddress1 changed="true" set="true"><value>{$address}</value></billAddress1>
          <billCity changed="true" set="true"><value>{$city}</value></billCity>
          <billFname changed="true" set="true"><value>{$firstName}</value></billFname>
          <billLname changed="true" set="true"><value>{$lastName}</value></billLname>
          <custAddress1 changed="true" set="true"><value>{$address}</value></custAddress1>
          <custCity changed="true" set="true"><value>{$city}</value></custCity>
          <custEmail changed="true" set="true"><value>{$email}</value></custEmail>
          <custPhone1 changed="true" set="true"><value>{$msisdn}</value></custPhone1>
          <gender changed="true" set="true"><value>{$gender}</value></gender>
          <ssn changed="true" set="true"><value>{$documentNumber}</value></ssn>
        </account>
      </com:input>
    </com:AccountBaseUpdate>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/AccountService', $xml);
    }

    private function c1PersonaUpdate(array $payload): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $personaInternalId = trim((string) ($payload['persona_internal_id'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        $nationality = trim((string) ($payload['nationality'] ?? ''));
        $dob = trim((string) ($payload['dob'] ?? ''));
        $gender = trim((string) ($payload['gender'] ?? ''));

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:PersonaUpdate>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <persona>
          <personaInternalId changed="true" set="true"><value>{$personaInternalId}</value></personaInternalId>
          <firstName changed="true" set="true"><value>{$firstName}</value></firstName>
          <lastName changed="true" set="true"><value>{$lastName}</value></lastName>
          <nationalId changed="true" set="true"><value>{$documentNumber}</value></nationalId>
          <nationality changed="true" set="true"><value>{$nationality}</value></nationality>
          <dateOfBirth changed="true" set="true"><value>{$dob}</value></dateOfBirth>
          <gender changed="true" set="true"><value>{$gender}</value></gender>
        </persona>
        <autoCommitOrder>1</autoCommitOrder>
      </com:input>
    </com:PersonaUpdate>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/SubscriberService', $xml);
    }

    private function c1UpdateRatingStatus(string $serviceInternalId, bool $resume): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $state = $resume ? '2' : '3';

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:SubscriberUpdateRatingStatus>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <subscriberId>
          <serviceInternalId changed="true" set="true"><value>{$serviceInternalId}</value></serviceInternalId>
          <serviceInternalIdResets changed="true" set="true"><value>0</value></serviceInternalIdResets>
        </subscriberId>
        <postRatingState>{$state}</postRatingState>
      </com:input>
    </com:SubscriberUpdateRatingStatus>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/SubscriberService', $xml);
    }

    private function c1SubscriberLifecycle(string $serviceInternalId, bool $resume, string $comment): array
    {
        $securityToken = $this->c1SecurityToken();
        $realm = trim((string) config('services.c1.realm', 'sapi'));
        $user = trim((string) config('services.c1.billing_user', ''));
        $action = $resume ? 'SubscriberResumeBilling' : 'SubscriberSuspendBilling';

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:com="http://www.converse.com">
  <soapenv:Header/>
  <soapenv:Body>
    <com:{$action}>
      <com:input>
        <realm>{$realm}</realm>
        <securityToken>{$securityToken}</securityToken>
        <userIdName>{$user}</userIdName>
        <subscriberId>
          <serviceInternalId set="true" changed="true"><value>{$serviceInternalId}</value></serviceInternalId>
          <serviceInternalIdResets set="true" changed="true"><value>0</value></serviceInternalIdResets>
        </subscriberId>
        <statusReasonId>13</statusReasonId>
        <comment>{$comment}</comment>
        <autoCommitOrder>1</autoCommitOrder>
        <generateWorkflow>true</generateWorkflow>
      </com:input>
    </com:{$action}>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $this->callC1Service('/services/SubscriberService', $xml);
    }

    private function callC1Service(string $path, string $xml): array
    {
        $billingHost = trim((string) config('services.c1.billing_ip', ''));
        if ($billingHost === '') {
            return ['ok' => false, 'error' => 'C1 billing host not configured'];
        }

        $response = $this->curlRequest(
            'POST',
            'http://'.$billingHost.$path,
            ['Content-Type: text/xml;charset=UTF-8'],
            $xml
        );

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'body' => $response['body'],
        ];
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
