#!/usr/bin/env bash
set -euo pipefail

# DevRouter セットアップスクリプト
# 使い方: sudo bash setup.sh

ROUTER_HOME="/opt/dev-router"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# 色付き出力
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

# --- 1. OS 検出 ---
detect_os() {
    case "$(uname -s)" in
        Darwin)
            OS="macos"
            ;;
        Linux)
            if grep -qi microsoft /proc/version 2>/dev/null; then
                OS="wsl2"
            else
                OS="linux"
            fi
            ;;
        *)
            error "未対応の OS です: $(uname -s)"
            exit 1
            ;;
    esac
    info "OS 検出: ${OS}"
}

# --- 2. 前提条件チェック ---
check_prerequisites() {
    local has_error=false

    # Apache チェック
    if command -v apachectl &>/dev/null; then
        local apache_version
        apache_version=$(apachectl -v 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+' | head -1)
        if [[ -n "$apache_version" ]]; then
            ok "Apache ${apache_version} が見つかりました"
        else
            error "Apache のバージョンを取得できません"
            has_error=true
        fi
    elif command -v httpd &>/dev/null; then
        ok "Apache (httpd) が見つかりました"
    else
        error "Apache がインストールされていません"
        case "$OS" in
            macos)  echo "  → brew install httpd" ;;
            linux)  echo "  → sudo apt install apache2  または  sudo yum install httpd" ;;
            wsl2)   echo "  → sudo apt install apache2" ;;
        esac
        has_error=true
    fi

    # PHP チェック
    if command -v php &>/dev/null; then
        local php_version
        php_version=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
        ok "PHP ${php_version} が見つかりました"
    else
        error "PHP がインストールされていません"
        case "$OS" in
            macos)  echo "  → brew install php" ;;
            linux)  echo "  → sudo apt install php  または  sudo yum install php" ;;
            wsl2)   echo "  → sudo apt install php" ;;
        esac
        has_error=true
    fi

    # 必須 Apache モジュールチェック
    local required_modules=("rewrite" "proxy" "proxy_http" "proxy_wstunnel" "headers")
    local loaded_modules
    loaded_modules=$(apachectl -M 2>/dev/null || httpd -M 2>/dev/null || echo "")

    for mod in "${required_modules[@]}"; do
        if echo "$loaded_modules" | grep -qi "${mod}_module"; then
            ok "mod_${mod} が有効です"
        else
            warn "mod_${mod} が無効です"
            case "$OS" in
                macos)
                    echo "  → Homebrew Apache の場合、httpd.conf で LoadModule の行をアンコメントしてください"
                    ;;
                linux|wsl2)
                    echo "  → sudo a2enmod ${mod} && sudo systemctl restart apache2"
                    ;;
            esac
            has_error=true
        fi
    done

    if $has_error; then
        echo ""
        warn "上記の問題を解決してから再実行してください"
        exit 1
    fi
}

# --- 3. ROUTER_HOME ディレクトリ作成 ---
create_router_home() {
    info "ROUTER_HOME を作成: ${ROUTER_HOME}"

    mkdir -p "${ROUTER_HOME}"/{public/api/lib,public/css,public/js,conf,data,ssl}

    ok "ディレクトリ構造を作成しました"
}

# --- 4-5. ファイルのデプロイ + 初期データ生成 ---
deploy_files() {
    info "ソースファイルをデプロイ中..."

    # public/ — PHP ファイル・静的ファイル
    rsync -a --delete "${SCRIPT_DIR}/public/" "${ROUTER_HOME}/public/"

    # conf/ — Apache 設定ファイル（テンプレートを展開）
    # routing-rules.conf: ${ROUTER_HOME} を実パスに置換
    sed "s|\${ROUTER_HOME}|${ROUTER_HOME}|g" \
        "${SCRIPT_DIR}/conf/routing-rules.conf" > "${ROUTER_HOME}/conf/routing-rules.conf"

    # 初期 routes.json（既存がなければ作成）
    if [[ ! -f "${ROUTER_HOME}/data/routes.json" ]]; then
        cp "${SCRIPT_DIR}/data/routes.json" "${ROUTER_HOME}/data/routes.json"
        info "初期 routes.json を作成しました"
    else
        info "既存の routes.json を保持します"
    fi

    # 初期 routing.map（既存がなければ作成）
    if [[ ! -f "${ROUTER_HOME}/data/routing.map" ]]; then
        cp "${SCRIPT_DIR}/data/routing.map" "${ROUTER_HOME}/data/routing.map"
        info "初期 routing.map を作成しました"
    else
        info "既存の routing.map を保持します"
    fi

    # Apache が PHP ファイルを書き込めるよう data/ のパーミッション設定
    case "$OS" in
        macos)
            # Homebrew Apache は通常のユーザ権限で動作する
            chmod -R 775 "${ROUTER_HOME}/data"
            ;;
        linux|wsl2)
            # www-data が書き込めるようにする
            chown -R www-data:www-data "${ROUTER_HOME}/data" 2>/dev/null || true
            chmod -R 775 "${ROUTER_HOME}/data"
            ;;
    esac

    ok "ファイルをデプロイしました"
}

# --- 6. Apache 設定の注入 ---
inject_apache_config() {
    info "Apache 設定を注入中..."

    local config_content
    config_content=$(sed "s|\${ROUTER_HOME}|${ROUTER_HOME}|g" \
        "${SCRIPT_DIR}/conf/vhost-http.conf.template")

    case "$OS" in
        macos)
            # Homebrew Apache
            local httpd_conf_dir
            if [[ -d "/opt/homebrew/etc/httpd/extra" ]]; then
                httpd_conf_dir="/opt/homebrew/etc/httpd"
            elif [[ -d "/usr/local/etc/httpd/extra" ]]; then
                httpd_conf_dir="/usr/local/etc/httpd"
            else
                error "Homebrew Apache の設定ディレクトリが見つかりません"
                exit 1
            fi

            echo "$config_content" > "${httpd_conf_dir}/extra/dev-router.conf"

            # httpd.conf に Include がなければ追加
            if ! grep -q "dev-router.conf" "${httpd_conf_dir}/httpd.conf"; then
                echo "" >> "${httpd_conf_dir}/httpd.conf"
                echo "# DevRouter" >> "${httpd_conf_dir}/httpd.conf"
                echo "Include ${httpd_conf_dir}/extra/dev-router.conf" >> "${httpd_conf_dir}/httpd.conf"
                info "httpd.conf に Include 行を追加しました"
            else
                info "httpd.conf に Include 行が既に存在します"
            fi

            ok "Apache 設定を配置しました: ${httpd_conf_dir}/extra/dev-router.conf"
            ;;

        linux|wsl2)
            # Debian/Ubuntu 系
            if [[ -d "/etc/apache2/sites-available" ]]; then
                echo "$config_content" > "/etc/apache2/sites-available/dev-router.conf"

                if command -v a2ensite &>/dev/null; then
                    a2ensite dev-router.conf 2>/dev/null || true
                fi

                ok "Apache 設定を配置しました: /etc/apache2/sites-available/dev-router.conf"

            # RHEL/CentOS 系
            elif [[ -d "/etc/httpd/conf.d" ]]; then
                echo "$config_content" > "/etc/httpd/conf.d/dev-router.conf"
                ok "Apache 設定を配置しました: /etc/httpd/conf.d/dev-router.conf"
            else
                error "Apache 設定ディレクトリが見つかりません"
                exit 1
            fi
            ;;
    esac
}

# --- 7. Apache 再起動 ---
restart_apache() {
    info "Apache を再起動中..."

    case "$OS" in
        macos)
            if brew services list 2>/dev/null | grep -q httpd; then
                brew services restart httpd
            else
                apachectl graceful 2>/dev/null || sudo apachectl graceful
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
}

# --- 8. 完了メッセージ ---
show_complete() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN} DevRouter セットアップ完了${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "  管理 UI: ${CYAN}http://localhost${NC}"
    echo -e "  ROUTER_HOME: ${ROUTER_HOME}"
    echo ""
    echo "  デフォルトベースドメイン: 127.0.0.1.nip.io"
    echo "  例: http://myapp.127.0.0.1.nip.io"
    echo ""
}

# --- メイン ---
main() {
    echo ""
    echo -e "${CYAN}DevRouter セットアップ${NC}"
    echo ""

    detect_os
    check_prerequisites
    create_router_home
    deploy_files
    inject_apache_config
    restart_apache
    show_complete
}

main "$@"
