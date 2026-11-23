-- ============================================================================
-- AiAll · MySQL 首次初始化脚本（docker-entrypoint-initdb.d）
-- ----------------------------------------------------------------------------
-- MySQL 镜像已通过 MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD 自动建库建账号，
-- 这里补充 CREATE 权限，确保 install.php 里的 `CREATE DATABASE IF NOT EXISTS`
-- 对已存在的库是安全的 no-op（避免权限歧义导致安装中止）。
-- ============================================================================

GRANT CREATE ON *.* TO 'aigw'@'%';
FLUSH PRIVILEGES;
