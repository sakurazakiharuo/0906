<?php
/**
 * みんなの MY推しランキング（公開フォルダの ranking.php → oshi_web.php から読み込まれる）
 * MY推しに登録した人の数をチャンネルごとに数えて並べる。だれでも見られる（ログイン不要）
 * 現在の登録者数は YouTube に確認するが、だれでも開けるページなので 1 時間キャッシュする
 */

const RANKING_LIMIT = 100;
const RANKING_CACHE_TTL = 3600;

$db = oshi_db();
$rows = $db->query('SELECT o.channel_id, COUNT(*) AS n, MIN(o.registered_at) AS first_at,
        (SELECT channel_title FROM oshi WHERE channel_id = o.channel_id ORDER BY registered_at DESC LIMIT 1) AS title,
        (SELECT subs_at_registration FROM oshi WHERE channel_id = o.channel_id AND hidden_at_registration = 0 ORDER BY registered_at ASC LIMIT 1) AS first_subs
    FROM oshi o GROUP BY o.channel_id ORDER BY n DESC, first_at ASC LIMIT ' . RANKING_LIMIT)->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = (int)$db->query('SELECT COUNT(DISTINCT user_id) FROM oshi')->fetchColumn();

// 現在の登録者数（1 時間キャッシュ）
$cacheFile = oshi_data_dir() . '/ranking_cache.json';
$cache = is_file($cacheFile) ? (json_decode(file_get_contents($cacheFile), true) ?: []) : [];
$need = array_values(array_filter(array_column($rows, 'channel_id'), fn($id) => ($cache[$id]['at'] ?? 0) < time() - RANKING_CACHE_TTL));
if ($need) {
    foreach (oshi_youtube_channels($config, $need) as $id => $info) {
        $cache[$id] = $info + ['at' => time()];
    }
    file_put_contents($cacheFile . '.tmp', json_encode($cache, JSON_UNESCAPED_UNICODE));
    rename($cacheFile . '.tmp', $cacheFile);
}

// 自分の MY推し（ログインしていればボタンを出す）
$mine = [];
if ($user) {
    $st = $db->prepare('SELECT channel_id FROM oshi WHERE user_id = ?');
    $st->execute([$user['id']]);
    $mine = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/ranking.php')), '/') . '/';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-SPWQV0H3XJ"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-SPWQV0H3XJ');
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>みんなのMY推しランキング｜初期推し</title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .meta { color: #666; font-size: .85rem; margin: 0 0 16px; }
  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; background: #fff; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; white-space: nowrap; }
  th { background: #f0f0f0; font-size: .85rem; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  td.rank { font-weight: bold; color: #c00; }
  td.n { font-weight: bold; }
  .up { color: #2a7a2a; font-weight: bold; }
  a { color: #c00; text-decoration: none; }
  .oshibtn { font-size: .75rem; background: #fff; color: #c00; border: 1px solid #c00; border-radius: 999px; padding: 2px 9px; cursor: pointer; }
  .oshibtn.on { background: #c00; color: #fff; }
</style>
</head>
<body>
<h1>みんなのMY推しランキング</h1>
<p class="meta">
  MY推しに登録した人が多いチャンネルの順です（<?= number_format($totalUsers) ?> 人が登録）。
  <a href="<?= h($base) ?>">トップへ戻る</a>　｜　<a href="<?= h($base) ?>oshi.php"><?= $user ? 'MY推し一覧' : 'ログインして MY推しを登録' ?></a>
</p>
<?php if (!$rows): ?>
  <p>まだ MY推しが登録されていません。</p>
<?php else: ?>
<div class="wrap">
<table>
  <thead><tr><th>順位</th><th>チャンネル名</th><th>MY推し数</th><th>現在の登録者数</th><th>最初に MY推しされた日</th><th>そのときの登録者数</th><th>増えた人数</th><?php if ($user): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php $rank = 0; $prev = null; foreach ($rows as $i => $r):
      if ($r['n'] !== $prev) { $rank = $i + 1; $prev = $r['n']; } // 同じ数なら同じ順位
      $cur = $cache[$r['channel_id']] ?? null;
      $curSubs = $cur['subs'] ?? null;
      $first = $r['first_subs'] === null ? null : (int)$r['first_subs'];
      $diff = ($curSubs !== null && $first !== null) ? $curSubs - $first : null; ?>
    <tr>
      <td class="num rank"><?= $rank ?></td>
      <td><a href="https://www.youtube.com/channel/<?= h($r['channel_id']) ?>" target="_blank" rel="noopener"><?= h($cur['title'] ?? $r['title']) ?></a></td>
      <td class="num n"><?= number_format($r['n']) ?> 人</td>
      <td class="num"><?= $cur === null ? '-' : ($curSubs === null ? '非公開' : number_format($curSubs) . ' 人') ?></td>
      <td><?= h(substr($r['first_at'], 0, 10)) ?></td>
      <td class="num"><?= $first === null ? '非公開' : number_format($first) . ' 人' ?></td>
      <td class="num"><?= $diff === null ? '-' : '<span class="' . ($diff > 0 ? 'up' : '') . '">' . ($diff > 0 ? '+' : '') . number_format($diff) . '</span>' ?></td>
      <?php if ($user): ?><td><button type="button" class="oshibtn<?= isset($mine[$r['channel_id']]) ? ' on' : '' ?>" data-channel="<?= h($r['channel_id']) ?>"><?= isset($mine[$r['channel_id']]) ? '✓MY推し' : '＋MY推し' ?></button></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="meta">現在の登録者数は 1 時間ごとに YouTube で確認しています（1,000 人以上は YouTube 側で上 3 桁に丸められます）。</p>
<?php endif; ?>
<script>
document.querySelectorAll('.oshibtn').forEach(function (b) {
  b.addEventListener('click', function () {
    var on = b.classList.contains('on');
    b.disabled = true;
    fetch(<?= json_encode($base . 'oshi.php') ?> + '?action=' + (on ? 'remove' : 'add'), { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Oshi': '1' }, body: JSON.stringify({ channel_id: b.dataset.channel }) })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); b.classList.toggle('on', !on); b.textContent = on ? '＋MY推し' : '✓MY推し'; })
      .catch(function (e) { alert(e.message); })
      .then(function () { b.disabled = false; });
  });
});
</script>
</body>
</html>
