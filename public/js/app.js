/**
 * DevRouter 管理 UI（Alpine.js）
 */

// --- 定数 ---
const SLUG_PATTERN = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/;
const DOMAIN_PATTERN = /^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i;
const DOMAIN_MAX_LENGTH = 253;
const TOAST_DURATION = 3000;
const DEBOUNCE_MS = 200;
const AUTOCOMPLETE_MAX_ITEMS = 50;
const DEFAULT_DOMAIN = "127.0.0.1.nip.io";

// --- API ヘルパー ---
const api = async (endpoint, method = "GET", body = null) => {
  const options = {
    method,
    headers: { "Content-Type": "application/json" },
  };
  if (body) options.body = JSON.stringify(body);

  const response = await fetch(`/api/${endpoint}`, options);
  const envelope = await response.json();

  if (!envelope.ok) {
    throw new Error(envelope.error ?? `API エラー (${response.status})`);
  }
  if (envelope.warning !== undefined) {
    Alpine.store("app").warning = envelope.warning;
  }
  return envelope.data;
};

// --- Alpine 初期化 ---
document.addEventListener("alpine:init", () => {

  // ========================================
  //  グローバルストア
  // ========================================
  Alpine.store("app", {
    baseDomains: [],
    groups: [],
    routes: [],
    warning: null,
    currentDomain: null,
    userHome: null,
    activeTab: "dashboard",
    toast: { message: "", visible: false, timer: null },

    get domain() {
      return this.currentDomain ?? DEFAULT_DOMAIN;
    },

    showToast(message) {
      clearTimeout(this.toast.timer);
      this.toast.message = message;
      this.toast.visible = true;
      this.toast.timer = setTimeout(() => {
        this.toast.visible = false;
      }, TOAST_DURATION);
    },

  });

  // ========================================
  //  タブ
  // ========================================
  Alpine.data("tabs", () => ({
    tabNames: ["groups", "routes", "domains", "env"],

    activate(name) {
      this.$store.app.activeTab = name;
    },

    isActive(name) {
      return this.$store.app.activeTab === name;
    },

    handleKeydown(event) {
      const current = this.tabNames.indexOf(this.$store.app.activeTab);
      let next;
      switch (event.key) {
        case "ArrowRight":
          next = (current + 1) % this.tabNames.length;
          break;
        case "ArrowLeft":
          next = (current - 1 + this.tabNames.length) % this.tabNames.length;
          break;
        case "Home":
          next = 0;
          break;
        case "End":
          next = this.tabNames.length - 1;
          break;
        default:
          return;
      }
      event.preventDefault();
      const name = this.tabNames[next];
      this.activate(name);
      this.$nextTick(() => this.$refs["tab_" + name]?.focus());
    },
  }));

  // ========================================
  //  グループ管理
  // ========================================
  Alpine.data("groupPanel", () => ({
    slug: "",
    path: "",
    label: "",
    dragIndex: null,
    _lastAutoSlug: "",
    _lastAutoLabel: "",

    init() {
      this.$watch("$store.app.userHome", (val) => {
        if (val && !this.path) this.path = val + "/";
      });
      this.$watch("path", () => this.autoFillFromPath());
    },

    autoFillFromPath() {
      if (!this.path.endsWith("/")) return;
      const trimmed = this.path.replace(/\/+$/, "");
      const lastSlash = trimmed.lastIndexOf("/");
      if (lastSlash < 0) return;
      const dirName = trimmed.substring(lastSlash + 1);
      if (!dirName) return;

      if (this.slug === "" || this.slug === this._lastAutoSlug) {
        const candidate = dirName.toLowerCase();
        if (SLUG_PATTERN.test(candidate)) {
          this.slug = candidate;
          this._lastAutoSlug = candidate;
        }
      }
      if (this.label === "" || this.label === this._lastAutoLabel) {
        this.label = dirName;
        this._lastAutoLabel = dirName;
      }
    },

    async addGroup() {
      const slug = this.slug.trim();
      const trimmed = this.path.trim().replace(/\/+$/, "");
      if (!slug || !trimmed) return;
      if (!SLUG_PATTERN.test(slug)) {
        this.$store.app.showToast("スラグは英小文字・数字・ハイフンのみ使用可能です（先頭と末尾はハイフン不可）");
        return;
      }
      try {
        const data = await api("groups", "POST", { slug, path: trimmed, label: this.label.trim() });
        this.$store.app.groups = data.groups;
        this.slug = "";
        this.label = "";
        this._lastAutoSlug = "";
        this._lastAutoLabel = "";
        this.path = this.$store.app.userHome ? this.$store.app.userHome + "/" : "";
        this.$store.app.showToast("グループを追加しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async deleteGroup(slug) {
      if (!confirm(`グループ "${slug}" を削除しますか？`)) return;
      try {
        const data = await api("groups", "DELETE", { slug });
        this.$store.app.groups = data.groups;
        this.$store.app.showToast("グループを削除しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async moveGroup(from, to) {
      const order = this.$store.app.groups.map((g) => g.slug);
      const [moved] = order.splice(from, 1);
      order.splice(to, 0, moved);
      try {
        const data = await api("groups", "PUT", { order });
        this.$store.app.groups = data.groups;
        this.$store.app.showToast("グループの優先順位を変更しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async enableGroupSsl(slug) {
      try {
        await api("ssl", "POST", { type: "group", slug });
        const group = this.$store.app.groups.find((g) => g.slug === slug);
        if (group) group.ssl = true;
        this.$store.app.showToast(`グループ "${slug}" の SSL を有効化しました`);
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    handleDragStart(event, index) {
      this.dragIndex = index;
      event.target.style.opacity = "0.5";
    },

    handleDragLeave(event) {
      if (!event.currentTarget.contains(event.relatedTarget)) {
        event.currentTarget.classList.remove("drag-over");
      }
    },

    handleDragEnd(event) {
      event.target.style.opacity = "";
      document.querySelectorAll(".card.drag-over").forEach((el) => el.classList.remove("drag-over"));
    },

    async handleDrop(event, dropIndex) {
      event.currentTarget.classList.remove("drag-over");
      if (this.dragIndex === null || this.dragIndex === dropIndex) return;
      await this.moveGroup(this.dragIndex, dropIndex);
      this.dragIndex = null;
    },
  }));

  // ========================================
  //  ルート管理
  // ========================================
  Alpine.data("routePanel", () => ({
    slug: "",
    target: "",
    label: "",
    type: "directory",
    _lastAutoSlug: "",
    _lastAutoLabel: "",

    init() {
      this.$watch("$store.app.userHome", (val) => {
        if (val && !this.target && this.type === "directory") {
          this.target = val + "/";
        }
      });
      this.$watch("target", () => {
        if (this.isDirectory) this.autoFillFromTarget();
      });
    },

    autoFillFromTarget() {
      if (!this.target.endsWith("/")) return;
      const trimmed = this.target.replace(/\/+$/, "");
      const lastSlash = trimmed.lastIndexOf("/");
      if (lastSlash < 0) return;
      const dirName = trimmed.substring(lastSlash + 1);
      if (!dirName) return;

      if (this.slug === "" || this.slug === this._lastAutoSlug) {
        const candidate = dirName.toLowerCase();
        if (SLUG_PATTERN.test(candidate)) {
          this.slug = candidate;
          this._lastAutoSlug = candidate;
        }
      }
      if (this.label === "" || this.label === this._lastAutoLabel) {
        this.label = dirName;
        this._lastAutoLabel = dirName;
      }
    },

    get isDirectory() {
      return this.type === "directory";
    },

    handleTypeChange() {
      if (this.isDirectory) {
        if (!this.target || this.target.startsWith("http")) {
          this.target = this.$store.app.userHome ? this.$store.app.userHome + "/" : "";
        }
      } else {
        if (this.target && !this.target.startsWith("http")) {
          this.target = "";
        }
      }
    },

    async addRoute() {
      const slug = this.slug.trim();
      const target = this.target.trim().replace(/\/+$/, "");
      if (!slug || !target) return;
      if (!SLUG_PATTERN.test(slug)) {
        this.$store.app.showToast("スラグは英小文字・数字・ハイフンのみ使用可能です（先頭と末尾はハイフン不可）");
        return;
      }
      try {
        const data = await api("routes", "POST", { slug, target, type: this.type, label: this.label.trim() });
        this.$store.app.routes = data.routes;
        this.slug = "";
        this.label = "";
        this._lastAutoSlug = "";
        this._lastAutoLabel = "";
        this.target = this.isDirectory && this.$store.app.userHome ? this.$store.app.userHome + "/" : "";
        this.$store.app.showToast("ルートを追加しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async deleteRoute(slug) {
      if (!confirm(`ルート "${slug}" を削除しますか？`)) return;
      try {
        const data = await api("routes", "DELETE", { slug });
        this.$store.app.routes = data.routes;
        this.$store.app.showToast("ルートを削除しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },
  }));

  // ========================================
  //  ベースドメイン管理
  // ========================================
  Alpine.data("domainPanel", () => ({
    domain: "",

    async addDomain() {
      const domain = this.domain.trim();
      if (!domain) return;
      if (domain.length > DOMAIN_MAX_LENGTH || !DOMAIN_PATTERN.test(domain)) {
        this.$store.app.showToast("無効なドメイン形式です（英数字・ハイフン・ドットのみ使用可）");
        return;
      }
      try {
        const data = await api("domains", "POST", { domain });
        this.$store.app.baseDomains = data.baseDomains;
        this.$store.app.currentDomain = data.baseDomains.find((e) => e.current)?.domain ?? null;
        this.domain = "";
        this.$store.app.showToast("ベースドメインを追加しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async setCurrent(domain) {
      try {
        const data = await api("domains", "PUT", { domain });
        this.$store.app.baseDomains = data.baseDomains;
        this.$store.app.currentDomain = domain;
        this.$store.app.showToast(`${domain} を current に設定しました`);
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async deleteDomain(domain) {
      if (!confirm(`ベースドメイン "${domain}" を削除しますか？`)) return;
      try {
        const data = await api("domains", "DELETE", { domain });
        this.$store.app.baseDomains = data.baseDomains;
        this.$store.app.currentDomain = data.baseDomains.find((e) => e.current)?.domain ?? null;
        this.$store.app.showToast("ベースドメインを削除しました");
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },

    async enableSsl(domain) {
      try {
        await api("ssl", "POST", { type: "domain", domain });
        const bd = this.$store.app.baseDomains.find((e) => e.domain === domain);
        if (bd) bd.ssl = true;
        this.$store.app.showToast(`${domain} の SSL を有効化しました`);
      } catch (e) {
        this.$store.app.showToast(e.message);
      }
    },
  }));

  // ========================================
  //  環境チェック
  // ========================================
  Alpine.data("envPanel", () => ({
    sections: [
      { key: "required", label: "必須" },
      { key: "proxy", label: "プロキシ（リバースプロキシ使用時）" },
      { key: "websocket", label: "WebSocket（HMR 使用時）" },
      { key: "ssl", label: "SSL（HTTPS 使用時）" },
    ],
    grouped: {},
    loading: false,
    loaded: false,
    error: null,

    init() {
      this.$watch("$store.app.activeTab", (tab) => {
        if (tab === "env" && !this.loaded) this.loadEnvCheck();
      });
    },

    async loadEnvCheck() {
      this.loading = true;
      this.error = null;
      try {
        const data = await api("env-check");
        const grouped = {};
        for (const s of this.sections) grouped[s.key] = [];
        for (const check of data.checks) {
          if (grouped[check.category]) grouped[check.category].push(check);
        }
        this.grouped = grouped;
        this.loaded = true;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },

    statusIcon(status) {
      if (status === "ok") return "\u2705";
      if (status === "warning") return "\u26A0\uFE0F";
      return "\u274C";
    },

    statusColor(status) {
      if (status === "ok") return "var(--color-success)";
      if (status === "warning") return "var(--color-warning)";
      return "var(--color-danger)";
    },
  }));

  // ========================================
  //  ディレクトリ・オートコンプリート
  // ========================================
  Alpine.data("dirAutocomplete", () => ({
    value: "",
    showDot: false,
    activeIndex: -1,
    candidates: [],
    dropdownVisible: false,
    _fetchTimer: null,

    init() {
      if (!this.value && this.$store.app.userHome) {
        this.value = this.$store.app.userHome + "/";
      }
    },

    toggleDot() {
      this.showDot = !this.showDot;
      this.triggerFetch(this.value);
    },

    triggerFetch(path) {
      clearTimeout(this._fetchTimer);
      this._fetchTimer = setTimeout(async () => {
        if (!path) {
          this.candidates = [];
          this.dropdownVisible = false;
          return;
        }
        try {
          const params = new URLSearchParams({ path });
          if (this.showDot) params.set("dot", "1");
          const data = await api(`browse-dirs?${params}`);
          this.candidates = data.dirs.slice(0, AUTOCOMPLETE_MAX_ITEMS);
          this.activeIndex = -1;
          this.dropdownVisible = this.candidates.length > 0;
        } catch {
          this.candidates = [];
          this.dropdownVisible = false;
        }
      }, DEBOUNCE_MS);
    },

    selectCandidate(dirName) {
      const basePath = this.value.endsWith("/")
        ? this.value
        : this.value.substring(0, this.value.lastIndexOf("/") + 1);
      this.value = basePath + dirName + "/";
      this.dropdownVisible = false;
      this.$nextTick(() => {
        this.$refs.input?.focus();
        this.triggerFetch(this.value);
      });
    },

    handleInput() {
      this.triggerFetch(this.value);
    },

    handleFocus() {
      if (this.value) this.triggerFetch(this.value);
    },

    handleBlur() {
      setTimeout(() => {
        this.dropdownVisible = false;
      }, 150);
    },

    handleKeydown(event) {
      if (!this.dropdownVisible || this.candidates.length === 0) return;

      switch (event.key) {
        case "ArrowDown":
          event.preventDefault();
          this.activeIndex = Math.min(this.activeIndex + 1, this.candidates.length - 1);
          this.scrollToActive();
          break;
        case "ArrowUp":
          event.preventDefault();
          this.activeIndex = Math.max(this.activeIndex - 1, -1);
          this.scrollToActive();
          break;
        case "Enter":
          if (this.activeIndex >= 0) {
            event.preventDefault();
            this.selectCandidate(this.candidates[this.activeIndex]);
          }
          break;
        case "Escape":
          this.dropdownVisible = false;
          this.activeIndex = -1;
          break;
        case "Tab":
          if (this.candidates.length === 1) {
            event.preventDefault();
            this.selectCandidate(this.candidates[0]);
          } else {
            this.dropdownVisible = false;
          }
          break;
      }
    },

    scrollToActive() {
      this.$nextTick(() => {
        this.$refs.dropdown?.querySelector(".active")?.scrollIntoView({ block: "nearest" });
      });
    },
  }));

  // ========================================
  //  初期データロード
  // ========================================
  const store = Alpine.store("app");

  Promise.all([
    api("domains").then((data) => {
      store.baseDomains = data.baseDomains;
      store.currentDomain = data.baseDomains.find((e) => e.current)?.domain ?? null;
    }),
    api("groups").then((data) => {
      store.groups = data.groups;
    }),
    api("routes").then((data) => {
      store.routes = data.routes;
    }),
    api("browse-dirs").then((data) => {
      store.userHome = data.userHome;
    }),
  ]).catch((error) => {
    store.showToast(`初期読み込みに失敗しました: ${error.message}`);
  });
});
