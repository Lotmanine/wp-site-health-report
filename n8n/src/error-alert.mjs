import { workflow, node, trigger, expr } from '@n8n/workflow-sdk';

const onError = trigger({
  type: 'n8n-nodes-base.errorTrigger',
  version: 1,
  config: { name: 'Pipeline Failed', parameters: {}, position: [0, 0] },
  output: [{ workflow: { name: 'Health Report - Scan Pipeline' }, execution: { id: '1', lastNodeExecuted: 'Post Result To WordPress', error: { message: 'failed' } } }]
});

const alert = node({
  type: 'n8n-nodes-base.telegram',
  version: 1.2,
  config: {
    name: 'Alert Operator',
    parameters: {
      resource: 'message',
      operation: 'sendMessage',
      chatId: 'REPLACE_WITH_TELEGRAM_CHAT_ID',
      text: expr('Health Report pipeline failed\nWorkflow: {{ $json.workflow.name }}\nNode: {{ $json.execution.lastNodeExecuted }}\nExecution: {{ $json.execution.id }}\nError: {{ ($json.execution.error.message || "").slice(0, 300) }}'),
      additionalFields: { appendAttribution: false }
    },
    retryOnFail: true,
    maxTries: 3,
    waitBetweenTries: 3000,
    position: [260, 0]
  },
  output: [{ ok: true }]
});

export default workflow('health-report-error-alert', 'Health Report - Error Alert').add(onError).to(alert);
