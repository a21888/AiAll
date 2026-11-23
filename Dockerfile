# syntax=docker/dockerfile:1

# ============================================================================
# AiAll — AI 聚合网关 Docker 镜像
# ----------------------------------------------------------------------------
# 基于 aigw-backend（https://github.com/ikooc/aigw-backend）封装为开箱即用的
# 单容器部署：Nginx + PHP-FPM 8.2 + Cron，由 supervisord 统一托管。
# 数据库使用独立的 MySQL 8.0 容器（见 docker-compose.yml）。
# ============================================================================

FROM php:8.2-fpm-bookworm

# ---------------------------------------------------------------------------
# 1. 系统运行时依赖（nginx / supervisor / cron / 运行时库）
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        cron \
        ca-certificates \
        tzdata \
        procps \
        curl \
        libcurl4 \
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        libwebp7 \
        libonig5 \
        libzip4 \
    && rm -rf /var/lib/apt/lists/*

# 时区固定为 Asia/Shanghai（与后端签到/保活等定时任务保持一致）
RUN ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# ---------------------------------------------------------------------------
# 2. 编译安装 PHP 扩展（应用必需：pdo_mysql / curl / mbstring / gd / zip / opcache / pcntl）
#    注：sqlite3 / bcmath 非必需，且在官方镜像中编译源缺失，故不安装
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libcurl4-openssl-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        curl \
        mbstring \
        gd \
        zip \
        opcache \
        pcntl \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        $PHPIZE_DEPS libcurl4-openssl-dev libpng-dev libjpeg-dev \
        libfreetype6-dev libwebp-dev libonig-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# 3. 应用代码
# ---------------------------------------------------------------------------
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

# 运行时目录
RUN mkdir -p /var/www/html/storage/{logs,sessions,tmp} \
    && chown -R www-data:www-data /var/www/html/storage

# ---------------------------------------------------------------------------
# 4. 配置与启动
# ---------------------------------------------------------------------------
COPY docker/php.ini        /usr/local/etc/php/conf.d/zz-aiall.ini
COPY docker/nginx.conf     /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/crontab        /etc/cron.d/aiall
COPY docker/entrypoint.sh  /usr/local/bin/entrypoint.sh

RUN chmod 0644 /etc/cron.d/aiall \
    && chmod +x /usr/local/bin/entrypoint.sh

# nginx 日志直通容器标准输出/错误，便于 docker logs 查看
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
