#!/bin/bash
# Apache graceful restart ラッパー
# sudoers からこのスクリプトのみ許可することで権限を最小化する。
# conf/env.conf は setup.sh が生成する。
# 安全のため source せず、許可キーのみを抽出してパースする。

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_CONF="${SCRIPT_DIR}/../conf/env.conf"

if [[ ! -f "${ENV_CONF}" ]]; then
    echo "エラー: ${ENV_CONF} が見つかりません。setup.sh を実行してください。" >&2
    exit 1
fi

# env.conf を source せず、許可キーのみを安全に抽出する
HTTPD_BIN=$(grep -E '^HTTPD_BIN=' "${ENV_CONF}" | head -1 | cut -d= -f2-)

if [[ -z "${HTTPD_BIN:-}" ]]; then
    echo "エラー: HTTPD_BIN が設定されていません。setup.sh を再実行してください。" >&2
    exit 1
fi

# HTTPD_BIN に一致する root のマスタープロセスを探す
# macOS では pgrep -f が sudo 経由で正しく動作しないケースがあるため
# ps からの検索をメインとする
HTTPD_PID=$(ps -eo pid,user,command \
    | awk -v bin="${HTTPD_BIN}" '$2 == "root" && index($0, bin) > 0 {print $1; exit}' \
    || true)

if [[ -z "${HTTPD_PID}" ]]; then
    echo "エラー: ${HTTPD_BIN} の root プロセスが見つかりません。" >&2
    exit 1
fi

# USR1 シグナルで graceful restart
kill -USR1 "${HTTPD_PID}"
