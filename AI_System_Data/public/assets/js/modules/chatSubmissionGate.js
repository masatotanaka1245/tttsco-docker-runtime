/**
 * One-operation gate for chat submission entry points.
 * The gate is intentionally DOM-free so it can be tested with node:test.
 */
export function createRequestId(randomUUID = globalThis.crypto?.randomUUID?.bind(globalThis.crypto)) {
    if (typeof randomUUID === 'function') {
        return randomUUID();
    }
    return `req-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
}

export function createChatSubmissionGate(createId = createRequestId) {
    let inFlight = false;

    return {
        begin() {
            if (inFlight) {
                return null;
            }
            inFlight = true;
            return createId();
        },
        finish() {
            inFlight = false;
        },
        isInFlight() {
            return inFlight;
        }
    };
}
