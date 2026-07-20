import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = __ENV.LOAD_BASE_URL ?? 'http://rpgays.test:8000';
const campaignId = '00000000-0000-7000-8000-000000000001';
const sessionId = '00000000-0000-7000-8000-000000000003';
const mapId = '00000000-0000-7000-8000-000000000004';
const presentationToken = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

export const options = {
    scenarios: {
        participants: { executor: 'per-vu-iterations', vus: 30, iterations: 1, maxDuration: '45s', exec: 'participant' },
        control: { executor: 'per-vu-iterations', vus: 1, iterations: 1, startTime: '4s', maxDuration: '30s', exec: 'control' },
        presentation: { executor: 'per-vu-iterations', vus: 1, iterations: 1, startTime: '1s', maxDuration: '20s', exec: 'presentation' },
    },
    thresholds: {
        checks: ['rate==1'],
        http_req_failed: ['rate==0'],
        'http_req_duration{latency_class:ordinary}': ['p(95)<250'],
    },
};

function csrfToken() {
    const cookies = http.cookieJar().cookiesForURL(baseUrl);
    const token = cookies['XSRF-TOKEN']?.[0];

    return token === undefined ? null : decodeURIComponent(token);
}

function openShell(path) {
    return http.get(`${baseUrl}${path}`, { tags: { request_type: 'shell' } });
}

function commandId() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const value = Math.floor(Math.random() * 16);

        return (character === 'x' ? value : (value & 0x3) | 0x8).toString(16);
    });
}

function request(method, path, body, expectedStatus, name, latencyClass = 'ordinary') {
    const headers = { Accept: 'application/json' };
    let payload;
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        const token = csrfToken();
        if (token !== null) headers['X-XSRF-TOKEN'] = token;
        payload = JSON.stringify(body);
    }

    const response = http.request(method, `${baseUrl}${path}`, payload, { headers, tags: { name, latency_class: latencyClass } });
    check(response, { [`${name} responds ${expectedStatus}`]: (result) => result.status === expectedStatus });

    return response;
}

export function participant() {
    const name = `Load player ${__VU}`;
    check(openShell('/player'), { 'Player shell loads before join': (response) => response.status === 200 });
    const join = request('POST', '/api/participant/v1/join', { player_code: 'LOADTEST', display_name: name, role: 'player' }, 201, 'participant_join');
    const resumeToken = join.json('data.resume_token');

    sleep(6 + Math.random() * 4);
    const polls = request('GET', '/api/participant/v1/polls', undefined, 200, 'participant_polls');
    const optionId = polls.json('data.0.options.0.id');
    check(polls, { 'Control poll is visible to every participant': () => typeof optionId === 'string' });
    request('POST', `/api/participant/v1/polls/${polls.json('data.0.id')}/vote`, { command_id: commandId(), option_ids: [optionId] }, 200, 'participant_vote', 'high_fanout');
    request('POST', '/api/participant/v1/messages', { command_id: commandId(), target_type: 'control', body: `Load message from ${name}` }, 201, 'participant_message', 'high_fanout');
    request('POST', '/api/participant/v1/rolls', { command_id: commandId(), expression: '1d20+2', visibility: 'public' }, 201, 'participant_roll', 'high_fanout');
    request('POST', '/api/participant/v1/resume', { resume_token: resumeToken }, 200, 'participant_resume');
    request('GET', '/api/participant/v1/roster', undefined, 200, 'participant_reconnect_read');
}

export function control() {
    check(openShell('/control'), { 'Control shell loads before login': (response) => response.status === 200 });
    request('POST', '/api/control/v1/auth/login', { secret: __ENV.CONTROL_SECRET ?? 'local-development-secret-change-before-production' }, 200, 'control_login');
    request('POST', `/api/control/v1/campaigns/${campaignId}/sessions/${sessionId}/polls`, { command_id: commandId(), question: 'Choose the route', options: ['North', 'South'], allows_multiple: false, target_type: 'all_players' }, 201, 'control_create_poll');
    request('POST', `/api/control/v1/campaigns/${campaignId}/sessions/${sessionId}/maps/${mapId}/progress/fog`, { command_id: commandId(), expected_revision: 1, mode: 'reveal', center_x: 0.5, center_y: 0.5, radius: 0.2 }, 200, 'control_fog_stroke');
}

export function presentation() {
    check(openShell('/presentation'), { 'Presentation shell loads before pairing': (response) => response.status === 200 });
    request('POST', '/api/presentation/v1/pair', { token: presentationToken }, 200, 'presentation_pair');
    request('GET', '/api/presentation/v1/state', undefined, 200, 'presentation_state');
}
