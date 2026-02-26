/**
 * DevRouter 管理 UI
 * @module app
 */

// --- 定数 ---
const TOAST_DURATION = 3000;
const COPY_FEEDBACK_DURATION = 1500;
const DEFAULT_DOMAIN = "127.0.0.1.nip.io";
const MAX_TARGET_LENGTH = 50;
const DEBOUNCE_MS = 200;
const AUTOCOMPLETE_MAX_ITEMS = 50;

// --- 状態管理 ---
const state = {
  baseDomains: [],
  groups: [],
  routes: [],
  warning: null,
  currentDomain: null,
  userHome: null,
};

// --- DOM要素の参照 ---
const ui = {
  stateWarning: document.querySelector("[data-js-state-warning]"),
  warningBanner: document.querySelector("[data-js-warning-banner]"),
  dashboardRoutes: document.querySelector("[data-js-dashboard-routes]"),
  groupsList: document.querySelector("[data-js-groups-list]"),
  routesList: document.querySelector("[data-js-routes-list]"),
  domainsList: document.querySelector("[data-js-domains-list]"),
  envChecks: document.querySelector("[data-js-env-checks]"),
  toast: document.querySelector("[data-js-toast]"),
  addGroupForm: document.querySelector("[data-js-add-group-form]"),
  addRouteForm: document.querySelector("[data-js-add-route-form]"),
  addDomainForm: document.querySelector("[data-js-add-domain-form]"),
};

// --- API ヘルパー ---

/**
 * APIリクエストを送信する
 * @param {string} endpoint エンドポイント
 * @param {string} method HTTPメソッド
 * @param {Object|null} body リクエストボディ
 * @returns {Promise<Object>} レスポンスデータ
 */
const api = async (endpoint, method = "GET", body = null) => {
  const options = {
    method,
    headers: { "Content-Type": "application/json" },
  };
  if (body) {
    options.body = JSON.stringify(body);
  }

  const response = await fetch(`/api/${endpoint}`, options);
  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.error ?? `API エラー (${response.status})`);
  }
  return data;
};

// --- タブ切替 ---

/**
 * 指定されたタブをアクティブにする
 * @param {HTMLElement} selectedTab アクティブにするタブ要素
 */
const activateTab = (selectedTab) => {
  document.querySelectorAll("[data-js-tabs] [role='tab']").forEach((tab) => {
    tab.setAttribute("aria-selected", "false");
    tab.setAttribute("tabindex", "-1");
  });

  selectedTab.setAttribute("aria-selected", "true");
  selectedTab.setAttribute("tabindex", "0");

  document.querySelectorAll("[role='tabpanel']").forEach((panel) => {
    panel.classList.remove("active");
  });

  const panelId = selectedTab.getAttribute("aria-controls");
  const panel = document.getElementById(panelId);
  if (panel) {
    panel.classList.add("active");
  }

  if (selectedTab.dataset.jsTab === "env") {
    loadEnvCheck();
  }
};

const setupTabs = () => {
  const tablist = document.querySelector("[data-js-tabs]");

  tablist.addEventListener("click", (event) => {
    const tab = event.target.closest("[role='tab']");
    if (!tab) return;
    activateTab(tab);
    tab.focus();
  });

  tablist.addEventListener("keydown", (event) => {
    const currentTab = event.target.closest("[role='tab']");
    if (!currentTab) return;

    const allTabs = [...tablist.querySelectorAll("[role='tab']")];
    const currentIndex = allTabs.indexOf(currentTab);
    let nextIndex;

    switch (event.key) {
      case "ArrowRight":
        nextIndex = (currentIndex + 1) % allTabs.length;
        break;
      case "ArrowLeft":
        nextIndex = (currentIndex - 1 + allTabs.length) % allTabs.length;
        break;
      case "Home":
        nextIndex = 0;
        break;
      case "End":
        nextIndex = allTabs.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    activateTab(allTabs[nextIndex]);
    allTabs[nextIndex].focus();
  });
};

// --- データ読み込み ---

const loadDomains = async () => {
  const data = await api("domains");
  state.baseDomains = data.baseDomains;
  if (data.warning) {
    state.warning = data.warning;
  }
  state.currentDomain = state.baseDomains.find((entry) => entry.current)?.domain ?? null;
};

const loadGroups = async () => {
  const data = await api("groups");
  state.groups = data.groups;
  if (data.warning) {
    state.warning = data.warning;
  }
};

const loadRoutes = async () => {
  const data = await api("routes");
  state.routes = data.routes;
  if (data.warning) {
    state.warning = data.warning;
  }
};

// --- レンダリング ---

const renderAll = () => {
  renderWarnings();
  renderDashboard();
  renderDomains();
  renderGroups();
  renderRoutes();
};

const renderWarnings = () => {
  const conflicts = [];

  for (const group of state.groups) {
    for (const subdir of (group.subdirs ?? [])) {
      if (subdir.status === "shadowed") {
        conflicts.push(
          `${escapeAttr(subdir.slug)}/ → ${escapeAttr(group.path)}（${escapeAttr(subdir.conflictWith)} が優先）`
        );
      }
    }
  }

  if (conflicts.length > 0) {
    ui.warningBanner.innerHTML =
      `<strong>スラグ衝突:</strong><br>${conflicts.map((conflict) => `  ${conflict}`).join("<br>")}`;
    ui.warningBanner.classList.add("visible");
  } else {
    ui.warningBanner.classList.remove("visible");
  }

  if (state.warning) {
    ui.stateWarning.textContent = state.warning;
    ui.stateWarning.classList.add("visible");
  } else {
    ui.stateWarning.classList.remove("visible");
  }
};

// --- ダッシュボード ---

const renderDashboard = () => {
  const allEntries = [];

  for (const route of state.routes) {
    allEntries.push({
      slug: route.slug,
      target: route.target,
      type: route.type === "proxy" ? "プロキシ" : "ディレクトリ",
      source: "明示登録",
    });
  }

  for (const group of state.groups) {
    for (const subdir of (group.subdirs ?? [])) {
      if (subdir.status === "active") {
        allEntries.push({
          slug: subdir.slug,
          target: subdir.target,
          type: "ディレクトリ",
          source: group.path.split("/").pop(),
        });
      }
    }
  }

  if (allEntries.length === 0) {
    ui.dashboardRoutes.innerHTML =
      `<tr><td colspan="4" class="empty">ルートが登録されていません</td></tr>`;
    return;
  }

  ui.dashboardRoutes.innerHTML = allEntries.map((entry) => {
    const domain = state.currentDomain ?? DEFAULT_DOMAIN;
    const url = `http://${entry.slug}.${domain}`;
    return `<tr>
      <td>
        <a href="${escapeAttr(url)}" target="_blank" rel="noopener" class="url">${escapeAttr(entry.slug)}.${escapeAttr(domain)}</a>
        <button type="button" class="copy" data-js-copy="${escapeAttr(url)}" aria-label="${escapeAttr(entry.slug)}.${escapeAttr(domain)} のURLをコピー">copy</button>
      </td>
      <td><code>${escapeAttr(truncate(entry.target, MAX_TARGET_LENGTH))}</code></td>
      <td><span class="badge type">${entry.type}</span></td>
      <td class="text-meta">${escapeAttr(entry.source)}</td>
    </tr>`;
  }).join("");
};

// --- ベースドメイン管理 ---

const renderDomains = () => {
  if (state.baseDomains.length === 0) {
    ui.domainsList.innerHTML =
      `<tr><td colspan="2" class="empty">ベースドメインが登録されていません</td></tr>`;
    return;
  }

  ui.domainsList.innerHTML = state.baseDomains.map((baseDomain) => `<tr>
    <td>
      ${escapeAttr(baseDomain.domain)}
      ${baseDomain.current ? `<span class="badge current">current</span>` : ""}
      ${baseDomain.ssl ? `<span class="badge ssl">SSL</span>` : ""}
    </td>
    <td class="actions">
      ${!baseDomain.current ? `<button type="button" class="btn sm secondary" data-js-set-current="${escapeAttr(baseDomain.domain)}">current に設定</button>` : ""}
      ${!baseDomain.current ? `<button type="button" class="btn sm danger" data-js-delete-domain="${escapeAttr(baseDomain.domain)}">削除</button>` : ""}
    </td>
  </tr>`).join("");
};

/**
 * ベースドメインを追加する
 */
const addDomain = async () => {
  const input = ui.addDomainForm.querySelector(`[name="domain"]`);
  const domain = input.value.trim();
  if (!domain) return;

  try {
    const data = await api("domains", "POST", { domain });
    state.baseDomains = data.baseDomains;
    state.currentDomain = state.baseDomains.find((entry) => entry.current)?.domain ?? null;
    input.value = "";
    renderAll();
    showToast("ベースドメインを追加しました");
  } catch (error) {
    showToast(error.message);
  }
};

/**
 * ベースドメインをcurrentに設定する
 * @param {string} domain 対象ドメイン
 */
const setCurrent = async (domain) => {
  try {
    const data = await api("domains", "PUT", { domain });
    state.baseDomains = data.baseDomains;
    state.currentDomain = domain;
    renderAll();
    showToast(`${domain} を current に設定しました`);
  } catch (error) {
    showToast(error.message);
  }
};

/**
 * ベースドメインを削除する
 * @param {string} domain 対象ドメイン
 */
const deleteDomain = async (domain) => {
  if (!confirm(`ベースドメイン "${domain}" を削除しますか？`)) return;

  try {
    const data = await api("domains", "DELETE", { domain });
    state.baseDomains = data.baseDomains;
    state.currentDomain = state.baseDomains.find((entry) => entry.current)?.domain ?? null;
    renderAll();
    showToast("ベースドメインを削除しました");
  } catch (error) {
    showToast(error.message);
  }
};

// --- グループ管理 ---

const renderGroups = () => {
  if (state.groups.length === 0) {
    ui.groupsList.innerHTML = `<p class="empty">グループが登録されていません</p>`;
    return;
  }

  ui.groupsList.innerHTML = state.groups.map((group, index) => {
    const domain = state.currentDomain ?? DEFAULT_DOMAIN;
    const hasContent = (group.subdirs?.length > 0) || (group.skipped?.length > 0);

    return `
      <div class="card" data-index="${index}" draggable="true">
        <header>
          <h4>
            <span class="handle" aria-hidden="true">&#x2630;</span>
            ${escapeAttr(group.path)}
            ${!group.exists ? `<span class="badge error">存在しません</span>` : ""}
          </h4>
          <div class="actions">
            ${index > 0 ? `<button type="button" class="btn sm secondary" data-js-move-up="${index}" aria-label="${escapeAttr(group.path)} を上へ移動">↑</button>` : ""}
            ${index < state.groups.length - 1 ? `<button type="button" class="btn sm secondary" data-js-move-down="${index}" aria-label="${escapeAttr(group.path)} を下へ移動">↓</button>` : ""}
            <button type="button" class="btn sm danger" data-js-delete-group="${escapeAttr(group.path)}">削除</button>
          </div>
        </header>
        ${hasContent ? `
        <div class="body">
          <ul class="subdirs">
            ${(group.subdirs ?? []).map((subdir) => {
              const url = `http://${subdir.slug}.${domain}`;
              return `<li>
                <span class="badge ${subdir.status === "active" ? "active" : "shadowed"}">${subdir.status === "active" ? "有効" : "衝突"}</span>
                <a href="${escapeAttr(url)}" target="_blank" rel="noopener" class="url">${escapeAttr(subdir.slug)}</a>
                ${subdir.conflictWith ? `<span class="text-meta">← ${escapeAttr(subdir.conflictWith)} が優先</span>` : ""}
              </li>`;
            }).join("")}
            ${(group.skipped ?? []).map((skippedItem) => `<li>
              <span class="badge type">対象外</span>
              <span class="text-muted">${escapeAttr(skippedItem.name)}/</span>
              <span class="text-meta">← ${escapeAttr(skippedItem.reason)}</span>
            </li>`).join("")}
          </ul>
        </div>` : ""}
      </div>
    `;
  }).join("");
};

/**
 * グループを追加する
 */
const addGroup = async () => {
  const input = ui.addGroupForm.querySelector(`[name="path"]`);
  const path = input.value.trim().replace(/\/+$/, "");
  if (!path) return;

  try {
    const data = await api("groups", "POST", { path });
    state.groups = data.groups;
    input.value = state.userHome ? state.userHome + "/" : "";
    renderAll();
    showToast("グループを追加しました");
  } catch (error) {
    showToast(error.message);
  }
};

/**
 * グループを削除する
 * @param {string} path グループのパス
 */
const deleteGroup = async (path) => {
  if (!confirm(`グループ "${path}" を削除しますか？`)) return;

  try {
    const data = await api("groups", "DELETE", { path });
    state.groups = data.groups;
    renderAll();
    showToast("グループを削除しました");
  } catch (error) {
    showToast(error.message);
  }
};

// --- グループの並べ替え ---

/**
 * グループの優先順位を変更する
 * @param {number} fromIndex 移動元インデックス
 * @param {number} toIndex 移動先インデックス
 */
const moveGroup = async (fromIndex, toIndex) => {
  const order = state.groups.map((group) => group.path);
  const [moved] = order.splice(fromIndex, 1);
  order.splice(toIndex, 0, moved);

  try {
    const data = await api("groups", "PUT", { order });
    state.groups = data.groups;
    renderAll();
    showToast("グループの優先順位を変更しました");
  } catch (error) {
    showToast(error.message);
  }
};

let dragIndex = null;

const setupGroupDragDrop = () => {
  ui.groupsList.addEventListener("dragstart", (event) => {
    const card = event.target.closest("[data-index]");
    if (!card) return;
    dragIndex = parseInt(card.dataset.index);
    card.style.opacity = "0.5";
  });

  ui.groupsList.addEventListener("dragover", (event) => {
    event.preventDefault();
    const card = event.target.closest("[data-index]");
    if (card) {
      card.classList.add("drag-over");
    }
  });

  ui.groupsList.addEventListener("dragleave", (event) => {
    const card = event.target.closest("[data-index]");
    if (card && !card.contains(event.relatedTarget)) {
      card.classList.remove("drag-over");
    }
  });

  ui.groupsList.addEventListener("dragend", () => {
    ui.groupsList.querySelectorAll("[data-index]").forEach((element) => {
      element.style.opacity = "";
      element.classList.remove("drag-over");
    });
  });

  ui.groupsList.addEventListener("drop", async (event) => {
    event.preventDefault();
    const card = event.target.closest("[data-index]");
    if (!card) return;
    card.classList.remove("drag-over");

    const dropIndex = parseInt(card.dataset.index);
    if (dragIndex === null || dragIndex === dropIndex) return;

    await moveGroup(dragIndex, dropIndex);
    dragIndex = null;
  });
};

/**
 * グループをスキャンして更新する
 */
const scanGroups = async () => {
  try {
    const data = await api("scan", "POST");
    state.groups = data.groups;
    renderAll();
    showToast("スキャン完了");
  } catch (error) {
    showToast(error.message);
  }
};

// --- ルート管理 ---

const renderRoutes = () => {
  if (state.routes.length === 0) {
    ui.routesList.innerHTML =
      `<tr><td colspan="4" class="empty">ルートが登録されていません</td></tr>`;
    return;
  }

  ui.routesList.innerHTML = state.routes.map((route) => {
    const domain = state.currentDomain ?? DEFAULT_DOMAIN;
    const url = `http://${route.slug}.${domain}`;
    return `<tr>
      <td>
        <a href="${escapeAttr(url)}" target="_blank" rel="noopener" class="url">${escapeAttr(route.slug)}</a>
        <button type="button" class="copy" data-js-copy="${escapeAttr(url)}" aria-label="${escapeAttr(route.slug)} のURLをコピー">copy</button>
      </td>
      <td><code>${escapeAttr(truncate(route.target, MAX_TARGET_LENGTH))}</code></td>
      <td><span class="badge type">${route.type === "proxy" ? "プロキシ" : "ディレクトリ"}</span></td>
      <td>
        <button type="button" class="btn sm danger" data-js-delete-route="${escapeAttr(route.slug)}">削除</button>
      </td>
    </tr>`;
  }).join("");
};

/**
 * ルートを追加する
 */
const addRoute = async () => {
  const slugInput = ui.addRouteForm.querySelector(`[name="slug"]`);
  const targetInput = ui.addRouteForm.querySelector(`[name="target"]`);
  const typeSelect = ui.addRouteForm.querySelector(`[name="type"]`);
  const slug = slugInput.value.trim();
  const target = targetInput.value.trim().replace(/\/+$/, "");
  const type = typeSelect.value;
  if (!slug || !target) return;

  try {
    const data = await api("routes", "POST", { slug, target, type });
    state.routes = data.routes;
    slugInput.value = "";
    targetInput.value = type === "directory" && state.userHome ? state.userHome + "/" : "";
    renderAll();
    showToast("ルートを追加しました");
  } catch (error) {
    showToast(error.message);
  }
};

/**
 * ルートを削除する
 * @param {string} slug 対象スラグ
 */
const deleteRoute = async (slug) => {
  if (!confirm(`ルート "${slug}" を削除しますか？`)) return;

  try {
    const data = await api("routes", "DELETE", { slug });
    state.routes = data.routes;
    renderAll();
    showToast("ルートを削除しました");
  } catch (error) {
    showToast(error.message);
  }
};

// --- 環境チェック ---

const loadEnvCheck = async () => {
  ui.envChecks.innerHTML = `<p class="empty">読み込み中...</p>`;

  const sections = [
    { key: "required",  label: "必須" },
    { key: "proxy",     label: "プロキシ（リバースプロキシ使用時）" },
    { key: "websocket", label: "WebSocket（HMR 使用時）" },
    { key: "ssl",       label: "SSL（HTTPS 使用時）" },
  ];

  try {
    const data = await api("env-check");
    const grouped = {};
    for (const s of sections) grouped[s.key] = [];

    for (const check of data.checks) {
      let icon;
      if (check.status === "ok") {
        icon = `<span style="color:var(--color-success)">&#x2705;</span>`;
      } else if (check.status === "warning") {
        icon = `<span style="color:var(--color-warning)">&#x26A0;</span>`;
      } else {
        icon = `<span style="color:var(--color-danger)">&#x274C;</span>`;
      }

      const itemHtml = `<li>
        <span class="icon">${icon}</span>
        <span class="name">${escapeAttr(check.name)}</span>
        ${check.command ? `<span class="command">${escapeAttr(check.command)}</span>` : ""}
      </li>`;

      if (grouped[check.category]) {
        grouped[check.category].push(itemHtml);
      }
    }

    let html = "";
    for (const s of sections) {
      if (grouped[s.key].length === 0) continue;
      html += `<p class="checks-label">${escapeAttr(s.label)}</p>`;
      html += `<ul class="checks">${grouped[s.key].join("")}</ul>`;
    }

    ui.envChecks.innerHTML = html;
  } catch (error) {
    ui.envChecks.innerHTML = `<p class="empty">環境チェックに失敗しました: ${escapeAttr(error.message)}</p>`;
  }
};

// --- ユーティリティ ---

/**
 * URLをクリップボードにコピーする
 * @param {string} url コピーするURL
 * @param {HTMLElement} button コピーボタン要素
 */
const copyUrl = (url, button) => {
  navigator.clipboard.writeText(url).then(() => {
    button.textContent = "copied!";
    button.classList.add("copied");
    setTimeout(() => {
      button.textContent = "copy";
      button.classList.remove("copied");
    }, COPY_FEEDBACK_DURATION);
  });
};

/**
 * 文字列を指定長で切り詰める
 * @param {string} str 対象文字列
 * @param {number} length 最大長
 * @returns {string} 切り詰められた文字列
 */
const truncate = (str, length) => {
  return str.length > length ? `${str.substring(0, length)}...` : str;
};

/**
 * HTML属性値用にエスケープする
 * @param {string} str エスケープ対象の文字列
 * @returns {string} エスケープ済み文字列
 */
const escapeAttr = (str) => {
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
};

let toastTimer;

/**
 * トースト通知を表示する
 * @param {string} message 表示メッセージ
 */
const showToast = (message) => {
  ui.toast.textContent = message;
  ui.toast.classList.add("visible");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => ui.toast.classList.remove("visible"), TOAST_DURATION);
};

// --- ディレクトリ・オートコンプリート ---

/**
 * デバウンス関数
 * @param {Function} fn 実行する関数
 * @param {number} ms 待機ミリ秒
 * @returns {Function} デバウンスされた関数
 */
const debounce = (fn, ms) => {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
};

/**
 * ディレクトリ候補を取得する
 * @param {string} path 入力パス
 * @param {boolean} showDot ドットディレクトリを表示するか
 * @returns {Promise<Object>} APIレスポンス
 */
const fetchDirCandidates = async (path, showDot = false) => {
  const params = new URLSearchParams({ path });
  if (showDot) params.set("dot", "1");
  return api(`browse-dirs?${params}`);
};

/**
 * ホームディレクトリを取得する
 */
const loadUserHome = async () => {
  const data = await api("browse-dirs");
  state.userHome = data.userHome;
};

/**
 * オートコンプリート付き入力欄を初期化する
 * @param {HTMLInputElement} input 対象の input 要素
 */
const initDirAutocomplete = (input) => {
  const wrapper = input.closest(".dir-autocomplete");
  if (!wrapper) return;

  const dropdown = wrapper.querySelector(".dir-autocomplete-dropdown");
  const dotToggle = wrapper.querySelector("[data-js-dot-toggle]");

  let showDot = false;
  let activeIndex = -1;
  let candidates = [];

  // 初期値をホームディレクトリにセット
  if (!input.value && state.userHome) {
    input.value = state.userHome + "/";
  }

  // ドットディレクトリ・トグル
  if (dotToggle) {
    dotToggle.addEventListener("click", () => {
      showDot = !showDot;
      dotToggle.classList.toggle("active", showDot);
      dotToggle.setAttribute("aria-pressed", showDot);
      triggerFetch(input.value);
    });
  }

  // 候補を描画する
  const renderDropdown = (dirs) => {
    candidates = dirs;
    activeIndex = -1;

    if (dirs.length === 0) {
      dropdown.innerHTML = "";
      dropdown.classList.remove("visible");
      return;
    }

    dropdown.innerHTML = dirs.slice(0, AUTOCOMPLETE_MAX_ITEMS).map((dir, i) =>
      `<div class="dir-autocomplete-item" data-index="${i}" data-dir="${escapeAttr(dir)}">${escapeAttr(dir)}/</div>`
    ).join("");
    dropdown.classList.add("visible");
  };

  // 候補をフェッチ
  const triggerFetch = debounce(async (value) => {
    if (!value) {
      dropdown.innerHTML = "";
      dropdown.classList.remove("visible");
      return;
    }

    try {
      const data = await fetchDirCandidates(value, showDot);
      renderDropdown(data.dirs);
    } catch {
      dropdown.innerHTML = "";
      dropdown.classList.remove("visible");
    }
  }, DEBOUNCE_MS);

  // 候補を選択する
  const selectCandidate = (dirName) => {
    const value = input.value;
    const basePath = value.endsWith("/")
      ? value
      : value.substring(0, value.lastIndexOf("/") + 1);

    input.value = basePath + dirName + "/";
    dropdown.classList.remove("visible");
    input.focus();

    // 次の階層を自動取得
    triggerFetch(input.value);
  };

  // 入力イベント
  input.addEventListener("input", () => {
    triggerFetch(input.value);
  });

  // フォーカス時にも候補を表示
  input.addEventListener("focus", () => {
    if (input.value) {
      triggerFetch(input.value);
    }
  });

  // キーボードナビゲーション
  input.addEventListener("keydown", (event) => {
    if (!dropdown.classList.contains("visible")) return;

    const items = dropdown.querySelectorAll(".dir-autocomplete-item");
    if (items.length === 0) return;

    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        updateActiveItem(items);
        break;

      case "ArrowUp":
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, -1);
        updateActiveItem(items);
        break;

      case "Enter":
        if (activeIndex >= 0 && activeIndex < items.length) {
          event.preventDefault();
          selectCandidate(candidates[activeIndex]);
        }
        break;

      case "Escape":
        dropdown.classList.remove("visible");
        activeIndex = -1;
        break;

      case "Tab":
        if (candidates.length === 1) {
          event.preventDefault();
          selectCandidate(candidates[0]);
        } else {
          dropdown.classList.remove("visible");
        }
        break;
    }
  });

  // ドロップダウンのクリックイベント（mousedown で blur より先に発火）
  dropdown.addEventListener("mousedown", (event) => {
    const item = event.target.closest(".dir-autocomplete-item");
    if (item) {
      event.preventDefault();
      selectCandidate(item.dataset.dir);
    }
  });

  // フォーカスが外れたらドロップダウンを閉じる
  input.addEventListener("blur", () => {
    setTimeout(() => {
      dropdown.classList.remove("visible");
    }, 150);
  });

  // アクティブアイテムの更新
  const updateActiveItem = (items) => {
    items.forEach((item, i) => {
      item.classList.toggle("active", i === activeIndex);
      if (i === activeIndex) {
        item.scrollIntoView({ block: "nearest" });
      }
    });
  };
};

// --- イベントリスナーの設定 ---

const setupEventListeners = () => {
  // フォーム送信
  ui.addGroupForm.addEventListener("submit", (event) => {
    event.preventDefault();
    addGroup();
  });

  ui.addRouteForm.addEventListener("submit", (event) => {
    event.preventDefault();
    addRoute();
  });

  ui.addDomainForm.addEventListener("submit", (event) => {
    event.preventDefault();
    addDomain();
  });

  // ルート種別切替でオートコンプリートの有効/無効を切替
  const routeTypeSelect = ui.addRouteForm.querySelector(`[name="type"]`);
  const routeTargetWrapper = ui.addRouteForm.querySelector(".dir-autocomplete");

  if (routeTypeSelect && routeTargetWrapper) {
    routeTypeSelect.addEventListener("change", () => {
      const isDir = routeTypeSelect.value === "directory";
      routeTargetWrapper.classList.toggle("autocomplete-disabled", !isDir);
      const targetInput = routeTargetWrapper.querySelector("input");

      if (isDir) {
        if (!targetInput.value || targetInput.value.startsWith("http")) {
          targetInput.value = state.userHome ? state.userHome + "/" : "";
        }
        targetInput.placeholder = "/Users/me/sites/myapp";
      } else {
        if (targetInput.value && !targetInput.value.startsWith("http")) {
          targetInput.value = "";
        }
        targetInput.placeholder = "http://localhost:3000";
      }
    });
  }

  // グローバルなクリックイベント委譲
  document.addEventListener("click", (event) => {
    // スキャンボタン
    if (event.target.closest("[data-js-scan]")) {
      scanGroups();
      return;
    }

    // コピーボタン
    const copyButton = event.target.closest("[data-js-copy]");
    if (copyButton) {
      copyUrl(copyButton.dataset.jsCopy, copyButton);
      return;
    }

    // ドメイン: currentに設定
    const setCurrentButton = event.target.closest("[data-js-set-current]");
    if (setCurrentButton) {
      setCurrent(setCurrentButton.dataset.jsSetCurrent);
      return;
    }

    // ドメイン: 削除
    const deleteDomainButton = event.target.closest("[data-js-delete-domain]");
    if (deleteDomainButton) {
      deleteDomain(deleteDomainButton.dataset.jsDeleteDomain);
      return;
    }

    // グループ: 削除
    const deleteGroupButton = event.target.closest("[data-js-delete-group]");
    if (deleteGroupButton) {
      deleteGroup(deleteGroupButton.dataset.jsDeleteGroup);
      return;
    }

    // グループ: 上へ移動
    const moveUpButton = event.target.closest("[data-js-move-up]");
    if (moveUpButton) {
      const index = parseInt(moveUpButton.dataset.jsMoveUp);
      if (index > 0) moveGroup(index, index - 1);
      return;
    }

    // グループ: 下へ移動
    const moveDownButton = event.target.closest("[data-js-move-down]");
    if (moveDownButton) {
      const index = parseInt(moveDownButton.dataset.jsMoveDown);
      if (index < state.groups.length - 1) moveGroup(index, index + 1);
      return;
    }

    // ルート: 削除
    const deleteRouteButton = event.target.closest("[data-js-delete-route]");
    if (deleteRouteButton) {
      deleteRoute(deleteRouteButton.dataset.jsDeleteRoute);
      return;
    }
  });
};

// --- 初期化 ---

const init = async () => {
  setupTabs();
  setupEventListeners();
  setupGroupDragDrop();

  try {
    await Promise.all([
      loadDomains(),
      loadGroups(),
      loadRoutes(),
      loadUserHome(),
    ]);
    renderAll();

    // オートコンプリートを初期化
    document.querySelectorAll(".dir-autocomplete input[type='text']").forEach((input) => {
      initDirAutocomplete(input);
    });
  } catch (error) {
    showToast(`初期読み込みに失敗しました: ${error.message}`);
  }
};

init();
