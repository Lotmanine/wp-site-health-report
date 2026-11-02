// Builds importable workflow JSON. Run in the n8n image, which ships the SDK: see scripts/build-workflows.sh
import { writeFileSync } from 'node:fs';
import pipeline from './src/scan-pipeline.mjs';
import alert from './src/error-alert.mjs';

for (const [file, wf] of [['health-report-scan-pipeline.json', pipeline], ['health-report-error-alert.json', alert]]) {
  const json = wf.toJSON();
  json.settings = { ...json.settings, executionOrder: 'v1', saveDataSuccessExecution: 'none', timezone: 'UTC', executionTimeout: 300 };
  writeFileSync(file, JSON.stringify(json, null, 2) + '\n');
  console.log(file, json.nodes.length, 'nodes');
}
