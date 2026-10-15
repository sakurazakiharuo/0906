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
  .exbtn { font-size: .78rem; background: #fff; color: #555; border: 1px solid #ccc; border-radius: 999px; padding: 4px 10px; cursor: pointer; }
  .exbtn:hover { border-color: #c00; color: #c00; }
  .gone { background: #f3f3f3; color: #888; font-size: .85rem; }
  .gone .undo { margin-left: 8px; }
  #excluded-list li { font-size: .8rem; color: #555; margin: 2px 0; }
</style>
</head>
<body>
<h1>X 投稿用（非公開ページ）</h1>
<p class="meta">最終更新: <?= h($updatedAt) ?>　｜　投稿文は編集できます。「𝕏 でポスト」で編集後の文章が入った投稿画面が開きます。</p>

<?php if (!$pickup): ?>
<p class="empty">今日のピックアップはありません。</p>
<?php endif; ?>

<?php foreach ($pickup as $i => $p): ?>
<div class="item" data-video="<?= h(substr($p['url'], -11)) ?>" data-channel="<?= h($p['channel_id']) ?>"
     data-title="<?= h($p['title']) ?>" data-artist="<?= h($p['artist']) ?>">
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
  <div class="row">
    <button type="button" class="exbtn" data-type="videos">この動画を除外</button>
    <button type="button" class="exbtn" data-type="channels">このチャンネルを除外</button>
  </div>
</div>
<?php endforeach; ?>

<h2 style="font-size:1rem;margin-top:24px">除外リスト</h2>
<p class="meta">ここにある動画・チャンネルは、次回の更新からどのリストにも載りません。</p>
<ul id="excluded-list"><li>読み込み中…</li></ul>

<script>
// 除外ボタン: サーバの除外リストに保存する（次回の更新から二度と載らない）
var EXCLUDE_URL = <?= json_encode($excludeEndpoint) ?>;
var EXCLUDE_TOKEN = <?= json_encode($excludeToken) ?>;

function sendExclusion(type, id, title, add) {
  return fetch(EXCLUDE_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: EXCLUDE_TOKEN, type: type, id: id, title: title, add: add })
  }).then(function (r) {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  });
}

// 除外済みのカードを畳み、除外リストを表示する
function render(ex) {
  document.querySelectorAll('.item').forEach(function (item) {
    var gone = ex.videos[item.dataset.video] ? 'videos' : (ex.channels[item.dataset.channel] ? 'channels' : null);
    if (gone && !item.classList.contains('gone')) {
      item.dataset.html = item.innerHTML;
      item.classList.add('gone');
      item.innerHTML = '';
      item.appendChild(document.createTextNode((gone === 'videos' ? '動画「' + item.dataset.title : 'チャンネル「' + item.dataset.artist) + '」を除外しました'));
    } else if (!gone && item.classList.contains('gone')) {
      item.innerHTML = item.dataset.html;
      item.classList.remove('gone');
      bindItem(item);
    }
  });
  var list = document.getElementById('excluded-list');
  list.innerHTML = '';
  ['channels', 'videos'].forEach(function (type) {
    Object.keys(ex[type]).forEach(function (id) {
      var li = document.createElement('li');
      li.appendChild(document.createTextNode((type === 'videos' ? '動画: ' : 'チャンネル: ') + (ex[type][id].title || id) + '（' + ex[type][id].at + '）'));
      var undo = document.createElement('a');
      undo.href = '#';
      undo.className = 'undo';
      undo.textContent = ' 元に戻す';
      undo.addEventListener('click', function (e) {
        e.preventDefault();
        sendExclusion(type, id, '', false).then(render).catch(showError);
      });
      li.appendChild(undo);
      list.appendChild(li);
    });
  });
  if (!list.children.length) list.innerHTML = '<li>なし</li>';
}

function showError(e) { alert('除外リストを保存できませんでした: ' + e.message); }

function bindItem(item) {
  item.querySelectorAll('.exbtn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var isVideo = btn.dataset.type === 'videos';
      var label = isVideo ? '動画「' + item.dataset.title + '」' : 'チャンネル「' + item.dataset.artist + '」のすべての動画';
      if (!confirm(label + 'を除外します。今後どのリストにも載りません。よろしいですか？')) return;
      sendExclusion(btn.dataset.type, isVideo ? item.dataset.video : item.dataset.channel,
        isVideo ? item.dataset.title : item.dataset.artist, true).then(render).catch(showError);
    });
  });
  var ta = item.querySelector('textarea');
  if (ta) bindTextarea(ta);
}

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
function bindTextarea(ta) {
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
}

document.querySelectorAll('.item').forEach(bindItem);
fetch(EXCLUDE_URL + '?token=' + encodeURIComponent(EXCLUDE_TOKEN))
  .then(function (r) { return r.json(); })
  .then(render)
  .catch(function () { document.getElementById('excluded-list').innerHTML = '<li>除外リストを読み込めませんでした</li>'; });
</script>
</body>
</html>
