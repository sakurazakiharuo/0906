<?php
/**
 * 初期推し機能の部品: データベース・ログイン状態（署名付き Cookie）・Google ログイン（OpenID Connect）・YouTube のチャンネル情報
 *
 * ログイン状態は PHP のセッションを使わず、改ざんできない署名付き Cookie で持つ
 * （サーバにセッションファイルを作らない。PHP2Shell で狙われたセッション周りの機能も使わない）
 */

const OSHI_COOKIE = 'oshi_auth';
const OSHI_OAUTH_COOKIE = 'oshi_oauth';
const OSHI_MAX_PER_USER = 1000;

function oshi_data_dir(): string
{
    $dir = APP_DIR . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

// Cookie の署名に使う秘密の鍵（初回に自動で作り、data/secret.key に保存）
function oshi_secret(): string
{
    $file = oshi_data_dir() . '/secret.key';
    if (!is_file($file)) {
        file_put_contents($file, bin2hex(random_bytes(32)));
        chmod($file, 0600);
    }
    return trim(file_get_contents($file));
}

function oshi_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . oshi_data_dir() . '/oshi.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    // id は「google:」＋ Google アカウントの番号（sub）
    $pdo->exec('CREATE TABLE IF NOT EXISTS accounts (
        id TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL, last_login TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oshi (
        user_id TEXT NOT NULL, channel_id TEXT NOT NULL,
        channel_title TEXT NOT NULL DEFAULT "", channel_published TEXT NOT NULL DEFAULT "",
        subs_at_registration INTEGER, hidden_at_registration INTEGER NOT NULL DEFAULT 0,
        registered_at TEXT NOT NULL,
        PRIMARY KEY (user_id, channel_id))');
    return $pdo;
}

// ---- 署名付きの値（Cookie 用） ----

function oshi_sign(array $data): string
{
    $payload = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    return $payload . '.' . hash_hmac('sha256', $payload, oshi_secret());
}

function oshi_verify(string $value): ?array
{
    $parts = explode('.', $value);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], oshi_secret()), $parts[1])) {
        return null;
    }
    $data = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function oshi_set_cookie(string $name, string $value, int $ttl): void
{
    setcookie($name, $value, [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ログイン中のユーザー（['id', 'name']）。ログインしていなければ null
function oshi_current_user(): ?array
{
    $raw = $_COOKIE[OSHI_COOKIE] ?? '';
    if (!is_string($raw) || $raw === '' || strlen($raw) > 1000) {
        return null;
    }
    $data = oshi_verify($raw);
    return ($data && isset($data['id'], $data['name'])) ? ['id' => (string)$data['id'], 'name' => (string)$data['name']] : null;
}

// ---- HTTP ----

function oshi_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $res === false ? null : json_decode($res, true)];
}

// ---- Google ログイン（OAuth 2.0 / OpenID Connect、Authorization Code + PKCE） ----

function oshi_google_settings(array $config): array
{
    $site = rtrim($config['site_url'] ?? 'https://song.nakamurakozo.tv/', '/') . '/';
    return [
        'client_id' => $config['google_client_id'] ?? '',
        'client_secret' => $config['google_client_secret'] ?? '',
        'redirect_uri' => $config['google_redirect_uri'] ?? $site . 'oshi-callback.php',
        'authorize_url' => $config['google_authorize_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => $config['google_token_url'] ?? 'https://oauth2.googleapis.com/token',
        'userinfo_url' => $config['google_userinfo_url'] ?? 'https://openidconnect.googleapis.com/v1/userinfo',
        'site' => $site,
    ];
}

// Google のログイン画面の URL を作り、確認用の値（state と PKCE の code_verifier）を短時間だけ Cookie に入れる
// 受け取る情報は Google アカウントの番号と表示名だけ（scope: openid profile。メールアドレスは受け取らない）
function oshi_login_url(array $config, string $return): string
{
    $g = oshi_google_settings($config);
    $state = bin2hex(random_bytes(16));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    oshi_set_cookie(OSHI_OAUTH_COOKIE, oshi_sign(['state' => $state, 'verifier' => $verifier, 'return' => $return, 'exp' => time() + 600]), 600);
    return $g['authorize_url'] . '?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $g['client_id'],
        'redirect_uri' => $g['redirect_uri'],
        'scope' => 'openid profile',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

// Google から戻ってきたときの処理。成功したらユーザー情報、失敗したらエラーの文字列を返す
function oshi_handle_callback(array $config, array $query)
{
    $g = oshi_google_settings($config);
    $saved = oshi_verify(is_string($_COOKIE[OSHI_OAUTH_COOKIE] ?? null) ? $_COOKIE[OSHI_OAUTH_COOKIE] : '');
    oshi_set_cookie(OSHI_OAUTH_COOKIE, '', 0);
    $code = $query['code'] ?? '';
    $state = $query['state'] ?? '';
    if (!$saved || !is_string($code) || !is_string($state) || $code === '' || !hash_equals($saved['state'], $state)) {
        return 'ログインの確認に失敗しました。もう一度お試しください。';
    }
    [$status, $token] = oshi_http('POST', $g['token_url'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $g['redirect_uri'],
        'code_verifier' => $saved['verifier'],
        'client_id' => $g['client_id'],
        'client_secret' => $g['client_secret'],
    ]));
    if ($status !== 200 || empty($token['access_token'])) {
        return "Google からアクセス許可を受け取れませんでした（HTTP $status）。";
    }
    [$status, $info] = oshi_http('GET', $g['userinfo_url'], ['Authorization: Bearer ' . $token['access_token']]);
    if ($status !== 200 || empty($info['sub'])) {
        return "Google のアカウント情報を取得できませんでした（HTTP $status）。";
    }
    // アクセストークンは保存しない（ログインの確認にだけ使う）
    $id = 'google:' . $info['sub'];
    $name = mb_substr((string)($info['name'] ?? $info['given_name'] ?? ''), 0, 50, 'UTF-8');
    if ($name === '') {
        $name = 'ユーザー';
    }
    $now = date('Y-m-d H:i:s');
    oshi_db()->prepare('INSERT INTO accounts (id, name, created_at, last_login) VALUES (?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET name = excluded.name, last_login = excluded.last_login')
        ->execute([$id, $name, $now, $now]);
    oshi_set_cookie(OSHI_COOKIE, oshi_sign(['id' => $id, 'name' => $name, 'exp' => time() + 30 * 86400]), 30 * 86400);
    return ['id' => $id, 'name' => $name, 'return' => $saved['return']];
}

// ---- YouTube ----

// チャンネル情報（タイトル・開設日・登録者数）を YouTube に問い合わせる（channels.list: 1 ユニット/50 件）
function oshi_youtube_channels(array $config, array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
        [$status, $res] = oshi_http('GET', 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
            'key' => $config['api_key'], 'part' => 'snippet,statistics', 'id' => implode(',', $chunk),
        ]));
        foreach (($status === 200 ? $res['items'] ?? [] : []) as $c) {
            $out[$c['id']] = [
                'title' => $c['snippet']['title'] ?? '',
                'published' => isset($c['snippet']['publishedAt']) ? date('Y-m-d', strtotime($c['snippet']['publishedAt'])) : '',
                'subs' => !empty($c['statistics']['hiddenSubscriberCount']) ? null : (int)($c['statistics']['subscriberCount'] ?? 0),
            ];
        }
    }
    return $out;
}
