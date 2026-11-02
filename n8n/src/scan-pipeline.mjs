import { workflow, node, trigger, newCredential, ifElse, merge, expr } from '@n8n/workflow-sdk';

const VERIFY_JOB = 'const item = $input.first();\n' +
'const req = item.json;\n' +
'const headers = req.headers || {};\n' +
'const deny = (reason) => [{ json: { valid: false, reason } }];\n' +
"const received = String(headers['x-shr-signature'] || '').replace(/^sha256=/, '').trim().toLowerCase();\n" +
"const expected = String(req.expected_signature || '').toLowerCase();\n" +
'// Constant-time comparison. The crypto module is not available in Code nodes on this instance.\n' +
'let diff = received.length ^ expected.length;\n' +
'for (let i = 0; i < expected.length; i++) {\n' +
'  diff |= (received.charCodeAt(i) || 0) ^ expected.charCodeAt(i);\n' +
'}\n' +
"if (!expected || diff !== 0) return deny('bad_signature');\n" +
'let job = req.body;\n' +
"if (typeof job === 'string') {\n" +
"  try { job = JSON.parse(job); } catch (e) { return deny('bad_json'); }\n" +
'}\n' +
"if (!job || !job.scan_id) {\n" +
"  try { job = JSON.parse(Buffer.from(item.binary.data.data, 'base64').toString('utf8')); } catch (e) { return deny('bad_json'); }\n" +
'}\n' +
"if (job.type !== 'scan.job' || job.v !== 1) return deny('bad_message');\n" +
'const now = Math.floor(Date.now() / 1000);\n' +
"if (Math.abs(now - Number(job.issued_at)) > 300) return deny('stale');\n" +
"const store = $getWorkflowStaticData('global');\n" +
'store.nonces = store.nonces || {};\n' +
'for (const key of Object.keys(store.nonces)) {\n' +
'  if (store.nonces[key] < now) delete store.nonces[key];\n' +
'}\n' +
"if (!/^[0-9a-f]{32}$/.test(String(job.nonce)) || store.nonces[job.nonce]) return deny('replayed');\n" +
'store.nonces[job.nonce] = now + 600;\n' +
'return [{ json: { valid: true, scan_id: job.scan_id, url: job.url, domain: job.domain, callback_url: job.callback_url, scanner_url: job.scanner_url } }];';

const CHECK_SCANNER = 'const r = $input.first().json;\n' +
'const job = $("Verify Job").first().json;\n' +
"if (r.status === 'complete') return [{ json: { done: true, source: 'scanner', result: r.result } }];\n" +
"if (r.status === 'failed') return [{ json: { done: true, source: 'scanner', error: r.error || 'scanner_failed' } }];\n" +
"if ($runIndex >= 24) return [{ json: { done: true, source: 'scanner', error: 'scanner_timeout' } }];\n" +
'return [{ json: { done: false, id: r.id, scanner_url: job.scanner_url } }];';

const SCANNER_DOWN = "const e = $input.first().json;\n" +
"const code = String((e.error && e.error.message) || e.message || '');\n" +
"return [{ json: { done: true, source: 'scanner', error: /422/.test(code) ? 'blocked_target' : 'scanner_unavailable' } }];";

const EXTRACT_PSI = 'const r = $input.first().json;\n' +
"const audits = (r.lighthouseResult && r.lighthouseResult.audits) || null;\n" +
"const category = r.lighthouseResult && r.lighthouseResult.categories && r.lighthouseResult.categories.performance;\n" +
'if (!audits || !category) {\n' +
"  const message = (r.error && (r.error.message || r.error)) || 'pagespeed_unavailable';\n" +
"  return [{ json: { source: 'pagespeed', pagespeed: { error: String(message).slice(0, 180) } } }];\n" +
'}\n' +
"const num = (key) => Number((audits[key] && audits[key].numericValue) || 0);\n" +
"return [{ json: { source: 'pagespeed', pagespeed: { score: Math.round(category.score * 100), lcp_ms: Math.round(num('largest-contentful-paint')), cls: Math.round(num('cumulative-layout-shift') * 1000) / 1000, tbt_ms: Math.round(num('total-blocking-time')), error: null } } }];";

const BUILD_DNS = 'const domain = $("Verify Job").first().json.domain;\n' +
"const selectors = ['default', 'google', 'selector1', 'selector2', 'k1', 'k2', 's1', 's2', 'mail', 'dkim', 'zmail', 'zmail1', 'brevo1', 'brevo2', 'mandrill', 'mxvault', 'smtp', 'protonmail'];\n" +
"const out = [{ json: { kind: 'spf', name: domain } }, { json: { kind: 'dmarc', name: '_dmarc.' + domain } }];\n" +
"for (const s of selectors) out.push({ json: { kind: 'dkim', selector: s, name: s + '._domainkey.' + domain } });\n" +
'return out;';

const SUMMARIZE_DNS = 'const queries = $("Build DNS Queries").all();\n' +
'const answers = $input.all();\n' +
'const dns = {\n' +
'  spf: { found: false, record: null, error: null },\n' +
'  dmarc: { found: false, record: null, error: null },\n' +
"  dkim: { found: false, selectors: [], checked: 0, error: null },\n" +
'};\n' +
"const texts = (a) => (a.Answer || []).filter((r) => r.type === 16).map((r) => String(r.data).replace(/\"\\s*\"/g, '').replace(/^\"|\"$/g, ''));\n" +
'let dkimErrors = 0;\n' +
'answers.forEach((item, i) => {\n' +
'  const q = queries[i].json;\n' +
'  const a = item.json;\n' +
'  const failed = a.error !== undefined || a.Status === undefined || a.Status === 2;\n' +
"  if (q.kind === 'dkim') {\n" +
'    dns.dkim.checked++;\n' +
'    if (failed) { dkimErrors++; return; }\n' +
"    if (texts(a).some((t) => /v=DKIM1|(^|;\\s*)p=/i.test(t))) dns.dkim.selectors.push(q.selector);\n" +
'    return;\n' +
'  }\n' +
"  if (failed) { dns[q.kind].error = 'lookup_failed'; return; }\n" +
"  const prefix = q.kind === 'spf' ? /^v=spf1/i : /^v=DMARC1/i;\n" +
'  const record = texts(a).find((t) => prefix.test(t));\n' +
'  if (record) { dns[q.kind].found = true; dns[q.kind].record = record.slice(0, 380); }\n' +
'});\n' +
'dns.dkim.found = dns.dkim.selectors.length > 0;\n' +
"if (dkimErrors === dns.dkim.checked) dns.dkim.error = 'lookup_failed';\n" +
"return [{ json: { source: 'dns', dns } }];";

const ASSEMBLE = 'const job = $("Verify Job").first().json;\n' +
"let scanner = null, error = 'scanner_failed', pagespeed = { error: 'pagespeed_unavailable' }, dns = {};\n" +
'for (const item of $input.all()) {\n' +
'  const j = item.json;\n' +
"  if (j.source === 'scanner') { scanner = j.result || null; error = j.error || error; }\n" +
"  if (j.source === 'pagespeed') pagespeed = j.pagespeed;\n" +
"  if (j.source === 'dns') dns = j.dns;\n" +
'}\n' +
"let nonce = '';\n" +
'while (nonce.length < 32) nonce += Math.floor(Math.random() * 16).toString(16);\n' +
'const payload = {\n' +
"  v: 1, type: 'scan.result', scan_id: job.scan_id,\n" +
"  status: scanner ? 'complete' : 'failed', error: scanner ? null : error,\n" +
'  issued_at: Math.floor(Date.now() / 1000), nonce, scanner, pagespeed, dns,\n' +
'};\n' +
'return [{ json: { payload_json: JSON.stringify(payload), callback_url: job.callback_url } }];';

const receiveJob = trigger({
  type: 'n8n-nodes-base.webhook',
  version: 2.1,
  config: {
    name: 'Receive Signed Job',
    parameters: { httpMethod: 'POST', path: 'shr-scan', responseMode: 'responseNode', options: { rawBody: true } },
    position: [0, 300]
  },
  output: [{ headers: {}, body: {} }]
});

const computeJobSignature = node({
  type: 'n8n-nodes-base.crypto',
  version: 2,
  config: {
    name: 'Compute Job Signature',
    parameters: { action: 'hmac', binaryData: true, binaryPropertyName: 'data', type: 'SHA256', dataPropertyName: 'expected_signature', encoding: 'hex' },
    credentials: { crypto: newCredential('Health Report HMAC') },
    position: [220, 300]
  },
  output: [{ expected_signature: 'abc' }]
});

const verifyJob = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Verify Job', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: VERIFY_JOB }, position: [440, 300] },
  output: [{ valid: true, scan_id: 'id', url: 'https://site.org/', domain: 'site.org', callback_url: 'https://site.org/cb', scanner_url: 'https://site.org/scanner' }]
});

const jobValid = ifElse({
  version: 2.2,
  config: {
    name: 'Job Valid?',
    parameters: {
      conditions: {
        options: { caseSensitive: true, leftValue: '', typeValidation: 'strict' },
        conditions: [{ leftValue: expr('{{ $json.valid }}'), operator: { type: 'boolean', operation: 'true', singleValue: true }, rightValue: '' }],
        combinator: 'and'
      }
    },
    position: [660, 300]
  }
});

const respondRejected = node({
  type: 'n8n-nodes-base.respondToWebhook',
  version: 1.5,
  config: { name: 'Respond Rejected', parameters: { respondWith: 'json', responseBody: '{ "accepted": false }', options: { responseCode: 401 } }, position: [880, 520] },
  output: [{}]
});

const respondAccepted = node({
  type: 'n8n-nodes-base.respondToWebhook',
  version: 1.5,
  config: { name: 'Respond Accepted', parameters: { respondWith: 'json', responseBody: '{ "accepted": true }', options: { responseCode: 202 } }, position: [880, 200] },
  output: [{}]
});

const createScan = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Create Scanner Job',
    parameters: {
      method: 'POST',
      url: expr('{{ $("Verify Job").first().json.scanner_url }}/api/v1/scans'),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpHeaderAuth',
      sendBody: true,
      contentType: 'json',
      specifyBody: 'json',
      jsonBody: expr('{{ JSON.stringify({ scan_ref: $("Verify Job").first().json.scan_id, url: $("Verify Job").first().json.url }) }}'),
      options: { timeout: 15000 }
    },
    credentials: { httpHeaderAuth: newCredential('Health Report Scanner Key') },
    retryOnFail: true,
    maxTries: 3,
    waitBetweenTries: 3000,
    onError: 'continueErrorOutput',
    position: [1100, 0]
  },
  output: [{ id: 'abc', status: 'queued' }]
});

const waitForScan = node({
  type: 'n8n-nodes-base.wait',
  version: 1.1,
  config: { name: 'Wait For Scanner', parameters: { resume: 'timeInterval', amount: 5, unit: 'seconds' }, position: [1320, -60] },
  output: [{}]
});

const getScan = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Get Scanner Result',
    parameters: {
      method: 'GET',
      url: expr('{{ $("Verify Job").first().json.scanner_url }}/api/v1/scans/{{ $("Create Scanner Job").first().json.id }}'),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpHeaderAuth',
      options: { timeout: 15000 }
    },
    credentials: { httpHeaderAuth: newCredential('Health Report Scanner Key') },
    retryOnFail: true,
    maxTries: 3,
    waitBetweenTries: 3000,
    onError: 'continueErrorOutput',
    position: [1540, -60]
  },
  output: [{ id: 'abc', status: 'complete', result: {}, error: null }]
});

const checkScan = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Check Scanner Status', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: CHECK_SCANNER }, position: [1760, -60] },
  output: [{ done: true, source: 'scanner', result: {} }]
});

const scanDone = ifElse({
  version: 2.2,
  config: {
    name: 'Scanner Finished?',
    parameters: {
      conditions: {
        options: { caseSensitive: true, leftValue: '', typeValidation: 'strict' },
        conditions: [{ leftValue: expr('{{ $json.done }}'), operator: { type: 'boolean', operation: 'true', singleValue: true }, rightValue: '' }],
        combinator: 'and'
      }
    },
    position: [1980, -60]
  }
});

const scannerDown = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Scanner Unavailable', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: SCANNER_DOWN }, position: [1540, 140] },
  output: [{ done: true, source: 'scanner', error: 'scanner_unavailable' }]
});

const pagespeed = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'PageSpeed Insights',
    parameters: {
      method: 'GET',
      url: 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
      authentication: 'genericCredentialType',
      genericAuthType: 'httpQueryAuth',
      sendQuery: true,
      specifyQuery: 'keypair',
      queryParameters: { parameters: [
        { name: 'url', value: expr('{{ $("Verify Job").first().json.url }}') },
        { name: 'strategy', value: 'mobile' },
        { name: 'category', value: 'performance' }
      ] },
      options: { timeout: 90000 }
    },
    credentials: { httpQueryAuth: newCredential('Health Report PageSpeed Key') },
    retryOnFail: true,
    maxTries: 2,
    waitBetweenTries: 5000,
    onError: 'continueRegularOutput',
    position: [1100, 300]
  },
  output: [{ lighthouseResult: {} }]
});

const extractPsi = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Extract PageSpeed Metrics', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: EXTRACT_PSI }, position: [1320, 300] },
  output: [{ source: 'pagespeed', pagespeed: {} }]
});

const buildDns = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Build DNS Queries', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: BUILD_DNS }, position: [1100, 520] },
  output: [{ kind: 'spf', name: 'site.org' }]
});

const queryDns = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'DNS over HTTPS',
    parameters: {
      method: 'GET',
      url: 'https://dns.google/resolve',
      sendQuery: true,
      specifyQuery: 'keypair',
      queryParameters: { parameters: [{ name: 'name', value: expr('{{ $json.name }}') }, { name: 'type', value: 'TXT' }] },
      options: { timeout: 10000, batching: { batch: { batchSize: 5, batchInterval: 200 } } }
    },
    retryOnFail: true,
    maxTries: 3,
    waitBetweenTries: 1000,
    onError: 'continueRegularOutput',
    position: [1320, 520]
  },
  output: [{ Status: 0, Answer: [] }]
});

const summarizeDns = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Summarize DNS', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: SUMMARIZE_DNS }, position: [1540, 520] },
  output: [{ source: 'dns', dns: {} }]
});

const collect = merge({
  version: 3.2,
  config: { name: 'Collect Results', parameters: { mode: 'append', numberInputs: 3 }, position: [2200, 300] }
});

const assemble = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: { name: 'Assemble Result', parameters: { mode: 'runOnceForAllItems', language: 'javaScript', jsCode: ASSEMBLE }, position: [2420, 300] },
  output: [{ payload_json: '{}', callback_url: 'https://site.org/cb' }]
});

const signResult = node({
  type: 'n8n-nodes-base.crypto',
  version: 2,
  config: {
    name: 'Sign Result',
    parameters: { action: 'hmac', type: 'SHA256', value: expr('{{ $json.payload_json }}'), dataPropertyName: 'signature', encoding: 'hex' },
    credentials: { crypto: newCredential('Health Report HMAC') },
    position: [2640, 300]
  },
  output: [{ payload_json: '{}', callback_url: 'https://site.org/cb', signature: 'abc' }]
});

const postResult = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Post Result To WordPress',
    parameters: {
      method: 'POST',
      url: expr('{{ $json.callback_url }}'),
      sendHeaders: true,
      specifyHeaders: 'keypair',
      headerParameters: { parameters: [{ name: 'X-SHR-Signature', value: expr('sha256={{ $json.signature }}') }] },
      sendBody: true,
      contentType: 'raw',
      rawContentType: 'application/json',
      body: expr('{{ $json.payload_json }}'),
      options: { timeout: 20000 }
    },
    retryOnFail: true,
    maxTries: 3,
    waitBetweenTries: 5000,
    onError: 'continueErrorOutput',
    position: [2860, 300]
  },
  output: [{ ok: true }]
});

const deliveryFailed = node({
  type: 'n8n-nodes-base.stopAndError',
  version: 1,
  config: { name: 'Result Delivery Failed', parameters: { errorType: 'errorMessage', errorMessage: 'WordPress did not accept the scan result after 3 attempts.' }, position: [3080, 420] },
  output: [{}]
});

export default workflow('health-report-scan', 'Health Report - Scan Pipeline')
  .add(receiveJob)
  .to(computeJobSignature)
  .to(verifyJob)
  .to(jobValid.onTrue(respondAccepted).onFalse(respondRejected))
  .add(respondAccepted)
  .to(createScan.to(waitForScan).to(getScan).to(checkScan).to(scanDone.onTrue(collect.input(0)).onFalse(waitForScan)))
  .add(createScan.onError(scannerDown))
  .add(getScan.onError(scannerDown))
  .add(scannerDown)
  .to(collect.input(0))
  .add(respondAccepted)
  .to(pagespeed.to(extractPsi).to(collect.input(1)))
  .add(respondAccepted)
  .to(buildDns.to(queryDns).to(summarizeDns).to(collect.input(2)))
  .add(collect)
  .to(assemble)
  .to(signResult)
  .to(postResult)
  .add(postResult.onError(deliveryFailed));
