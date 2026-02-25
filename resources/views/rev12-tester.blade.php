<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BTC Rev 1.2 API Tester</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f6fa; color: #1f2937; }
        h1 { margin-bottom: 8px; }
        .note { margin-bottom: 16px; color: #4b5563; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
        .card { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 12px; }
        .card h3 { margin: 0 0 10px 0; font-size: 16px; }
        label { font-size: 12px; color: #374151; display: block; margin: 6px 0 3px; }
        input, textarea, select, button { width: 100%; box-sizing: border-box; }
        input, textarea, select { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 12px; }
        textarea { min-height: 90px; font-family: Consolas, monospace; }
        button { margin-top: 10px; background: #0f766e; color: #fff; border: 0; border-radius: 6px; padding: 9px; cursor: pointer; }
        button:hover { background: #0d635d; }
        pre { background: #0b1020; color: #d1f7ff; padding: 10px; border-radius: 6px; max-height: 220px; overflow: auto; font-size: 11px; }
        .small { font-size: 11px; color: #6b7280; margin-top: 6px; }
    </style>
</head>
<body>
<h1>BTC Rev 1.2 API Tester</h1>
<div class="note">Use this page to test all Laravel-exposed Rev 1.2 endpoints from one place.</div>

<div class="grid">
    <div class="card">
        <h3>1. Security Token</h3>
        <button onclick="runApi('GET', '/rev12/security/token', null, 'out-token')">Run</button>
        <pre id="out-token">-</pre>
    </div>

    <div class="card">
        <h3>2. Subscriber Retrieve</h3>
        <label>MSISDN</label>
        <input id="sr-msisdn" value="26773717137">
        <button onclick="runApi('POST', '/rev12/billing/subscriber-retrieve', {msisdn: val('sr-msisdn')}, 'out-sr')">Run</button>
        <pre id="out-sr">-</pre>
    </div>

    <div class="card">
        <h3>3. Subscriber Resume</h3>
        <label>Service Internal ID</label>
        <input id="resume-sid" value="8797264">
        <label>Comment</label>
        <input id="resume-comment" value="KYC compliant">
        <button onclick="runApi('POST', '/rev12/billing/subscriber-resume', {service_internal_id: val('resume-sid'), comment: val('resume-comment')}, 'out-resume')">Run</button>
        <pre id="out-resume">-</pre>
    </div>

    <div class="card">
        <h3>4. Subscriber Suspend</h3>
        <label>Service Internal ID</label>
        <input id="suspend-sid" value="8797264">
        <label>Comment</label>
        <input id="suspend-comment" value="NON COMPLIANT FOR KYC">
        <button onclick="runApi('POST', '/rev12/billing/subscriber-suspend', {service_internal_id: val('suspend-sid'), comment: val('suspend-comment')}, 'out-suspend')">Run</button>
        <pre id="out-suspend">-</pre>
    </div>

    <div class="card">
        <h3>5-8. Subscriber/Account/Address/Persona Update</h3>
        <label>Endpoint</label>
        <select id="upd-endpoint" onchange="applyEndpointPayload('upd-endpoint','upd-payload', updTemplates)">
            <option value="/rev12/billing/subscriber-update">SubscriberUpdate</option>
            <option value="/rev12/billing/account-update">AccountUpdate</option>
            <option value="/rev12/billing/address-update">AddressUpdate</option>
            <option value="/rev12/billing/persona-update">PersonaUpdate</option>
        </select>
        <label>Payload JSON</label>
        <textarea id="upd-payload">{
  "service_internal_id": "8797264",
  "account_internal_id": "9875599",
  "persona_internal_id": "",
  "msisdn": "26773717137",
  "first_name": "Moffat",
  "last_name": "Matenge",
  "address": "Plot 3501 Metlhabeng",
  "city": "Tlokweng",
  "email": "moffat@btc.bw",
  "document_number": "935512806",
  "nationality": "Motswana",
  "dob": "1980-12-14",
  "gender": "MALE"
}</textarea>
        <button onclick="runJsonEndpoint('upd-endpoint','upd-payload','out-upd')">Run</button>
        <pre id="out-upd">-</pre>
    </div>

    <div class="card">
        <h3>9. Update Rating Status</h3>
        <label>Service Internal ID</label>
        <input id="rate-sid" value="8797264">
        <label>Resume (true/false)</label>
        <input id="rate-resume" value="true">
        <button onclick="runApi('POST', '/rev12/billing/update-rating-status', {service_internal_id: val('rate-sid'), resume: val('rate-resume') === 'true'}, 'out-rate')">Run</button>
        <pre id="out-rate">-</pre>
    </div>

    <div class="card">
        <h3>10-12c. BOCRA APIs</h3>
        <label>Endpoint</label>
        <select id="bocra-endpoint" onchange="applyEndpointPayload('bocra-endpoint','bocra-payload', bocraTemplates)">
            <option value="/rev12/bocra/check-msisdn">CheckRegistrationByMsisdn</option>
            <option value="/rev12/bocra/check-document">CheckRegistrationByDocument</option>
            <option value="/rev12/bocra/register">RegisterAtBocra</option>
            <option value="/rev12/bocra/update-subscriber">UpdateSubscriberPatch</option>
            <option value="/rev12/bocra/update-address-docs">UpdateAddressDocumentsPatch</option>
        </select>
        <label>Payload JSON</label>
        <textarea id="bocra-payload">{
  "msisdn": "26773717137",
  "first_name": "Moffat",
  "last_name": "Matenge",
  "country": "BOTSWANA",
  "dob_iso": "1980-12-14T00:00:00.000Z",
  "gender": "MALE",
  "document_number": "935512806",
  "document_type": "NATIONAL_ID",
  "physical_address": "Plot 3501 Metlhabeng",
  "postal_address": "Plot 3501 Metlhabeng",
  "city": "Tlokweng"
}</textarea>
        <button onclick="runJsonEndpoint('bocra-endpoint','bocra-payload','out-bocra')">Run</button>
        <pre id="out-bocra">-</pre>
    </div>

    <div class="card">
        <h3>13-14. SMEGA APIs</h3>
        <label>Endpoint</label>
        <select id="smega-endpoint" onchange="applyEndpointPayload('smega-endpoint','smega-payload', smegaTemplates)">
            <option value="/rev12/smega/check">CheckSmega</option>
            <option value="/rev12/smega/register">RegisterSmega</option>
        </select>
        <label>Payload JSON</label>
        <textarea id="smega-payload">{
  "msisdn": "26773717137",
  "first_name": "Moffat",
  "last_name": "Matenge",
  "document_number": "935512806",
  "address": "Plot 3501 Metlhabeng",
  "city": "Tlokweng",
  "source_of_income": "SALARY"
}</textarea>
        <button onclick="runJsonEndpoint('smega-endpoint','smega-payload','out-smega')">Run</button>
        <pre id="out-smega">-</pre>
    </div>

    <div class="card">
        <h3>15. Log Transaction</h3>
        <label>Payload JSON</label>
        <textarea id="log-payload">{
  "journey_id": "jrn-001",
  "event_type": "API_CALL",
  "correlation_id": "",
  "actor": "SYSTEM",
  "action": "SUBSCRIBER_UPDATE",
  "outcome": "SUCCESS",
  "msisdn": "26773717137"
}</textarea>
        <button onclick="runJsonDirect('/rev12/logging/transaction', 'log-payload', 'out-log')">Run</button>
        <pre id="out-log">-</pre>
    </div>
</div>

<p class="small">Tip: Keep this page on BTC Windows server and run all tests there for stable internal routing.</p>

<script>
function val(id) { return document.getElementById(id).value; }
const API_BASE = "{{ rtrim(url('/'), '/') }}";
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const baseKyc = {
    msisdn: '26773717137',
    service_internal_id: '8797264',
    account_internal_id: '9875599',
    persona_internal_id: '',
    first_name: 'Moffat',
    last_name: 'Matenge',
    address: 'Plot 3501 Metlhabeng',
    city: 'Tlokweng',
    email: 'moffat@btc.bw',
    document_number: '935512806',
    document_type: 'NATIONAL_ID',
    nationality: 'Motswana',
    dob: '1980-12-14',
    dob_iso: '1980-12-14T00:00:00.000Z',
    gender: 'MALE',
};

const updTemplates = {
    '/rev12/billing/subscriber-update': {
        service_internal_id: baseKyc.service_internal_id,
        msisdn: baseKyc.msisdn,
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        address: baseKyc.address,
        city: baseKyc.city,
        email: baseKyc.email,
        document_number: baseKyc.document_number,
        nationality: baseKyc.nationality,
        dob: baseKyc.dob,
        gender: baseKyc.gender,
    },
    '/rev12/billing/account-update': {
        account_internal_id: baseKyc.account_internal_id,
        msisdn: baseKyc.msisdn,
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        address: baseKyc.address,
        city: baseKyc.city,
        email: baseKyc.email,
        document_number: baseKyc.document_number,
        gender: baseKyc.gender,
    },
    '/rev12/billing/address-update': {
        account_internal_id: baseKyc.account_internal_id,
        address: baseKyc.address,
        city: baseKyc.city,
    },
    '/rev12/billing/persona-update': {
        persona_internal_id: '',
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        document_number: baseKyc.document_number,
        nationality: baseKyc.nationality,
        dob: baseKyc.dob,
        gender: baseKyc.gender,
    },
};

const bocraTemplates = {
    '/rev12/bocra/check-msisdn': {
        msisdn: baseKyc.msisdn,
    },
    '/rev12/bocra/check-document': {
        document_number: baseKyc.document_number,
    },
    '/rev12/bocra/register': {
        msisdn: baseKyc.msisdn,
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        country: 'BOTSWANA',
        dob_iso: baseKyc.dob_iso,
        gender: baseKyc.gender,
        document_number: baseKyc.document_number,
        document_type: baseKyc.document_type,
        physical_address: baseKyc.address,
        postal_address: baseKyc.address,
        city: baseKyc.city,
    },
    '/rev12/bocra/update-subscriber': {
        msisdn: baseKyc.msisdn,
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        country: 'BOTSWANA',
        dob_iso: baseKyc.dob_iso,
        gender: baseKyc.gender,
        document_number: baseKyc.document_number,
        document_type: baseKyc.document_type,
    },
    '/rev12/bocra/update-address-docs': {
        msisdn: baseKyc.msisdn,
        document_number: baseKyc.document_number,
        document_type: baseKyc.document_type,
        physical_address: baseKyc.address,
        postal_address: baseKyc.address,
        city: baseKyc.city,
    },
};

const smegaTemplates = {
    '/rev12/smega/check': {
        msisdn: baseKyc.msisdn,
    },
    '/rev12/smega/register': {
        msisdn: baseKyc.msisdn,
        first_name: baseKyc.first_name,
        last_name: baseKyc.last_name,
        document_number: baseKyc.document_number,
        address: baseKyc.address,
        city: baseKyc.city,
        source_of_income: 'SALARY',
    },
};

function setOut(id, obj) {
    document.getElementById(id).textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
}

function toAbsolute(path) {
    if (!path.startsWith('/')) return path;
    return API_BASE + path;
}

async function runApi(method, path, payload, outId) {
    try {
        const res = await fetch(toAbsolute(path), {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            credentials: 'same-origin',
            body: payload ? JSON.stringify(payload) : null
        });
        const contentType = res.headers.get('content-type') || '';
        const data = contentType.includes('application/json') ? await res.json() : await res.text();
        setOut(outId, data);
    } catch (e) {
        setOut(outId, 'Request failed: ' + e.message);
    }
}

function runJsonEndpoint(endpointId, payloadId, outId) {
    const path = val(endpointId);
    runJsonDirect(path, payloadId, outId);
}

function applyEndpointPayload(endpointId, payloadId, templates) {
    const path = val(endpointId);
    const payload = templates[path];
    if (!payload) return;
    document.getElementById(payloadId).value = JSON.stringify(payload, null, 2);
}

function runJsonDirect(path, payloadId, outId) {
    let payload;
    try {
        payload = JSON.parse(val(payloadId));
    } catch (e) {
        setOut(outId, 'Invalid JSON: ' + e.message);
        return;
    }
    runApi('POST', path, payload, outId);
}

applyEndpointPayload('upd-endpoint', 'upd-payload', updTemplates);
applyEndpointPayload('bocra-endpoint', 'bocra-payload', bocraTemplates);
applyEndpointPayload('smega-endpoint', 'smega-payload', smegaTemplates);
</script>
</body>
</html>
