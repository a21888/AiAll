<?php
/**
 * 内置供应商加载自检（CI 用）：实例化 dumate / lobster 适配器并调用基础方法。
 * 用法：php docker/verify-providers.php
 * 退出码 0 = 全部通过；非 0 = 失败（打印具体原因）。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

require AIGW_ROOT . '/bootstrap/autoload.php';

$app = new \App\Core\Application(AIGW_ROOT);
$pm = $app->container()->make(\App\Provider\ProviderManager::class);

$failures = 0;

foreach (['dumate', 'lobster'] as $key) {
    try {
        $p = $pm->get($key);
        $id = $p->id();
        $name = $p->displayName();
        $loginType = $p->loginType();
        if ($id !== $key) {
            echo "[FAIL] {$key}: id() 返回 {$id}\n";
            $failures++;
            continue;
        }

        // 用空凭据账号调用 models()：dumate 走静态表，lobster 网络失败后回退静态表
        $account = new \App\Provider\Dto\Account(0, $key, 'verify', 'verify', [], []);
        $models = $p->models($account);
        $count = count($models);

        echo "[OK] {$key}: {$name} (login={$loginType}), models={$count}\n";
        if ($count === 0) {
            echo "[FAIL] {$key}: models() 返回空列表\n";
            $failures++;
        }
    } catch (\Throwable $e) {
        echo "[FAIL] {$key}: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $failures++;
    }
}

// 确认两个 provider 已进入内置供应商目录（ProviderRepository BUILTIN_DEFAULTS 自补齐）
$repo = $app->container()->make(\App\Repositories\ProviderRepository::class);
foreach (['dumate', 'lobster'] as $key) {
    $row = $repo->find($key);
    if ($row === null) {
        echo "[FAIL] {$key}: 未在 ProviderRepository 中找到\n";
        $failures++;
    } else {
        echo "[OK] {$key}: provider 行存在（enabled=" . ($row['enabled'] ? '1' : '0') . "）\n";
    }
}

if ($failures > 0) {
    echo "RESULT: {$failures} 项失败\n";
    exit(1);
}

echo "RESULT: ALL PASS\n";
