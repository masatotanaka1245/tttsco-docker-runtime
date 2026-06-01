/**
 * map.js - Leaflet.js を用いた地図および座標操作モジュール
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 * ★実績ある 150ms 遅延コンテナ生成仕様 ＆ 通信基盤一本化 統合版
 */
import { secureFetch } from './api.js?v=4';

// =========================================================================
// 1. マップ・座標ロジック（親ファイルの実体仕様を100%忠実に無加工移設）
// =========================================================================

function searchAddress(type) {
    const addressInput = document.getElementById(`${type}-project-address`);
    if (!addressInput || !addressInput.value.trim()) return alert('住所を入力してください。');
    
    secureFetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(addressInput.value)}`, { method: 'GET', headers: {} })
        .then(data => {
            if (data && data.length > 0) {
                const latInput = document.getElementById(`${type}-lat`);
                const lngInput = document.getElementById(`${type}-lng`);
                if (latInput) latInput.value = data[0].lat;
                if (lngInput) lngInput.value = data[0].lon;
                alert('座標を取得しました！マップを確認してください。');
            } else {
                alert('指定された住所の座標が見つかりませんでした。');
            }
        }).catch(err => alert('座標の検索に失敗しました。'));
}

function copyCoords(type) {
    const lat = document.getElementById(`${type}-lat`)?.value;
    const lng = document.getElementById(`${type}-lng`)?.value;
    if (lat && lng) {
        navigator.clipboard.writeText(`${lat}, ${lng}`)
            .then(() => alert('座標をクリップボードにコピーしました。'))
            .catch(err => console.error('Copy failed:', err));
    }
}

function initModalMap(containerId, latId, lngId, defaultLat, defaultLng) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = `<div id="${containerId}-map" class="w-full h-full rounded-xl"></div>`;
    
    setTimeout(() => {
        if (typeof L === 'undefined') return;
        const lat = defaultLat || 35.681236;
        const lng = defaultLng || 139.767125;
        const map = L.map(containerId + '-map').setView([lat, lng], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
        
        let marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        
        marker.on('dragend', function (e) {
            const pos = marker.getLatLng();
            document.getElementById(latId).value = pos.lat.toFixed(6);
            document.getElementById(lngId).value = pos.lng.toFixed(6);
        });
        
        map.on('click', function (e) {
            marker.setLatLng(e.latlng);
            document.getElementById(latId).value = e.latlng.lat.toFixed(6);
            document.getElementById(lngId).value = e.latlng.lng.toFixed(6);
        });
        
        map.invalidateSize();
    }, 150);
}

// =========================================================================
// ★[究極の安全設計] グローバルへの確実なバインドレイヤー
// =========================================================================
(function initGlobalMapBindings() {
    window.searchAddress = searchAddress;
    window.copyCoords = copyCoords;
    window.initModalMap = initModalMap;
})();

// ★インラインexportを全廃し、最末尾一本化一括出荷
export { searchAddress, copyCoords, initModalMap };