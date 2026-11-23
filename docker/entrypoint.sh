#!/bin/sh
# ============================================================================
# AiAll · 容器入口脚本
# ----------------------------------------------------------------------------
# 职责：等待 MySQL → 生成/恢复 .env → 首次安装数据库 → 启动 supervisord
# （nginx + php-fpm + cron）。
# 幂等：可安全地反复执行（重启容器不重置管理员口令、不重建库）。
# ============================================================================
set -e

APP_DIR="/var/www/html"
ENV_VOL="/etc/aiall"          # 持久化卷挂载点（存 .env 与首次随机口令）
ENV_FILE="$APP_DIR/.env"

echo "[AiAll] ==== 容器启动 ===="

# ---------------------------------------------------------------------------
# 0. 从持久化卷恢复 .env（若存在）
# ---------------------------------------------------------------------------
if [ -f "$ENV_VOL/.env" ]; then
    cp -f "$ENV_VOL/.env" "$ENV_FILE"
    echo "[AiAll] 已从持久化卷恢复 .env"
fi

# ---------------------------------------------------------------------------
# 1. 生成 .env（仅当不存在）
# ---------------------------------------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    APP_KEY="${AIGW_APP_KEY:-}"
    if [ -z "$APP_KEY" ]; then
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        echo "[AiAll] 未提供 AIGW_APP_KEY，已随机生成（生产环境请显式指定并妥善保管）"
    fi
    APP_URL="${AIGW_APP_URL:-http://localhost:8080}"

    cat > "$ENV_FILE" <<EOF
AIGW_APP_NAME=${AIGW_APP_NAME:-AiAll 聚合网关}
AIGW_APP_DEBUG=${AIGW_APP_DEBUG:-false}
AIGW_APP_URL=${APP_URL}
AIGW_APP_KEY=${APP_KEY}
AIGW_ADMIN_LOGIN_PATH=${AIGW_ADMIN_LOGIN_PATH:-/api/admin/login}
AIGW_DB_HOST=${AIGW_DB_HOST:-db}
AIGW_DB_PORT=${AIGW_DB_PORT:-3306}
AIGW_DB_DATABASE=${AIGW_DB_DATABASE:-aigw}
AIGW_DB_USERNAME=${AIGW_DB_USERNAME:-aigw}
AIGW_DB_PASSWORD=${AIGW_DB_PASSWORD:-}
AIGW_GATEWAY_PORT=${AIGW_GATEWAY_PORT:-51120}
AIGW_GATEWAY_BASE_URL=${AIGW_GATEWAY_BASE_URL:-${APP_URL}}
AIGW_CORS_ORIGINS=${AIGW_CORS_ORIGINS:-}
AIGW_TRUSTED_PROXIES=${AIGW_TRUSTED_PROXIES:-}
EOF
    chmod 600 "$ENV_FILE"
    echo "[AiAll] 已生成 .env"
fi

# .env 需对 www-data（php-fpm worker）可读
chown www-data:www-data "$ENV_FILE" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 2. 等待 MySQL 就绪（用应用账号探测，天然覆盖账号创建完成）
# ---------------------------------------------------------------------------
echo "[AiAll] 等待 MySQL ${AIGW_DB_HOST:-db}:${AIGW_DB_PORT:-3306} 就绪 ..."
until php -r '
    $host = getenv("AIGW_DB_HOST") ?: "db";
    $port = getenv("AIGW_DB_PORT") ?: "3306";
    $user = getenv("AIGW_DB_USERNAME") ?: "aigw";
    $pass = getenv("AIGW_DB_PASSWORD") ?: "";
    try {
        new PDO("mysql:host=" . $host . ";port=" . $port . ";charset=utf8mb4", $user, $pass,
            [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (Throwable $e) { exit(1); }
'; do
    echo "[AiAll] MySQL 尚未就绪，3 秒后重试 ..."
    sleep 3
done
echo "[AiAll] MySQL 已就绪。"

# ---------------------------------------------------------------------------
# 3. 准备 storage 运行时目录
# ---------------------------------------------------------------------------
mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/sessions" "$APP_DIR/storage/tmp"
chown -R www-data:www-data "$APP_DIR/storage"
echo "[AiAll] storage 目录已就绪。"

# ---------------------------------------------------------------------------
# 4. 首次安装数据库（幂等：已安装则跳过，避免重置管理员口令）
# ---------------------------------------------------------------------------
cd "$APP_DIR"

installed=$(php -r '
    $host = getenv("AIGW_DB_HOST") ?: "db";
    $port = getenv("AIGW_DB_PORT") ?: "3306";
    $db   = getenv("AIGW_DB_DATABASE") ?: "aigw";
    $user = getenv("AIGW_DB_USERNAME") ?: "aigw";
    $pass = getenv("AIGW_DB_PASSWORD") ?: "";
    try {
        $pdo = new PDO("mysql:host=" . $host . ";port=" . $port . ";dbname=" . $db . ";charset=utf8mb4", $user, $pass);
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db) . " AND table_name = \"aigw_gateway_settings\"")->fetchColumn();
        if ($n > 0) {
            $k = $pdo->query("SELECT api_key FROM aigw_gateway_settings WHERE id = 1")->fetchColumn();
            echo ($k !== false && $k !== null && $k !== "") ? "yes" : "no";
        } else {
            echo "no";
        }
    } catch (Throwable $e) { echo "no"; }
')

if [ "$installed" != "yes" ]; then
    echo "[AiAll] 首次安装，执行 database/install.php ..."
    if [ -n "$AIGW_INSTALL_ADMIN_PASSWORD" ]; then
        php database/install.php
    else
        ADMIN_PW="$(php -r 'echo bin2hex(random_bytes(10));')"
        AIGW_INSTALL_ADMIN_PASSWORD="$ADMIN_PW" php database/install.php
        mkdir -p "$ENV_VOL"
        printf '%s\n' "username=admin" "password=$ADMIN_PW" "created_at=$(date '+%Y-%m-%d %H:%M:%S')" > "$ENV_VOL/admin-credentials.txt"
        echo "[AiAll] 首次安装已生成随机管理员口令，已保存到 aiall_env 卷的 admin-credentials.txt"
    fi
else
    echo "[AiAll] 数据库已安装，跳过安装脚本。"
fi

# ---------------------------------------------------------------------------
# 5. 持久化 .env（供后续重启恢复，保证 AIGW_APP_KEY 稳定）
# ---------------------------------------------------------------------------
mkdir -p "$ENV_VOL"
cp -f "$ENV_FILE" "$ENV_VOL/.env"
echo "[AiAll] 已持久化 .env。"

# ---------------------------------------------------------------------------
# 6. 启动 supervisord（nginx + php-fpm + cron）
# ---------------------------------------------------------------------------
echo "[AiAll] 启动服务（nginx + php-fpm + cron）..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
