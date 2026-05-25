import http from 'k6/http';
import { check, sleep } from 'k6';

// Run with:
//   k6 run -e TOKEN="<paste-jwt-here>" k6-load-test.js
//
// Install k6 on macOS:  brew install k6

export const options = {
  // Ramp up to 50 concurrent virtual users over 30s, hold for 1m, ramp down.
  stages: [
    { duration: '30s', target: 50 },
    { duration: '1m',  target: 50 },
    { duration: '10s', target: 0  },
  ],
  thresholds: {
    // Fail the test if >1% of requests error or p95 latency > 500ms
    http_req_failed:   ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
};

const TOKEN = __ENV.TOKEN;
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export default function () {
  const url = `${BASE_URL}/api/v1/transfers`;

  const payload = JSON.stringify({
    fromAccount: 'ACC-001',
    toAccount:   'ACC-002',
    amountMinor: '1',
    currency:    'USD',
  });

  // Unique idempotency key per virtual-user iteration
  const idempotencyKey = `k6-${__VU}-${__ITER}-${Date.now()}`;

  const params = {
    headers: {
      'Content-Type':    'application/json',
      'Authorization':   `Bearer ${TOKEN}`,
      'Idempotency-Key': idempotencyKey,
    },
  };

  const res = http.post(url, payload, params);

  check(res, {
    'status is 201':        (r) => r.status === 201,
    'response has txn id':  (r) => r.json('transactionId') !== undefined,
  });

  sleep(0.1);
}
