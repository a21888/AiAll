<?php
/**
 * 有道 LobsterAI 适配器
 *
 * 登录方式：API_KEY —— 手工粘贴 accessToken（可选 refreshToken / uuid / keyfrom）。
 *
 * 链路（逆向自 lobsterai2api（MIT）/ AIAutomator（MIT））：
 *   1) 对话 → POST https://lobsterai-server.youdao.com/api/proxy/v1/chat/completions
 *      Authorization: Bearer <accessToken>
 *      X-LobsterAI-Client-Capabilities / X-LobsterAI-Client-Version
 *      OpenAI 兼容，上游仅支持 SSE（force stream=true）
 *   2) 模型 → GET /api/models/available → { code, data: [{ modelId, ... }] }
 *   3) 额度 → GET /api/user/profile-summary?firstKeyfrom=official
 *      → { code, data: { totalCreditsRemaining, creditItems } }
 *   4) 签到 → GET slot → GET context → POST actions/check_in（幂等，+100 积分/天）
 *   5) 刷新 → POST /api/auth/refresh（refreshToken）
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

class LobsterProvider extends AbstractProvider
{
    private const BASE = 'https://lobsterai-server.youdao.com';
    private const UPDATE_API = 'https://api-overmind.youdao.com/openapi/get/luna/hardware/lobsterai/prod/update';
    private const CLIENT_VERSION_FALLBACK = '2026.9.4';
    private const CLIENT_CAPABILITIES = 'kimi-k3-agentic-v1';

    /** @var list<string> 静态兜底模型（/api/models/available 不可用时） */
    private const FALLBACK_MODELS = [
        'deepseek-v4-pro',
        'deepseek-v4-flash',
        'deepseek-v41-flash',
        'kimi-k3',
        'kimi-k3-agentic-v1',
        'glm-53',
        'qwen3.8-max',
        'qwen38-flash',
    ];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'lobster';
    }

    public function displayName(): string
    {
        return '有道 LobsterAI';
    }

    public function loginType(): string
    {
        return 'API_KEY';
    }

    public function hosts(): array
    {
        return ['lobsterai-server.youdao.com', 'api-overmind.youdao.com'];
    }

    public function supportsImageInput(): bool
    {
        return false;
    }

    // ---------------------------------------------------------
    // 凭据
    // ---------------------------------------------------------

    private function accessToken(Account $account): string
    {
        $token = trim((string) $account->cred('accessToken', $account->cred('token', '')));
        if ($token === '') {
            throw new \RuntimeException('有道 LobsterAI 账号缺少 accessToken，请重新登录');
        }
        return $token;
    }

    private function chatHeaders(string $token): array
    {
        return [
            'Authorization'                    => 'Bearer ' . $token,
            'Content-Type'                     => 'application/json',
            'Accept'                           => 'text/event-stream, application/json',
            'X-LobsterAI-Client-Capabilities'  => self::CLIENT_CAPABILITIES,
            'X-LobsterAI-Client-Version'       => self::CLIENT_VERSION_FALLBACK,
            'User-Agent'                       => 'LobsterAI/' . self::CLIENT_VERSION_FALLBACK,
        ];
    }

    private function authHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'LobsterAI/' . self::CLIENT_VERSION_FALLBACK,
        ];
    }

    /**
     * 取官方当前客户端版本号（签到活动按版本下发，旧版本会拿 slotState=empty）。
     */
    private function clientVersion(): string
    {
        try {
            $res = $this->http()->timeout(10)->get(self::UPDATE_API);
            if ($res->ok()) {
                $data = $res->json();
                $v = $data['data']['value']['version'] ?? '';
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        } catch (\Throwable) {
            // 回退内置版本号
        }
        return self::CLIENT_VERSION_FALLBACK;
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = $this->accessToken($account);

        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        return $this->parseOpenAiSse(
            self::BASE . '/api/proxy/v1/chat/completions',
            $payload,
            $this->chatHeaders($token),
            $account,
            $onDelta
        );
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        try {
            $res = $this->http()->withBearer($this->accessToken($account))
                ->withHeader('Accept', 'application/json')
                ->withHeader('User-Agent', 'LobsterAI/' . self::CLIENT_VERSION_FALLBACK)
                ->timeout(20)
                ->get(self::BASE . '/api/models/available', $this->keyfromQuery($account));

            if ($res->ok()) {
                $data = $res->json();
                $items = $data['data'] ?? [];
                $ids = [];
                foreach ((array) $items as $item) {
                    if (is_array($item) && !empty($item['modelId'])) {
                        $ids[] = (string) $item['modelId'];
                    } elseif (is_string($item)) {
                        $ids[] = $item;
                    }
                }
                if (!empty($ids)) {
                    return $ids;
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('有道 LobsterAI 拉取模型失败，回退静态表', ['uid' => $account->uid, 'error' => $e->getMessage()]);
        }

        return self::FALLBACK_MODELS;
    }

    private function keyfromQuery(Account $account): array
    {
        $query = [
            'firstKeyfrom'  => (string) $account->cred('firstKeyfrom', 'official'),
            'latestKeyfrom' => (string) $account->cred('latestKeyfrom', ''),
        ];
        if ($account->cred('uuid', '') !== '') {
            $query['uuid'] = (string) $account->cred('uuid');
        }
        if ($account->cred('userId', '') !== '') {
            $query['userId'] = (string) $account->cred('userId');
        }
        return $query;
    }

    // ---------------------------------------------------------
    // 额度
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $res = $this->http()->withBearer($this->accessToken($account))
                ->withHeader('Accept', 'application/json')
                ->withHeader('User-Agent', 'LobsterAI/' . self::CLIENT_VERSION_FALLBACK)
                ->timeout(20)
                ->get(self::BASE . '/api/user/profile-summary', ['firstKeyfrom' => 'official']);

            if ($res->ok()) {
                $data = $res->json();
                $body = $data['data'] ?? $data;
                $total = $body['totalCreditsRemaining'] ?? null;
                if ($total !== null && is_numeric($total)) {
                    $packs = [];
                    foreach (($body['creditItems'] ?? []) as $item) {
                        if (is_array($item)) {
                            $packs[] = [
                                'name'   => (string) ($item['label'] ?? '积分'),
                                'group'  => 'credit',
                                'limit'  => (float) ($item['creditsRemaining'] ?? 0),
                                'used'   => 0.0,
                                'remain' => (float) ($item['creditsRemaining'] ?? 0),
                            ];
                        }
                    }
                    return new CreditInfo(
                        known: true,
                        balance: round((float) $total, 2),
                        detail: '剩余积分 ' . round((float) $total, 2),
                        packs: $packs,
                    );
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('有道 LobsterAI 查询额度失败', ['uid' => $account->uid, 'error' => $e->getMessage()]);
        }

        return CreditInfo::unknown('有道 LobsterAI 额度查询失败');
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
            return $this->probe($account);
        }

        return parent::action($account, $action, $params);
    }

    /**
     * 连通性自检：拉模型 + 真实对话网关探针（流式，收到首帧即中断）。
     * 上游仅支持 SSE，故探针走流式并在首帧后主动停止，尽量少耗额度。
     */
    private function probe(Account $account): array
    {
        try {
            $models = $this->models($account);
            $model = $models[0] ?? 'deepseek-v4-pro';
            $got = false;
            try {
                $this->chat($account, new ChatRequest(
                    model: $model,
                    messages: [['role' => 'user', 'content' => 'hi']],
                    stream: true,
                    maxTokens: 1,
                ), function () use (&$got) {
                    $got = true;
                    return false; // 收到首帧即中断
                });
            } catch (\Throwable $e) {
                if (!$got) {
                    return ['ok' => false, 'message' => '对话探针失败：' . $e->getMessage(), 'data' => null];
                }
            }
            return ['ok' => true, 'message' => '连通正常（accessToken + 对话网关均可用）', 'data' => ['model' => $model]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '自检失败：' . $e->getMessage(), 'data' => null];
        }
    }

    private function checkin(Account $account): array
    {
        try {
            $token = $this->accessToken($account);
            $version = $this->clientVersion();
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'LobsterAI/' . $version,
            ];

            // 1) 活动槽位
            $slot = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::BASE . '/api/client-activities/slot', [
                    'placement'           => 'desktop_sidebar',
                    'clientVersion'       => $version,
                    'containerApiVersion' => '2',
                    'platform'            => 'win32',
                ]);
            if (!$slot->ok()) {
                return ['ok' => false, 'message' => '查询活动失败（HTTP ' . $slot->status() . '）', 'data' => null];
            }
            $slotData = $slot->json()['data'] ?? [];
            $activity = $slotData['activity'] ?? [];
            $code = $activity['activityCode'] ?? '';
            $rev = $activity['configRevision'] ?? 0;
            if ($code === '' || ($slotData['slotState'] ?? '') !== 'available') {
                return ['ok' => false, 'message' => '当前无每日积分礼活动', 'data' => null];
            }

            // 2) 查询签到状态
            $ctx = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::BASE . "/api/client-activities/{$code}/context", ['configRevision' => $rev]);
            $ctxData = $ctx->json()['data'] ?? [];
            $state = $ctxData['state'] ?? [];
            if (!empty($state['claimedToday'])) {
                return ['ok' => true, 'message' => '今日已签到', 'data' => null];
            }

            // 3) 执行签到（幂等键防重复发放）
            $body = [
                'configRevision'  => $rev,
                'idempotencyKey'  => 'daily-check-in-' . bin2hex(random_bytes(16)),
                'payload'         => new \stdClass(),
            ];
            $claim = $this->http()->withHeaders(array_merge($headers, ['Content-Type' => 'application/json']))->timeout(20)
                ->postJson(self::BASE . "/api/client-activities/{$code}/actions/check_in", $body);

            $claimJson = $claim->json();
            if (($claimJson['code'] ?? 0) === 0) {
                $reward = $state['rewardCredits'] ?? 100;
                return ['ok' => true, 'message' => '签到成功，获得 ' . $reward . ' 积分', 'data' => null];
            }

            return ['ok' => false, 'message' => '签到失败：' . ($claimJson['message'] ?? $claim->snippet()), 'data' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '签到失败：' . $e->getMessage(), 'data' => null];
        }
    }

    // ---------------------------------------------------------
    // Token 刷新
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        $refresh = trim((string) $account->cred('refreshToken', ''));
        if ($refresh === '') {
            return [];
        }

        $body = [
            'refreshToken'  => $refresh,
            'firstKeyfrom'  => (string) $account->cred('firstKeyfrom', ''),
            'latestKeyfrom' => (string) $account->cred('latestKeyfrom', ''),
            'version'       => '0.1.0',
        ];
        if ($account->cred('uuid', '') !== '') {
            $body['uuid'] = (string) $account->cred('uuid');
        }
        if ($account->cred('userId', '') !== '') {
            $body['userId'] = (string) $account->cred('userId');
        }

        try {
            $res = $this->http()->withHeaders($this->authHeaders())->timeout(20)
                ->postJson(self::BASE . '/api/auth/refresh', $body);

            if ($res->ok()) {
                $json = $res->json();
                $data = $json['data'] ?? $json;
                if (!empty($data['accessToken'])) {
                    $patch = ['accessToken' => (string) $data['accessToken']];
                    if (!empty($data['refreshToken'])) {
                        $patch['refreshToken'] = (string) $data['refreshToken'];
                    }
                    return $patch;
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('有道 LobsterAI 刷新 token 失败', ['uid' => $account->uid, 'error' => $e->getMessage()]);
        }

        return [];
    }

    // ---------------------------------------------------------
    // 登录：手工粘贴 accessToken
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'pending',
            message: '请粘贴有道 LobsterAI 的 accessToken（可选 refreshToken / uuid / firstKeyfrom / latestKeyfrom）',
            extra: ['need_token' => true]
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $providerKey = trim((string) ($params['provider_key'] ?? $this->id()));
        $accessToken = trim((string) ($params['accessToken'] ?? $params['access_token'] ?? ''));
        $refreshToken = trim((string) ($params['refreshToken'] ?? $params['refresh_token'] ?? ''));
        $nickname = trim((string) ($params['nickname'] ?? ''));

        if ($accessToken === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $providerKey,
                loginType: $this->loginType(),
                state: 'failed',
                message: 'accessToken 为必填',
                error: 'missing_token'
            );
        }

        $uid = 'lb_' . substr(hash('sha256', $accessToken), 0, 16);
        $credential = [
            'accessToken'   => $accessToken,
            'refreshToken'  => $refreshToken,
            'uuid'          => trim((string) ($params['uuid'] ?? '')),
            'firstKeyfrom'  => trim((string) ($params['firstKeyfrom'] ?? '')),
            'latestKeyfrom' => trim((string) ($params['latestKeyfrom'] ?? '')),
            'userId'        => trim((string) ($params['userId'] ?? '')),
        ];
        $this->accountRepository->upsertCredential(
            $providerKey,
            $uid,
            $credential,
            ['added_at' => now_ms()],
            $nickname !== '' ? $nickname : ('Lobster-' . substr($uid, 3, 6))
        );

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $providerKey,
            loginType: $this->loginType(),
            state: 'success',
            message: '有道 LobsterAI 账号已保存',
            accountUid: $uid,
            nickname: $nickname
        );
    }

    public function ingestCredential(array $credential): array
    {
        $accessToken = trim((string) ($credential['accessToken'] ?? $credential['access_token'] ?? ''));
        if ($accessToken === '') {
            return ['ok' => false, 'message' => '缺少 accessToken'];
        }
        $uid = 'lb_' . substr(hash('sha256', $accessToken), 0, 16);
        $stored = [
            'accessToken'   => $accessToken,
            'refreshToken'  => trim((string) ($credential['refreshToken'] ?? $credential['refresh_token'] ?? '')),
            'uuid'          => trim((string) ($credential['uuid'] ?? '')),
            'firstKeyfrom'  => trim((string) ($credential['firstKeyfrom'] ?? '')),
            'latestKeyfrom' => trim((string) ($credential['latestKeyfrom'] ?? '')),
            'userId'        => trim((string) ($credential['userId'] ?? '')),
        ];
        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            $stored,
            ['added_at' => now_ms()],
            $credential['nickname'] ?? ('Lobster-' . substr($uid, 3, 6))
        );
        return ['ok' => true, 'message' => '有道 LobsterAI 账号已导入', 'account_uid' => $uid, 'account_id' => $accountId];
    }
}
