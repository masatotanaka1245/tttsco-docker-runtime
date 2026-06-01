/**
 * api.js - 共通の通信基盤と設定取得モジュール
 */

export const getConfig = () => {
    const configEl = document.querySelector('#support-config');
    return configEl ? configEl.dataset : { csrfToken: '', projectId: null };
};

export const secureFetch = async (url, options = {}) => {
    const { csrfToken } = getConfig();
    const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
        ...options.headers
    };
    
    // FormData 送信時はブラウザに Content-Type の設定を任せる
    if (options.body instanceof FormData && headers['Content-Type']) {
        delete headers['Content-Type'];
    }
    
    try {
        const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
        const data = await response.json().catch(() => null);

        if (!response.ok) {
            const errorMsg = data?.error || data?.message || `HTTP Error: ${response.status}`;
            throw new Error(errorMsg);
        }
        return data;
    } catch (error) {
        console.error('Fetch error:', error);
        throw error;
    }
};