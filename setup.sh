#!/usr/bin/env bash
set -euo pipefail

# DevRouter セットアップスクリプト
# ファイルを ROUTER_HOME に配置し、Apache への Include パスを案内する
#
# 使い方:
#   sudo bash setup.sh                          → デフォルト /opt/dev-router に配置
#   ROUTER_HOME=/path sudo bash setup.sh        → 任意のパスに配置
#   APACHE_USER=www-data sudo bash setup.sh     → Apache ユーザを明示指定
#
# Apache が起動中であればバイナリパスと実行ユーザを自動検出する。
# 起動していない場合は対話式で入力を求める。

ROUTER_HOME="${ROUTER_HOME:-/opt/dev-router}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# 色付き出力
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

YELLOW='\033[0;33m'

info() { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# --- 1. ディレクトリ作成 ---
info "ROUTER_HOME を作成: ${ROUTER_HOME}"
mkdir -p "${ROUTER_HOME}"/{public/api/lib,public/css,public/js,public/default,conf,data,ssl}

# --- 2. ファイルデプロイ ---
info "ファイルをデプロイ中..."

# public/ — PHP・静的ファイル
rsync -a --delete "${SCRIPT_DIR}/public/" "${ROUTER_HOME}/public/"

# conf/ — テンプレート内の ${ROUTER_HOME} を実パスに置換して配置
for tmpl in "${SCRIPT_DIR}"/conf/*.template; do
    name=$(basename "$tmpl" .template)
    sed "s|\${ROUTER_HOME}|${ROUTER_HOME}|g" "$tmpl" > "${ROUTER_HOME}/conf/${name}"
done

# data/ — 初期データ（既存があれば保持）
if [[ ! -f "${ROUTER_HOME}/data/routes.json" ]]; then
    cp "${SCRIPT_DIR}/data/routes.json" "${ROUTER_HOME}/data/routes.json"
    info "初期データを作成しました"
else
    info "既存のデータを保持します"
fi

# routes.conf / routes-ssl.conf — 存在しなければ空ファイルを作成
[[ -f "${ROUTER_HOME}/data/routes.conf" ]]     || cp "${SCRIPT_DIR}/data/routes.conf" "${ROUTER_HOME}/data/routes.conf"
[[ -f "${ROUTER_HOME}/data/routes-ssl.conf" ]] || cp "${SCRIPT_DIR}/data/routes-ssl.conf" "${ROUTER_HOME}/data/routes-ssl.conf"

# data/ のパーミッション — Apache（PHP）から書き込めるようにする
chmod -R 777 "${ROUTER_HOME}/data"

ok "デプロイ完了"

# --- 3. Apache 環境検出 + env.conf 生成 ---
# 実行中の Apache プロセスからバイナリパスと実行ユーザを検出し、
# conf/env.conf に書き出す。bin/graceful.sh がこの設定を読み込んで
# graceful restart を実行する。
ENV_CONF="${ROUTER_HOME}/conf/env.conf"

# Apache マスタープロセス（root）の PID とバイナリパスを検出
HTTPD_PID=$(ps aux | grep -E '[h]ttpd|[a]pache2' | grep root | head -1 | awk '{print $2}')

if [[ -n "${HTTPD_PID}" ]]; then
    HTTPD_BIN=$(ps -p "${HTTPD_PID}" -o command= | awk '{print $1}')
    info "Apache バイナリ（プロセスから検出）: ${HTTPD_BIN}"
else
    HTTPD_BIN=""
    if [[ -z "${HTTPD_BIN:-}" ]]; then
        warn "Apache が起動していないためバイナリパスを自動検出できません"
        echo ""
        read -p "  Apache バイナリのパスを入力してください（例: /Applications/MAMP/Library/bin/httpd）: " HTTPD_BIN
    fi
fi

# Apache ワーカーの実行ユーザを検出
if [[ -n "${APACHE_USER:-}" ]]; then
    info "Apache ユーザ（環境変数指定）: ${APACHE_USER}"
else
    APACHE_USER=$(ps aux | grep -E '[h]ttpd|[a]pache2' | grep -v root | head -1 | awk '{print $1}')

    if [[ -n "${APACHE_USER}" ]]; then
        info "Apache ユーザ（プロセスから検出）: ${APACHE_USER}"
    else
        warn "Apache ユーザを自動検出できません"
        echo ""
        read -p "  Apache の実行ユーザを入力してください（例: _www, www-data）: " APACHE_USER
    fi
fi

# ログインユーザのホームディレクトリを取得
LOGIN_USER="${SUDO_USER:-$(whoami)}"
USER_HOME=$(eval echo "~${LOGIN_USER}")
info "ログインユーザ: ${LOGIN_USER}（ホーム: ${USER_HOME}）"

# env.conf 生成
if [[ -n "${HTTPD_BIN}" ]]; then
    cat > "${ENV_CONF}" <<EOF
# Apache 環境設定（setup.sh が自動生成）
HTTPD_BIN=${HTTPD_BIN}
USER_HOME=${USER_HOME}
EOF
    ok "env.conf を生成しました: ${ENV_CONF}"
else
    warn "HTTPD_BIN が未指定のため env.conf を生成できません"
    warn "後から setup.sh を再実行してください"
fi

# --- 4. bin/graceful.sh デプロイ ---
mkdir -p "${ROUTER_HOME}/bin"
cp "${SCRIPT_DIR}/bin/graceful.sh" "${ROUTER_HOME}/bin/graceful.sh"
chmod +x "${ROUTER_HOME}/bin/graceful.sh"

# --- 5. sudoers 設定（Apache graceful restart 用）---
# Apache ワーカーユーザに対して graceful.sh の実行のみを許可する。
SUDOERS_FILE="/etc/sudoers.d/dev-router"
GRACEFUL_SCRIPT="${ROUTER_HOME}/bin/graceful.sh"

if [[ -n "${APACHE_USER}" ]]; then
    info "sudoers を設定中..."
    echo "${APACHE_USER} ALL=(root) NOPASSWD: ${GRACEFUL_SCRIPT}" > "${SUDOERS_FILE}"
    chmod 440 "${SUDOERS_FILE}"
    ok "sudoers 設定完了（${APACHE_USER} に ${GRACEFUL_SCRIPT} を許可）"
else
    warn "Apache ユーザが未指定のため sudoers 設定をスキップします"
    warn "後から APACHE_USER=xxx sudo bash setup.sh で再実行してください"
fi

# --- 6. 案内 ---
echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN} DevRouter セットアップ完了${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo "  ROUTER_HOME: ${ROUTER_HOME}"
echo ""
echo "  あとは Apache の httpd.conf に以下の1行を追加して再起動してください:"
echo ""
echo -e "    ${CYAN}Include ${ROUTER_HOME}/conf/vhost-http.conf${NC}"
echo ""
echo "  SSL を使う場合は追加で:"
echo ""
echo -e "    ${CYAN}Include ${ROUTER_HOME}/conf/vhost-https.conf${NC}"
echo ""
echo "  必須 Apache モジュール:"
echo "    mod_rewrite, mod_headers"
echo "  プロキシ機能を使う場合は追加で:"
echo "    mod_proxy, mod_proxy_http, mod_proxy_wstunnel"
echo "  SSL の場合は追加で:"
echo "    mod_ssl"
echo ""
echo "  Apache 再起動後、管理UIにアクセス:"
echo -e "    ${CYAN}http://localhost${NC}"
echo ""
