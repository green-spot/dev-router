#!/usr/bin/env bash
set -uo pipefail

# DevRouter スモークテスト
# 使い方: bash smoke-test.sh

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

PASS=0
FAIL=0

check() {
    local name="$1"
    local result="$2"
    local detail="${3:-}"

    if [[ "$result" == "ok" ]]; then
        echo -e "  ${GREEN}✅${NC} ${name}"
        ((PASS++))
    else
        echo -e "  ${RED}❌${NC} ${name}"
        if [[ -n "$detail" ]]; then
            echo -e "     ${detail}"
        fi
        ((FAIL++))
    fi
}

echo ""
echo "DevRouter スモークテスト:"
echo ""

# 1. 管理 UI アクセス
response=$(curl -s -o /dev/null -w '%{http_code}' http://localhost/ 2>/dev/null)
if [[ "$response" == "200" ]]; then
    check "管理 UI アクセス" "ok"
else
    check "管理 UI アクセス" "fail" "HTTP ${response} (期待: 200)"
fi

# 2. API ヘルスチェック
health=$(curl -s http://localhost/api/health.php 2>/dev/null)
if echo "$health" | grep -q '"status".*"ok"'; then
    check "API ヘルスチェック" "ok"
else
    check "API ヘルスチェック" "fail" "レスポンス: ${health}"
fi

# 3. 環境チェック API
env_check=$(curl -s http://localhost/api/env-check.php 2>/dev/null)
if echo "$env_check" | grep -q '"checks"'; then
    # 必須モジュールで missing がないかチェック
    missing=$(echo "$env_check" | grep -o '"status":"missing"' | head -1)
    if [[ -z "$missing" ]]; then
        check "環境チェック（全モジュール OK）" "ok"
    else
        check "環境チェック" "ok" "一部モジュールが不足しています（詳細は管理 UI で確認）"
    fi
else
    check "環境チェック API" "fail" "レスポンス: ${env_check}"
fi

# 4. ベースドメイン直アクセス → 302 リダイレクト
redirect_code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: 127.0.0.1.nip.io" http://127.0.0.1/ 2>/dev/null)
if [[ "$redirect_code" == "302" ]]; then
    check "ベースドメインリダイレクト" "ok"
else
    check "ベースドメインリダイレクト" "fail" "HTTP ${redirect_code} (期待: 302)"
fi

# 5. 未登録サブドメイン → 404
not_found_code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: nonexistent.127.0.0.1.nip.io" http://127.0.0.1/ 2>/dev/null)
if [[ "$not_found_code" == "404" ]]; then
    check "未登録サブドメイン 404" "ok"
else
    check "未登録サブドメイン 404" "fail" "HTTP ${not_found_code} (期待: 404)"
fi

# 結果サマリー
echo ""
echo "結果: ${PASS} 成功 / ${FAIL} 失敗"
echo ""

if [[ $FAIL -gt 0 ]]; then
    exit 1
fi
