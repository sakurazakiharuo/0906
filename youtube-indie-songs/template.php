<?php
/** @var array $rows */
/** @var string $updatedAt */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>最初の推しになる</title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .meta { color: #666; font-size: .85rem; margin-bottom: 16px; }
  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; background: #fff; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
  th { background: #f0f0f0; font-size: .85rem; white-space: nowrap; }
  td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  a { color: #c00; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .empty { padding: 24px; text-align: center; color: #888; }
</style>
</head>
<body>
<h1>最初の推しになる</h1>
<p class="meta">
  登録者 0〜300 人 / 3 日以内の投稿 / 再生数の少ない順　｜　最終更新: <?= h($updatedAt) ?>（<?= count($rows) ?> 件）
</p>
<div class="wrap">
<table>
  <thead>
    <tr><th>#</th><th>アーティスト名</th><th>登録者数</th><th>動画タイトル</th><th>再生数</th><th>投稿日時</th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="6" class="empty">該当する動画がありません</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td class="num"><?= $i + 1 ?></td>
      <td><a href="<?= h($r['channel_url']) ?>" target="_blank" rel="noopener"><?= h($r['artist']) ?></a></td>
      <td class="num"><?= number_format($r['subscribers']) ?></td>
      <td><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['title']) ?></a></td>
      <td class="num"><?= number_format($r['views']) ?></td>
      <td class="num"><?= h($r['published']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="meta">データ提供: YouTube Data API</p>
</body>
</html>
