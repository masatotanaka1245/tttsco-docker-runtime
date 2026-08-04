/**
 * Keeps duplicate-response decisions independent from the chat DOM.
 * Normal responses return false so chat.js can continue its existing rendering.
 */
export function createChatResponseHandler({ onDuplicateNotice, onGateRelease } = {}) {
    let duplicateNotified = false;
    let gateReleased = false;

    const handleDuplicate = () => {
        if (!duplicateNotified) {
            duplicateNotified = true;
            onDuplicateNotice?.();
        }
        return true;
    };

    return {
        handleHttpResponse(response) {
            return response?.status === 409 ? handleDuplicate() : false;
        },
        handleSsePayload(payload) {
            return payload?.error_code === 'CHAT_REQUEST_DUPLICATE' ? handleDuplicate() : false;
        },
        releaseGate() {
            if (!gateReleased) {
                gateReleased = true;
                onGateRelease?.();
            }
        },
        isDuplicateHandled() {
            return duplicateNotified;
        }
    };
}
