#!/usr/bin/env bash
set -euo pipefail

# DevRouter アンインストールスクリプト
# 使い方: sudo bash uninstall.sh

ROUTER_HOME="/opt/dev-router"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }

# OS 検出
detect_os() {
    case "$(uname -s)" in
        Darwin) OS="macos" ;;
        Linux)
            if grep -qi microsoft /proc/version 2>/dev/null; then
                OS="wsl2"
            else
                OS="linux"
            fi
            ;;
        *) OS="unknown" ;;
    esac
}

# --- 1. 確認プロンプト ---
echo ""
echo -e "${YELLOW}DevRouter アンインストール${NC}"
echo ""
echo "以下を削除します:"
echo "  - Apache 設定ファイル（dev-router.conf / dev-router-ssl.conf）"
echo "  - ROUTER_HOME ディレクトリ: ${ROUTER_HOME}"
echo ""

read -p "続行しますか？ (y/N): " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "キャンセルしました"
    exit 0
fi

# routes.json のバックアップ
if [[ -f "${ROUTER_HOME}/data/routes.json" ]]; then
    read -p "routes.json をホームディレクトリにバックアップしますか？ (Y/n): " backup
    if [[ "$backup" != "n" && "$backup" != "N" ]]; then
        backup_path="${HOME}/dev-router-routes-backup.json"
        cp "${ROUTER_HOME}/data/routes.json" "$backup_path"
        ok "routes.json をバックアップしました: ${backup_path}"
    fi
fi

detect_os

# --- 2. Apache 設定の除去 ---
info "Apache 設定を除去中..."

case "$OS" in
    macos)
        for dir in "/opt/homebrew/etc/httpd" "/usr/local/etc/httpd"; do
            if [[ -d "$dir" ]]; then
                rm -f "${dir}/extra/dev-router.conf"
                rm -f "${dir}/extra/dev-router-ssl.conf"
                # httpd.conf から Include 行を除去
                if [[ -f "${dir}/httpd.conf" ]]; then
                    sed -i '' '/# DevRouter/d' "${dir}/httpd.conf"
                    sed -i '' '/dev-router\.conf/d' "${dir}/httpd.conf"
                    sed -i '' '/# DevRouter SSL/d' "${dir}/httpd.conf"
                    sed -i '' '/dev-router-ssl\.conf/d' "${dir}/httpd.conf"
                fi
            fi
        done
        ok "Apache 設定を除去しました（macOS）"
        ;;

    linux|wsl2)
        # Debian/Ubuntu 系
        if [[ -d "/etc/apache2/sites-available" ]]; then
            if command -v a2dissite &>/dev/null; then
                a2dissite dev-router.conf 2>/dev/null || true
                a2dissite dev-router-ssl.conf 2>/dev/null || true
            fi
            rm -f /etc/apache2/sites-available/dev-router.conf
            rm -f /etc/apache2/sites-available/dev-router-ssl.conf
            rm -f /etc/apache2/sites-enabled/dev-router.conf
            rm -f /etc/apache2/sites-enabled/dev-router-ssl.conf
        fi
        # RHEL/CentOS 系
        if [[ -d "/etc/httpd/conf.d" ]]; then
            rm -f /etc/httpd/conf.d/dev-router.conf
            rm -f /etc/httpd/conf.d/dev-router-ssl.conf
        fi
        ok "Apache 設定を除去しました（Linux）"
        ;;
esac

# --- 3. ROUTER_HOME ディレクトリの削除 ---
if [[ -d "${ROUTER_HOME}" ]]; then
    info "ROUTER_HOME を削除中: ${ROUTER_HOME}"
    rm -rf "${ROUTER_HOME}"
    ok "ROUTER_HOME を削除しました"
else
    info "ROUTER_HOME は既に存在しません"
fi

# --- 4. Apache 再起動 ---
info "Apache を再起動中..."

case "$OS" in
    macos)
        if brew services list 2>/dev/null | grep -q httpd; then
            brew services restart httpd 2>/dev/null || true
        else
            apachectl graceful 2>/dev/null || sudo apachectl graceful 2>/dev/null || true
        fi
        ;;
    linux|wsl2)
        if command -v systemctl &>/dev/null; then
            systemctl restart apache2 2>/dev/null || systemctl restart httpd 2>/dev/null || true
        else
            service apache2 restart 2>/dev/null || service httpd restart 2>/dev/null || true
        fi
        ;;
esac

ok "Apache を再起動しました"

# --- 5. 完了メッセージ ---
echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN} DevRouter アンインストール完了${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo "Apache はデフォルト状態に戻りました。"
echo ""
