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
<title>初期推し（YouTube歌動画低再生数リスト）</title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  h1 .sub { display: block; font-size: .85rem; font-weight: normal; color: #666; margin-top: 2px; }
  .meta { color: #666; font-size: .85rem; margin-bottom: 16px; }
  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; background: #fff; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
  th { background: #f0f0f0; font-size: .85rem; white-space: nowrap; }
  td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  a { color: #c00; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .empty { padding: 24px; text-align: center; color: #888; }
  h2 { font-size: 1.1rem; margin: 24px 0 4px; }
  .note { color: #666; font-size: .8rem; margin: 0 0 10px; }
  .picks { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin-bottom: 8px; }
  .pick { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 12px; }
  .pick .t { font-weight: bold; display: block; margin-bottom: 4px; }
  .pick .a { font-size: .85rem; color: #444; }
  .pick .s { font-size: .8rem; color: #666; margin: 4px 0 6px; }
  .xbtn { display: inline-block; margin-top: 8px; font-size: .8rem; background: #000; color: #fff; border-radius: 999px; padding: 4px 12px; }
  .xbtn:hover { text-decoration: none; opacity: .85; }
  .tag { display: inline-block; font-size: .72rem; background: #fdecec; color: #a00; border-radius: 4px; padding: 1px 6px; margin: 2px 4px 0 0; }
</style>
</head>
<body>
<h1>初期推し<span class="sub">YouTube歌動画低再生数リスト</span></h1>
<p class="meta">
  登録者 0〜300 人 / 3 日以内の投稿 / 再生数の少ない順　｜　最終更新: <?= h($updatedAt) ?>（<?= count($rows) ?> 件）
</p>
<?php if (!empty($pickup)): ?>
<h2>ピックアップ</h2>
<p class="note">登録者 <?= h($config['pickup_max_subscribers']) ?> 人以下・投稿ペースが量産型でないチャンネルから、クレジットや歌詞・MV など作りこみの手がかりが多い動画を自動で選んでいます。</p>
<div class="picks">
<?php foreach ($pickup as $p): ?>
  <div class="pick">
    <a class="t" href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['title']) ?></a>
    <a class="a" href="<?= h($p['channel_url']) ?>" target="_blank" rel="noopener"><?= h($p['artist']) ?></a>
    <?php if ($p['x_handle']): ?><a class="a" href="https://x.com/<?= h($p['x_handle']) ?>" target="_blank" rel="noopener">@<?= h($p['x_handle']) ?></a><?php endif; ?>
    <div class="s">登録者 <?= number_format($p['subscribers']) ?> 人 ・ 再生 <?= number_format($p['views']) ?> 回 ・ <?= h($p['published']) ?></div>
    <?php foreach ($p['reasons'] as $r): ?><span class="tag"><?= h($r) ?></span><?php endforeach; ?>
    <div><a class="xbtn" href="<?= h(x_intent_url($p['post_text'])) ?>" target="_blank" rel="noopener">𝕏 でポスト</a></div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h2>新着リスト</h2>
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
