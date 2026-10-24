<?php
/**
 * 初期推し機能の入り口（公開フォルダの oshi.php・oshi-callback.php から読み込まれる。fetch.php が自動で作る）
 *
 *   oshi.php?action=login&return=…  Google のログイン画面へ
 *   oshi-callback.php                Google から戻ってきたとき（ログイン完了）
 *   oshi.php?action=logout           ログアウト
 *   oshi.php?action=me               ログイン状態と登録済みチャンネル（JSON。公開ページのボタン用）
 *   oshi.php?action=add / remove     初期推し登録・取り消し（POST・JSON）
 *   oshi.php                         自分の初期推し一覧
 */

date_default_timezone_set('Asia/Tokyo');
$config = require APP_DIR . '/config.php';
require APP_DIR . '/functions.php';
require APP_DIR . '/oshi_lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function oshi_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// サイト内のページにだけ戻す（外部サイトへの転送に使われないように）
function oshi_safe_return($value): string
{
    return (is_string($value) && preg_match('#^/[A-Za-z0-9_./-]*$#', $value) && strpos($value, '//') === false) ? $value : '/';
}

// Origin ヘッダーの「ホスト名:ポート」（Host ヘッダーと比べる用）
function oshi_origin_host(string $origin): string
{
    $u = parse_url($origin);
    return strtolower(($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    oshi_json(405, ['error' => 'method not allowed']);
}

if (defined('OSHI_CALLBACK')) {
    $result = oshi_handle_callback($config, $_GET);
    if (is_array($result)) {
        header('Location: ' . oshi_safe_return($result['return']));
        exit;
    }
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ログインエラー</title>'
        . '<p>' . h($result) . '</p><p><a href="./">トップへ戻る</a></p>';
    exit;
}

$action = $_GET['action'] ?? '';
if (!is_string($action)) {
    oshi_json(400, ['error' => 'invalid action']);
}
$user = oshi_current_user();

switch ($action) {
    case 'login':
        if (($config['google_client_id'] ?? '') === '') {
            oshi_json(503, ['error' => 'Google ログインの設定（google_client_id）がありません']);
        }
        header('Location: ' . oshi_login_url($config, oshi_safe_return($_GET['return'] ?? '/')));
        exit;

    case 'logout':
        oshi_set_cookie(OSHI_COOKIE, '', 0);
        header('Location: ' . oshi_safe_return($_GET['return'] ?? '/'));
        exit;

    case 'me':
        if (!$user) {
            oshi_json(200, ['logged_in' => false]);
        }
        $st = oshi_db()->prepare('SELECT channel_id FROM oshi WHERE user_id = ?');
        $st->execute([$user['id']]);
        oshi_json(200, ['logged_in' => true, 'name' => $user['name'], 'oshi' => $st->fetchAll(PDO::FETCH_COLUMN)]);

    case 'add':
    case 'remove':
        // 他のサイトから勝手に登録させられないよう、JSON・専用ヘッダー・同じサイトからの送信だけ受け付ける
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($method !== 'POST'
            || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json'
            || ($_SERVER['HTTP_X_OSHI'] ?? '') !== '1'
            || ($origin !== '' && oshi_origin_host($origin) !== strtolower($host))
            || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024) {
            oshi_json(400, ['error' => 'bad request']);
        }
        if (!$user) {
            oshi_json(401, ['error' => 'login required']);
        }
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 1025), true, 3);
        $channelId = is_array($in) ? ($in['channel_id'] ?? '') : '';
        if (!is_string($channelId) || !preg_match('/^UC[A-Za-z0-9_-]{22}$/', $channelId)) {
            oshi_json(400, ['error' => 'invalid channel']);
        }
        $db = oshi_db();
        if ($action === 'remove') {
            $db->prepare('DELETE FROM oshi WHERE user_id = ? AND channel_id = ?')->execute([$user['id'], $channelId]);
            oshi_json(200, ['ok' => true]);
        }
        $count = $db->prepare('SELECT COUNT(*) FROM oshi WHERE user_id = ?');
        $count->execute([$user['id']]);
        if ((int)$count->fetchColumn() >= OSHI_MAX_PER_USER) {
            oshi_json(400, ['error' => 'too many']);
        }
        // 登録した瞬間の登録者数を YouTube に確認しにいく
        $info = oshi_youtube_channels($config, [$channelId])[$channelId] ?? null;
        if (!$info) {
            oshi_json(502, ['error' => 'YouTube でチャンネルを確認できませんでした']);
        }
        $db->prepare('INSERT OR IGNORE INTO oshi (user_id, channel_id, channel_title, channel_published, subs_at_registration, hidden_at_registration, registered_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['id'], $channelId, $info['title'], $info['published'], $info['subs'], $info['subs'] === null ? 1 : 0, date('Y-m-d H:i:s')]);
        oshi_json(200, ['ok' => true, 'subs' => $info['subs']]);

    case '':
        break; // 一覧ページ

    default:
        oshi_json(400, ['error' => 'invalid action']);
}

// ---- 自分の初期推し一覧 ----
$rows = [];
if ($user) {
    $st = oshi_db()->prepare('SELECT * FROM oshi WHERE user_id = ? ORDER BY registered_at DESC');
    $st->execute([$user['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 現在の登録者数を YouTube に確認しにいく（50 件ごとに 1 ユニット）
    $now = $rows ? oshi_youtube_channels($config, array_column($rows, 'channel_id')) : [];
}
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/oshi.php')), '/') . '/';
$self = $base . 'oshi.php';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>初期推し一覧</title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .meta { color: #666; font-size: .85rem; margin: 0 0 16px; }
  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; background: #fff; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; white-space: nowrap; }
  th { background: #f0f0f0; font-size: .85rem; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .up { color: #2a7a2a; font-weight: bold; }
  a { color: #c00; text-decoration: none; }
  .btn { display: inline-block; background: #000; color: #fff; border-radius: 999px; padding: 8px 18px; }
  .rm { font-size: .75rem; color: #888; background: none; border: 1px solid #ccc; border-radius: 999px; padding: 2px 8px; cursor: pointer; }
</style>
</head>
<body>
<h1>初期推し一覧</h1>
<?php if (!$user): ?>
  <p class="meta">初期推しの登録・一覧には Google アカウントでのログインが必要です。</p>
  <p><a class="btn" href="<?= h($self) ?>?action=login&amp;return=<?= h(rawurlencode($self)) ?>">Google でログイン</a></p>
  <p><a href="<?= h($base) ?>">← トップへ戻る</a></p>
<?php else: ?>
  <p class="meta"><?= h($user['name']) ?> さんの初期推し（<?= count($rows) ?> チャンネル）　｜　<a href="<?= h($base) ?>">トップへ戻る</a>　｜　<a href="<?= h($self) ?>?action=logout">ログアウト</a></p>
  <?php if (!$rows): ?>
    <p>まだ初期推しがありません。リストの「＋初期推し」ボタンで登録できます。</p>
  <?php else: ?>
  <div class="wrap">
  <table>
    <thead><tr><th>チャンネル名</th><th>チャンネル開始日</th><th>現在の登録者数</th><th>初期推し登録日</th><th>登録時の登録者数</th><th>増えた人数</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $cur = $now[$r['channel_id']] ?? null;
        $curSubs = $cur['subs'] ?? null;
        $then = $r['hidden_at_registration'] ? null : $r['subs_at_registration'];
        $diff = ($curSubs !== null && $then !== null) ? $curSubs - (int)$then : null; ?>
      <tr>
        <td><a href="https://www.youtube.com/channel/<?= h($r['channel_id']) ?>" target="_blank" rel="noopener"><?= h($cur['title'] ?? $r['channel_title']) ?></a></td>
        <td><?= h($cur['published'] ?? $r['channel_published']) ?></td>
        <td class="num"><?= $cur === null ? '取得できず' : ($curSubs === null ? '非公開' : number_format($curSubs) . ' 人') ?></td>
        <td><?= h(substr($r['registered_at'], 0, 16)) ?></td>
        <td class="num"><?= $then === null ? '非公開' : number_format((int)$then) . ' 人' ?></td>
        <td class="num"><?= $diff === null ? '-' : '<span class="' . ($diff > 0 ? 'up' : '') . '">' . ($diff > 0 ? '+' : '') . number_format($diff) . '</span>' ?></td>
        <td><button class="rm" data-channel="<?= h($r['channel_id']) ?>">取り消す</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="meta">登録者数は YouTube の公開数値です（1,000 人以上は YouTube 側で上 3 桁に丸められます）。</p>
  <?php endif; ?>
<?php endif; ?>
<script>
document.querySelectorAll('.rm').forEach(function (b) {
  b.addEventListener('click', function () {
    if (!confirm('このチャンネルを初期推しから外しますか？')) return;
    fetch(<?= json_encode($self) ?> + '?action=remove', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Oshi': '1' },
      body: JSON.stringify({ channel_id: b.dataset.channel }) })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); b.closest('tr').remove(); })
      .catch(function (e) { alert('取り消せませんでした: ' + e.message); });
  });
});
</script>
</body>
</html>
