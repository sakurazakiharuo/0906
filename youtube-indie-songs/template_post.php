<?php
/** @var array $pickup */
/** @var string $updatedAt */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- 検索エンジンに載せない・リンクをたどらせない・キャッシュさせない -->
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="googlebot" content="noindex, nofollow">
<!-- このページから YouTube や X を開いても、このページの URL を相手に伝えない -->
<meta name="referrer" content="no-referrer">
<title>X 投稿用（非公開）</title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.2rem; margin: 0 0 4px; }
  .meta { color: #666; font-size: .85rem; margin-bottom: 16px; }
  .item { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 12px; margin-bottom: 12px; max-width: 640px; }
  .t { font-weight: bold; }
  .s { font-size: .8rem; color: #666; margin: 4px 0 8px; }
  a { color: #c00; text-decoration: none; }
  textarea { width: 100%; box-sizing: border-box; min-height: 9em; font: inherit; font-size: .9rem; padding: 8px; border: 1px solid #ddd; border-radius: 6px; }
  .row { display: flex; align-items: center; gap: 12px; margin-top: 8px; }
  .count { font-size: .8rem; color: #666; }
  .count.over { color: #c00; font-weight: bold; }
  .xbtn { display: inline-block; font-size: .85rem; background: #000; color: #fff; border-radius: 999px; padding: 6px 14px; }
  .xbtn:hover { opacity: .85; }
  .warn { font-size: .8rem; color: #a60; }
  .empty { color: #888; }
</style>
</head>
<body>
<h1>X 投稿用（非公開ページ）</h1>
<p class="meta">最終更新: <?= h($updatedAt) ?>　｜　投稿文は編集できます。「𝕏 でポスト」で編集後の文章が入った投稿画面が開きます。</p>

<?php if (!$pickup): ?>
<p class="empty">今日のピックアップはありません。</p>
<?php endif; ?>

<?php foreach ($pickup as $i => $p): ?>
<div class="item">
  <div class="t"><a href="<?= h($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($p['title']) ?></a></div>
  <div class="s">
    <?= h($p['artist']) ?> ・ 登録者 <?= number_format($p['subscribers']) ?> 人 ・ 再生 <?= number_format($p['views']) ?> 回
    <?php if ($p['x_handle']): ?> ・ <a href="https://x.com/<?= h($p['x_handle']) ?>" target="_blank" rel="noopener noreferrer">@<?= h($p['x_handle']) ?></a>
      <span class="warn">（説明欄から自動取得。本人か確認してください）</span>
    <?php else: ?> ・ X アカウント見つからず<?php endif; ?>
  </div>
  <textarea id="post<?= $i ?>"><?= h($p['post_text']) ?></textarea>
  <div class="row">
    <a class="xbtn" href="<?= h(x_intent_url($p['post_text'])) ?>" target="_blank" rel="noopener noreferrer" data-src="post<?= $i ?>">𝕏 でポスト</a>
    <span class="count" data-count="post<?= $i ?>"></span>
  </div>
</div>
<?php endforeach; ?>

<script>
// 編集した投稿文で X の投稿画面を開く／文字数（日本語 2・URL 23 換算、上限 280）を表示
function xLength(text) {
  var len = 0;
  text = text.replace(/https?:\/\/\S+/g, function () { len += 23; return ''; });
  for (var ch of text) {
    var cp = ch.codePointAt(0);
    len += (cp <= 0x10FF || (cp >= 0x2000 && cp <= 0x200D) || (cp >= 0x2010 && cp <= 0x201F) || (cp >= 0x2032 && cp <= 0x2037)) ? 1 : 2;
  }
  return len;
}
document.querySelectorAll('textarea').forEach(function (ta) {
  var btn = document.querySelector('[data-src="' + ta.id + '"]');
  var count = document.querySelector('[data-count="' + ta.id + '"]');
  function update() {
    btn.href = 'https://x.com/intent/post?text=' + encodeURIComponent(ta.value);
    var n = xLength(ta.value);
    count.textContent = n + ' / 280';
    count.className = 'count' + (n > 280 ? ' over' : '');
  }
  ta.addEventListener('input', update);
  update();
});
</script>
</body>
</html>
