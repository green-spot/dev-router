#!/usr/bin/env bash
set -euo pipefail

# DevRouter アンインストールスクリプト
# ROUTER_HOME を削除する。Apache 設定の除去はユーザーが行う。
#
# 使い方:
#   sudo bash uninstall.sh
#   ROUTER_HOME=/path sudo bash uninstall.sh

ROUTER_HOME="${ROUTER_HOME:-/opt/dev-router}"

YELLOW='\033[0;33m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

info() { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()   { echo -e "${GREEN}[OK]${NC} $1"; }

# --- 1. 確認 ---
echo ""
echo -e "${YELLOW}DevRouter アンインストール${NC}"
echo ""
echo "  削除対象: ${ROUTER_HOME}"
echo ""

read -p "続行しますか？ (y/N): " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "キャンセルしました"
    exit 0
fi

# --- 2. routes.json のバックアップ ---
if [[ -f "${ROUTER_HOME}/data/routes.json" ]]; then
    read -p "routes.json をホームディレクトリにバックアップしますか？ (Y/n): " backup
    if [[ "$backup" != "n" && "$backup" != "N" ]]; then
        backup_path="${HOME}/dev-router-routes-backup.json"
        cp "${ROUTER_HOME}/data/routes.json" "$backup_path"
        ok "バックアップ: ${backup_path}"
    fi
fi

# --- 3. ROUTER_HOME 削除 ---
if [[ -d "${ROUTER_HOME}" ]]; then
    rm -rf "${ROUTER_HOME}"
    ok "削除しました: ${ROUTER_HOME}"
else
    info "既に存在しません: ${ROUTER_HOME}"
fi

# --- 4. sudoers 削除 ---
SUDOERS_FILE="/etc/sudoers.d/dev-router"
if [[ -f "${SUDOERS_FILE}" ]]; then
    rm -f "${SUDOERS_FILE}"
    ok "sudoers 設定を削除しました: ${SUDOERS_FILE}"
fi

# --- 5. 案内 ---
echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN} DevRouter アンインストール完了${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo "  httpd.conf に追加した Include 行を手動で削除し、Apache を再起動してください。"
echo ""
