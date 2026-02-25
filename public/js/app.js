/**
 * DevRouter 管理 UI
 */

// --- 状態管理 ---
const state = {
    baseDomains: [],
    groups: [],
    routes: [],
    warning: null,
    currentDomain: null,
};

// --- API ヘルパー ---
async function api(endpoint, method = 'GET', body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
    };
    if (body) opts.body = JSON.stringify(body);

    const res = await fetch(`/api/${endpoint}`, opts);
    const data = await res.json();

    if (!res.ok) {
        throw new Error(data.error || `API エラー (${res.status})`);
    }
    return data;
}

// --- 初期読み込み ---
async function init() {
    setupTabs();

    try {
        await Promise.all([
            loadDomains(),
            loadGroups(),
            loadRoutes(),
        ]);
        renderAll();
    } catch (e) {
        showToast('初期読み込みに失敗しました: ' + e.message);
    }
}

// --- タブ切替 ---
function setupTabs() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');

            // 環境チェックタブを開いたときにロード
            if (btn.dataset.tab === 'tab-env') {
                loadEnvCheck();
            }
        });
    });
}

// --- データ読み込み ---
async function loadDomains() {
    const data = await api('domains.php');
    state.baseDomains = data.baseDomains;
    if (data.warning) state.warning = data.warning;
    state.currentDomain = state.baseDomains.find(d => d.current)?.domain || null;
}

async function loadGroups() {
    const data = await api('groups.php');
    state.groups = data.groups;
    if (data.warning) state.warning = data.warning;
}

async function loadRoutes() {
    const data = await api('routes.php');
    state.routes = data.routes;
    if (data.warning) state.warning = data.warning;
}

// --- レンダリング ---
function renderAll() {
    renderWarnings();
    renderDashboard();
    renderDomains();
    renderGroups();
    renderRoutes();
}

function renderWarnings() {
    const el = document.getElementById('warning-banner');
    // スラグ衝突の検出
    const conflicts = [];
    for (const group of state.groups) {
        for (const sub of (group.subdirs || [])) {
            if (sub.status === 'shadowed') {
                conflicts.push(`${sub.slug}/ → ${group.path}（${sub.conflictWith} が優先）`);
            }
        }
    }

    if (conflicts.length > 0) {
        el.innerHTML = '<strong>スラグ衝突:</strong><br>' +
            conflicts.map(c => '  ' + c).join('<br>');
        el.classList.add('visible');
    } else {
        el.classList.remove('visible');
    }

    // routes.json の警告
    const warnEl = document.getElementById('state-warning');
    if (state.warning) {
        warnEl.textContent = state.warning;
        warnEl.classList.add('visible');
    } else {
        warnEl.classList.remove('visible');
    }
}

// --- ダッシュボード ---
function renderDashboard() {
    const tbody = document.getElementById('dashboard-routes');
    const allEntries = [];

    // 明示登録ルート
    for (const route of state.routes) {
        allEntries.push({
            slug: route.slug,
            target: route.target,
            type: route.type === 'proxy' ? 'プロキシ' : 'ディレクトリ',
            source: '明示登録',
        });
    }

    // グループ解決ルート
    for (const group of state.groups) {
        for (const sub of (group.subdirs || [])) {
            if (sub.status === 'active') {
                allEntries.push({
                    slug: sub.slug,
                    target: sub.target,
                    type: 'ディレクトリ',
                    source: group.path.split('/').pop(),
                });
            }
        }
    }

    if (allEntries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty">ルートが登録されていません</td></tr>';
        return;
    }

    tbody.innerHTML = allEntries.map(entry => {
        const domain = state.currentDomain || '127.0.0.1.nip.io';
        const url = `http://${entry.slug}.${domain}`;
        return `<tr>
            <td>
                <a href="${url}" target="_blank" class="url-link">${entry.slug}.${domain}</a>
                <button class="copy-btn" onclick="copyUrl('${url}', this)">copy</button>
            </td>
            <td><code>${truncate(entry.target, 50)}</code></td>
            <td><span class="badge badge-type">${entry.type}</span></td>
            <td><span style="font-size:0.8rem;color:var(--color-text-muted)">${entry.source}</span></td>
        </tr>`;
    }).join('');
}

// --- ベースドメイン管理 ---
function renderDomains() {
    const tbody = document.getElementById('domains-list');

    if (state.baseDomains.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty">ベースドメインが登録されていません</td></tr>';
        return;
    }

    tbody.innerHTML = state.baseDomains.map(bd => `<tr>
        <td>
            ${bd.domain}
            ${bd.current ? '<span class="badge badge-current">current</span>' : ''}
            ${bd.ssl ? '<span class="badge badge-ssl">SSL</span>' : ''}
        </td>
        <td class="actions">
            ${!bd.current ? `<button class="btn btn-sm btn-secondary" onclick="setCurrent('${bd.domain}')">current に設定</button>` : ''}
            ${!bd.current ? `<button class="btn btn-sm btn-danger" onclick="deleteDomain('${bd.domain}')">削除</button>` : ''}
        </td>
    </tr>`).join('');
}

async function addDomain() {
    const input = document.getElementById('new-domain');
    const domain = input.value.trim();
    if (!domain) return;

    try {
        const data = await api('domains.php', 'POST', { domain });
        state.baseDomains = data.baseDomains;
        state.currentDomain = state.baseDomains.find(d => d.current)?.domain || null;
        input.value = '';
        renderAll();
        showToast('ベースドメインを追加しました');
    } catch (e) {
        showToast(e.message);
    }
}

async function setCurrent(domain) {
    try {
        const data = await api('domains.php', 'PUT', { domain });
        state.baseDomains = data.baseDomains;
        state.currentDomain = domain;
        renderAll();
        showToast(`${domain} を current に設定しました`);
    } catch (e) {
        showToast(e.message);
    }
}

async function deleteDomain(domain) {
    if (!confirm(`ベースドメイン "${domain}" を削除しますか？`)) return;

    try {
        const data = await api('domains.php', 'DELETE', { domain });
        state.baseDomains = data.baseDomains;
        state.currentDomain = state.baseDomains.find(d => d.current)?.domain || null;
        renderAll();
        showToast('ベースドメインを削除しました');
    } catch (e) {
        showToast(e.message);
    }
}

// --- グループ管理 ---
function renderGroups() {
    const container = document.getElementById('groups-list');

    if (state.groups.length === 0) {
        container.innerHTML = '<div class="empty">グループが登録されていません</div>';
        return;
    }

    container.innerHTML = state.groups.map((group, i) => `
        <div class="card" data-index="${i}" draggable="true"
             ondragstart="dragStart(event)" ondragover="dragOver(event)"
             ondrop="dropGroup(event)" ondragend="dragEnd(event)">
            <div class="card-header">
                <span>
                    <span class="drag-handle">&#x2630;</span>
                    ${group.path}
                    ${!group.exists ? '<span class="badge" style="background:#fef2f2;color:#991b1b">存在しません</span>' : ''}
                </span>
                <button class="btn btn-sm btn-danger" onclick="deleteGroup('${escapeHtml(group.path)}')">削除</button>
            </div>
            ${(group.subdirs && group.subdirs.length > 0) || (group.skipped && group.skipped.length > 0) ? `
            <div class="card-body">
                <div class="group-subdirs">
                    ${(group.subdirs || []).map(sub => {
                        const domain = state.currentDomain || '127.0.0.1.nip.io';
                        const url = `http://${sub.slug}.${domain}`;
                        return `<div class="subdir-item">
                            <span class="badge ${sub.status === 'active' ? 'badge-active' : 'badge-shadowed'}">${sub.status === 'active' ? '有効' : '衝突'}</span>
                            <a href="${url}" target="_blank" class="url-link">${sub.slug}</a>
                            ${sub.conflictWith ? `<span style="font-size:0.8rem;color:var(--color-text-muted)">← ${sub.conflictWith} が優先</span>` : ''}
                        </div>`;
                    }).join('')}
                    ${(group.skipped || []).map(s => `<div class="subdir-item">
                        <span class="badge" style="background:#f3f4f6;color:var(--color-text-muted)">対象外</span>
                        <span style="color:var(--color-text-muted)">${s.name}/</span>
                        <span style="font-size:0.75rem;color:var(--color-text-muted)">← ${s.reason}</span>
                    </div>`).join('')}
                </div>
            </div>` : ''}
        </div>
    `).join('');
}

async function addGroup() {
    const input = document.getElementById('new-group-path');
    const path = input.value.trim();
    if (!path) return;

    try {
        const data = await api('groups.php', 'POST', { path });
        state.groups = data.groups;
        input.value = '';
        renderAll();
        showToast('グループを追加しました');
    } catch (e) {
        showToast(e.message);
    }
}

async function deleteGroup(path) {
    if (!confirm(`グループ "${path}" を削除しますか？`)) return;

    try {
        const data = await api('groups.php', 'DELETE', { path });
        state.groups = data.groups;
        renderAll();
        showToast('グループを削除しました');
    } catch (e) {
        showToast(e.message);
    }
}

// グループのドラッグ&ドロップ（優先順位変更）
let dragIndex = null;

function dragStart(e) {
    dragIndex = parseInt(e.currentTarget.dataset.index);
    e.currentTarget.style.opacity = '0.5';
}

function dragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}

function dragEnd(e) {
    e.currentTarget.style.opacity = '';
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
}

async function dropGroup(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    const dropIndex = parseInt(e.currentTarget.dataset.index);

    if (dragIndex === null || dragIndex === dropIndex) return;

    // 順序を入れ替え
    const order = state.groups.map(g => g.path);
    const [moved] = order.splice(dragIndex, 1);
    order.splice(dropIndex, 0, moved);

    try {
        const data = await api('groups.php', 'PUT', { order });
        state.groups = data.groups;
        renderAll();
        showToast('グループの優先順位を変更しました');
    } catch (e) {
        showToast(e.message);
    }

    dragIndex = null;
}

async function scanGroups() {
    try {
        const data = await api('scan.php', 'POST');
        state.groups = data.groups;
        renderAll();
        showToast('スキャン完了');
    } catch (e) {
        showToast(e.message);
    }
}

// --- ルート管理 ---
function renderRoutes() {
    const tbody = document.getElementById('routes-list');

    if (state.routes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty">ルートが登録されていません</td></tr>';
        return;
    }

    tbody.innerHTML = state.routes.map(route => {
        const domain = state.currentDomain || '127.0.0.1.nip.io';
        const url = `http://${route.slug}.${domain}`;
        return `<tr>
            <td>
                <a href="${url}" target="_blank" class="url-link">${route.slug}</a>
                <button class="copy-btn" onclick="copyUrl('${url}', this)">copy</button>
            </td>
            <td><code>${truncate(route.target, 50)}</code></td>
            <td><span class="badge badge-type">${route.type === 'proxy' ? 'プロキシ' : 'ディレクトリ'}</span></td>
            <td>
                <button class="btn btn-sm btn-danger" onclick="deleteRoute('${route.slug}')">削除</button>
            </td>
        </tr>`;
    }).join('');
}

async function addRoute() {
    const slug = document.getElementById('new-route-slug').value.trim();
    const target = document.getElementById('new-route-target').value.trim();
    const type = document.getElementById('new-route-type').value;
    if (!slug || !target) return;

    try {
        const data = await api('routes.php', 'POST', { slug, target, type });
        state.routes = data.routes;
        document.getElementById('new-route-slug').value = '';
        document.getElementById('new-route-target').value = '';
        renderAll();
        showToast('ルートを追加しました');
    } catch (e) {
        showToast(e.message);
    }
}

async function deleteRoute(slug) {
    if (!confirm(`ルート "${slug}" を削除しますか？`)) return;

    try {
        const data = await api('routes.php', 'DELETE', { slug });
        state.routes = data.routes;
        renderAll();
        showToast('ルートを削除しました');
    } catch (e) {
        showToast(e.message);
    }
}

// --- 環境チェック ---
async function loadEnvCheck() {
    const container = document.getElementById('env-checks');
    container.innerHTML = '<div class="empty">読み込み中...</div>';

    try {
        const data = await api('env-check.php');
        let html = '';
        let prevCategory = '';

        for (const check of data.checks) {
            if (check.category !== prevCategory && check.category === 'optional') {
                html += '<div class="check-separator">── オプション ──</div>';
            }
            prevCategory = check.category;

            let icon;
            if (check.status === 'ok') icon = '<span style="color:var(--color-success)">&#x2705;</span>';
            else if (check.status === 'warning') icon = '<span style="color:var(--color-warning)">&#x26A0;</span>';
            else icon = '<span style="color:var(--color-danger)">&#x274C;</span>';

            html += `<div class="check-item">
                <span class="check-icon">${icon}</span>
                <span class="check-name">${check.name}</span>
                ${check.command ? `<span class="check-command">${check.command}</span>` : ''}
            </div>`;
        }

        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = `<div class="empty">環境チェックに失敗しました: ${e.message}</div>`;
    }
}

// --- ユーティリティ ---
function copyUrl(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        btn.textContent = 'copied!';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.textContent = 'copy';
            btn.classList.remove('copied');
        }, 1500);
    });
}

function truncate(str, len) {
    return str.length > len ? str.substring(0, len) + '...' : str;
}

function escapeHtml(str) {
    return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

let toastTimer;
function showToast(message) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('visible'), 3000);
}

// --- 起動 ---
document.addEventListener('DOMContentLoaded', init);
