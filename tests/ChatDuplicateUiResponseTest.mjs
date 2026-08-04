import assert from 'node:assert/strict';
import test from 'node:test';
import { createChatResponseHandler } from '../AI_System_Data/public/assets/js/modules/chatResponseHandler.js';

function createHarness() {
    const state = { assistant: [], notices: 0, gateReleases: 0 };
    const handler = createChatResponseHandler({
        onDuplicateNotice: () => { state.notices += 1; },
        onGateRelease: () => { state.gateReleases += 1; }
    });
    return { state, handler };
}

test('HTTP 409 duplicate suppresses assistant output, notifies once, and releases the gate', () => {
    const { state, handler } = createHarness();
    assert.doesNotThrow(() => assert.equal(handler.handleHttpResponse(new Response('', { status: 409 })), true));
    handler.releaseGate();
    assert.deepEqual(state, { assistant: [], notices: 1, gateReleases: 1 });
});

test('duplicate SSE suppresses normal rendering and does not duplicate its notice', () => {
    const { state, handler } = createHarness();
    assert.equal(handler.handleHttpResponse(new Response('', { status: 200 })), false);
    assert.equal(handler.handleSsePayload({ type: 'result', status: 'error', error_code: 'CHAT_REQUEST_DUPLICATE' }), true);
    assert.equal(handler.handleSsePayload({ type: 'result', status: 'error', error_code: 'CHAT_REQUEST_DUPLICATE' }), true);
    handler.releaseGate();
    handler.releaseGate();
    assert.deepEqual(state, { assistant: [], notices: 1, gateReleases: 1 });
});

test('normal HTTP and SSE payloads stay on the existing response-rendering path', () => {
    const { state, handler } = createHarness();
    assert.equal(handler.handleHttpResponse(new Response('ok', { status: 200 })), false);
    const payload = { type: 'result', status: 'success', response: '通常回答' };
    assert.equal(handler.handleSsePayload(payload), false);
    state.assistant.push(payload.response); // chat.js owns normal rendering after false is returned.
    handler.releaseGate();
    assert.deepEqual(state, { assistant: ['通常回答'], notices: 0, gateReleases: 1 });
});

test('duplicate details never reach the assistant callback', () => {
    const { state, handler } = createHarness();
    const internalPayload = {
        type: 'result',
        status: 'error',
        error_code: 'CHAT_REQUEST_DUPLICATE',
        request_id: 'internal-request-id',
        lock_file: '/private/lock'
    };
    assert.equal(handler.handleSsePayload(internalPayload), true);
    handler.releaseGate();
    assert.deepEqual(state.assistant, []);
    assert.equal(state.notices, 1);
    assert.equal(state.gateReleases, 1);
});
