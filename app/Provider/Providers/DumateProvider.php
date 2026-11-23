<?php
/**
 * 百度搭子（DuMate）适配器
 *
 * 登录方式：API_KEY —— 手工粘贴网页 Cookie（BDUSS 等），后台自动换票。
 *
 * 链路（逆向自 dumate2api / AIAutomator，均开源 MIT）：
 *   1) 网页 Cookie → GET https://www.dumate.cn/api/dumate/app/token/get
 *      → { result: { token, expired_at } }（token 约 1 小时有效，服务端会缓存）
 *   2) 模型网关 → POST https://dumate-svc.baidu.com/gateway/apis/v1/chat/completions
 *      Authorization: Bearer <token>
 *      X-Dumate-Client-Type: web / X-Dumate-Account-Id: global
 *      OpenAI 兼容（SSE 流式与非流式）
 *   3) 额度 → GET https://www.dumate.cn/api/dumate/points/quota_overview?...（cookie）
 *      → { result: { totalPoints, usedPoints, subscription, incremental } }
 *   4) 签到 → GET loginBonusInfo / POST loginBonus（cookie，幂等）
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Repositories\AccountRepository;
use App\Repositories\ProxyRepository;
use App\Support\Logger;

class DumateProvider extends AbstractProvider
{
    private const WEB_BASE = 'https://www.dumate.cn';
    private const GATEWAY_BASE = 'https://dumate-svc.baidu.com/gateway/apis/v1';

    /**
     * 静态模型目录：model_id => [context_window, max_tokens, multimodal]
     * 数据源自 dumate2api 的 dumate-catalog.default.json。
     */
    private const MODEL_META = [
        'deepseek-v4-pro'         => [1000000, 384000, false],
        'deepseek-v4-flash-0731'  => [200000, 32000, false],
        'deepseek-v41-flash'      => [1000000, 384000, true],
        'dm-auto-model/mm.L0'     => [1000000, 384000, true],
        'dm-auto-model/mm.L1'     => [1000000, 384000, true],
        'dm-auto-model/mm.L2'     => [1000000, 384000, true],
        'dm-auto-model/text.L0'   => [1000000, 384000, false],
        'dm-auto-model/text.L1'   => [1000000, 384000, false],
        'dm-auto-model/text.L2'   => [1000000, 384000, false],
        'doubao-seed-2-0-pro'     => [128000, 32000, false],
        'ernie-4.5-turbo-vl-32k'  => [32000, 8192, true],
        'glm-53'                  => [128000, 32000, false],
        'glm-53-flash'            => [128000, 32000, false],
        'kimi-k3'                 => [256000, 32000, false],
        'kimi-k31'                => [256000, 32000, false],
        'model-text'              => [128000, 32000, false],
        'qwen2.5-vl-32b-instruct' => [32000, 8192, true],
        'qwen3.5-27b'             => [128000, 32000, false],
        'qwen3.5-35b-a3b'         => [128000, 32000, false],
        'qwen3.5-397b-a17b'       => [128000, 32000, false],
        'qwen3.8-max'             => [200000, 32000, false],
        'qwen38-flash'            => [200000, 32000, false],
    ];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'dumate';
    }

    public function displayName(): string
    {
        return '百度搭子';
    }

    public function loginType(): string
    {
        return 'API_KEY';
    }

    public function hosts(): array
    {
        return ['www.dumate.cn', 'dumate.cn', 'console.bce.baidu.com', 'dumate-svc.baidu.com'];
    }

    public function supportsImageInput(): bool
    {
        return true;
    }

    // ---------------------------------------------------------
    // 凭据
    // ---------------------------------------------------------

    private function cookie(Account $account): string
    {
        $cookie = trim((string) $account->cred('cookie', ''));
        if ($cookie === '') {
            throw new \RuntimeException('百度搭子账号缺少 Cookie，请重新登录');
        }
        return $cookie;
    }

    /**
     * 网页请求头（签到/额度/token 换票共用）：cookie + 浏览器来源标记。
     */
    private function webHeaders(string $cookie): array
    {
        return [
            'Cookie'                 => $cookie,
            'Accept'                 => 'application/json, text/plain, */*',
            'X-Dumate-Client-Type'   => 'web',
            'Referer'                => self::WEB_BASE . '/app',
            'Origin'                 => self::WEB_BASE,
            'User-Agent'             => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ];
    }

    /**
     * 取（或复用）网关 Bearer token。token 缓存在凭据里，临期自动换票。
     */
    private function appToken(Account $account): string
    {
        $token = trim((string) $account->cred('token', ''));
        $expireAt = (int) $account->cred('token_expire_at', 0);
        if ($token !== '' && $expireAt > now_ms() + 5 * 60 * 1000) {
            return $token;
        }

        $cookie = $this->cookie($account);
        $res = $this->http()->withHeaders($this->webHeaders($cookie))->timeout(15)
            ->get(self::WEB_BASE . '/api/dumate/app/token/get');

        if (!$res->ok()) {
            throw new \RuntimeException('百度搭子取 token 失败（HTTP ' . $res->status() . '）：' . $res->snippet());
        }

        $json = $res->json();
        $result = $json['result'] ?? $json['data'] ?? $json;
        $token = trim((string) ($result['token'] ?? ''));
        if ($token === '') {
            $msg = $json['message'] ?? $res->snippet();
            throw new \RuntimeException('百度搭子取 token 失败：' . $msg);
        }

        $expiredAt = (string) ($result['expired_at'] ?? '');
        $expireMs = $expiredAt !== '' ? (strtotime($expiredAt) * 1000) : (now_ms() + 50 * 60 * 1000);
        if ($expireMs <= 0) {
            $expireMs = now_ms() + 50 * 60 * 1000;
        }

        $this->accountRepository->patchCredential($account, [
            'token'           => $token,
            'token_expire_at' => $expireMs,
        ]);

        return $token;
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = $this->appToken($account);

        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        $headers = [
            'Authorization'         => 'Bearer ' . $token,
            'Content-Type'          => 'application/json',
            'Accept'                => 'text/event-stream',
            'X-Dumate-Client-Type'  => 'web',
            'X-Dumate-Account-Id'   => 'global',
        ];

        return $this->parseOpenAiSse(self::GATEWAY_BASE . '/chat/completions', $payload, $headers, $account, $onDelta);
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        return array_keys(self::MODEL_META);
    }

    public function modelCatalog(Account $account): array
    {
        $out = [];
        foreach (self::MODEL_META as $modelId => [$ctx, $maxOut, $multimodal]) {
            $out[] = [
                'model_id'         => $modelId,
                'display_name'     => $modelId,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $ctx,
                'max_tokens'       => $maxOut,
                'multimodal'       => $multimodal,
                'reasoning'        => str_contains($modelId, 'pro'),
                'capabilities'     => array_values(array_filter(['stream', $multimodal ? 'vision' : null])),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------
    // 额度
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $cookie = $this->cookie($account);
            $res = $this->http()->withHeaders($this->webHeaders($cookie))->timeout(20)
                ->get(self::WEB_BASE . '/api/dumate/points/quota_overview?clientType=desktop&timezone=Asia/Shanghai');

            if ($res->ok()) {
                $json = $res->json();
                $result = $json['result'] ?? $json['data'] ?? $json;
                if (is_array($result) && array_key_exists('totalPoints', $result)) {
                    $total = $result['totalPoints'];
                    $used = $result['usedPoints'] ?? 0;
                    $total = is_numeric($total) ? (float) $total : null;
                    $used = is_numeric($used) ? (float) $used : 0.0;
                    if ($total !== null) {
                        $remain = max(0.0, $total - $used);
                        $packs = [];
                        foreach (($result['subscription'] ?? []) as $sub) {
                            if (is_array($sub)) {
                                $packs[] = [
                                    'name'   => ($sub['packageType'] ?? '') === 'plan_experience' ? '体验套餐' : '订阅套餐',
                                    'group'  => 'subscription',
                                    'limit'  => (float) ($sub['totalPoints'] ?? 0),
                                    'used'   => (float) ($sub['usedPoints'] ?? 0),
                                    'remain' => (float) ($sub['totalPoints'] ?? 0) - (float) ($sub['usedPoints'] ?? 0),
                                ];
                            }
                        }
                        foreach (($result['incremental'] ?? []) as $inc) {
                            if (is_array($inc)) {
                                $source = (string) ($inc['source'] ?? '');
                                $label = match ($source) {
                                    'login_bonus' => '每日登录',
                                    'grant_point' => '奖励积分',
                                    'event_bonus' => '活动奖励',
                                    default       => '积分',
                                };
                                $packs[] = [
                                    'name'   => $label,
                                    'group'  => 'incremental',
                                    'limit'  => (float) ($inc['totalPoints'] ?? 0),
                                    'used'   => (float) ($inc['usedPoints'] ?? 0),
                                    'remain' => (float) ($inc['totalPoints'] ?? 0) - (float) ($inc['usedPoints'] ?? 0),
                                ];
                            }
                        }
                        return new CreditInfo(
                            known: true,
                            balance: round($remain, 2),
                            detail: sprintf('总量 %s · 已用 %s · 剩余 %s', $total, $used, $remain),
                            packs: $packs,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('百度搭子查询额度失败', ['uid' => $account->uid, 'error' => $e->getMessage()]);
        }

        return CreditInfo::unknown('百度搭子额度查询失败');
    }

    // ---------------------------------------------------------
    // 动作：签到
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        if ($action === 'checkin') {
            return $this->checkin($account);
        }

        if ($action === 'test' || $action === 'connectivity') {
            try {
                $token = $this->appToken($account);
                return ['ok' => true, 'message' => '连通正常，token 换票成功', 'data' => ['token_prefix' => substr($token, 0, 8) . '...']];
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => '连通失败：' . $e->getMessage(), 'data' => null];
            }
        }

        return parent::action($account, $action, $params);
    }

    private function checkin(Account $account): array
    {
        try {
            $cookie = $this->cookie($account);
            $headers = $this->webHeaders($cookie);
            $today = date('Y-m-d');

            $info = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::WEB_BASE . '/api/dumate/points/loginBonusInfo');
            $json = $info->json();
            $result = $json['result'] ?? $json['data'] ?? $json;
            $days = $result['signInDays'] ?? [];
            foreach ((array) $days as $d) {
                if (is_string($d) && str_starts_with($d, $today)) {
                    return ['ok' => true, 'message' => '今日已签到', 'data' => null];
                }
            }

            $claim = $this->http()->withHeaders($headers)->timeout(20)
                ->postJson(self::WEB_BASE . '/api/dumate/points/loginBonus', new \stdClass());

            // 复核：再查一次，signInDays 含今天即成功
            $info2 = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::WEB_BASE . '/api/dumate/points/loginBonusInfo');
            $result2 = $info2->json()['result'] ?? $info2->json()['data'] ?? $info2->json();
            foreach ((array) ($result2['signInDays'] ?? []) as $d) {
                if (is_string($d) && str_starts_with($d, $today)) {
                    return ['ok' => true, 'message' => '签到成功', 'data' => null];
                }
            }

            $msg = $claim->json()['message'] ?? '领取未确认成功';
            return ['ok' => false, 'message' => '签到未成功：' . $msg, 'data' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '签到失败：' . $e->getMessage(), 'data' => null];
        }
    }

    // ---------------------------------------------------------
    // Token：cookie 长期有效，无需刷新
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        return [];
    }

    // ---------------------------------------------------------
    // 登录：手工粘贴 Cookie
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'pending',
            message: '请粘贴百度搭子网页 Cookie（BDUSS 等，含 bce-user-info）',
            extra: ['need_cookie' => true]
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $providerKey = trim((string) ($params['provider_key'] ?? $this->id()));
        $cookie = trim((string) ($params['cookie'] ?? ''));
        $nickname = trim((string) ($params['nickname'] ?? ''));

        if ($cookie === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $providerKey,
                loginType: $this->loginType(),
                state: 'failed',
                message: 'Cookie 为必填',
                error: 'missing_cookie'
            );
        }

        $uid = 'dm_' . substr(hash('sha256', $cookie), 0, 16);
        $this->accountRepository->upsertCredential(
            $providerKey,
            $uid,
            ['cookie' => $cookie],
            ['added_at' => now_ms()],
            $nickname !== '' ? $nickname : ('搭子-' . substr($uid, 3, 6))
        );

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $providerKey,
            loginType: $this->loginType(),
            state: 'success',
            message: '百度搭子账号已保存',
            accountUid: $uid,
            nickname: $nickname
        );
    }

    public function ingestCredential(array $credential): array
    {
        $cookie = trim((string) ($credential['cookie'] ?? ''));
        if ($cookie === '') {
            return ['ok' => false, 'message' => '缺少 cookie'];
        }
        $uid = 'dm_' . substr(hash('sha256', $cookie), 0, 16);
        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            ['cookie' => $cookie],
            ['added_at' => now_ms()],
            $credential['nickname'] ?? ('搭子-' . substr($uid, 3, 6))
        );
        return ['ok' => true, 'message' => '百度搭子账号已导入', 'account_uid' => $uid, 'account_id' => $accountId];
    }
}
