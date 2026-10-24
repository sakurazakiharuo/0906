<?php
/** @var array $P 言語の設定（languages.php） */
/** @var array $tabs 言語タブ */
/** @var array $rows */
/** @var array $pickup */
/** @var array $mebuki */
/** @var array $config */
/** @var string $updatedAt */
$U = $P['ui'];
?>
<!DOCTYPE html>
<html lang="<?= h($P['html_lang']) ?>">
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
<title><?= h($U['title']) ?></title>
<style>
  body { font-family: system-ui, "Hiragino Sans", "Noto Sans JP", "Noto Sans TC", "PingFang TC", "Noto Sans KR", "Apple SD Gothic Neo", sans-serif; margin: 0; padding: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  h1 .sub { display: block; font-size: .85rem; font-weight: normal; color: #666; margin-top: 2px; }
  .tabs { display: flex; gap: 4px; border-bottom: 2px solid #c00; margin: 12px 0 12px; }
  .tabs a { padding: 6px 14px; border-radius: 6px 6px 0 0; background: #eee; color: #444; font-size: .9rem; }
  .tabs a.active { background: #c00; color: #fff; font-weight: bold; }
  .tabs a:hover { text-decoration: none; }
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
  h2 small { font-size: .8rem; font-weight: normal; color: #666; }
  .note { color: #666; font-size: .8rem; margin: 0 0 10px; }
  .picks { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin-bottom: 8px; }
  .pick { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 12px; }
  .pick .t { font-weight: bold; display: block; margin-bottom: 4px; }
  .pick .a { font-size: .85rem; color: #444; }
  .pick .s { font-size: .8rem; color: #666; margin: 4px 0 6px; }
  .tag { display: inline-block; font-size: .72rem; background: #fdecec; color: #a00; border-radius: 4px; padding: 1px 6px; margin: 2px 4px 0 0; }
  .xbtn { display: inline-block; font-size: .75rem; background: #000; color: #fff; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
  .xbtn:hover { text-decoration: none; opacity: .85; }
  .pick .xbtn { margin-top: 8px; }
  .oshibtn { display: none; font-size: .75rem; background: #fff; color: #c00; border: 1px solid #c00; border-radius: 999px; padding: 2px 9px; margin-left: 4px; cursor: pointer; white-space: nowrap; }
  .oshi-ready .oshibtn { display: inline-block; }
  .oshibtn.on { background: #c00; color: #fff; }
  #oshi-bar { display: none; font-size: .85rem; margin: -4px 0 12px; }
  .oshi-ready #oshi-bar { display: block; }
  #oshi-bar .note { color: #666; font-size: .8rem; margin-left: 6px; }
  #oshi-bar .login { display: inline-block; background: #000; color: #fff; border-radius: 999px; padding: 3px 12px; }
  .mebuki td.rank { font-weight: bold; color: #2a7a2a; }
</style>
</head>
<body>
<h1><?= h($U['h1']) ?><span class="sub"><?= h($U['sub']) ?></span></h1>

<nav class="tabs">
<?php foreach ($tabs as $t): ?>
  <a href="<?= h($t['href']) ?>" hreflang="<?= h($t['code'] === 'zh' ? 'zh-Hant' : $t['code']) ?>"<?= $t['code'] === $P['code'] ? ' class="active"' : '' ?>><?= h($t['label']) ?></a>
<?php endforeach; ?>
</nav>

<div id="oshi-bar"></div>

<p class="meta">
  <?= h(sprintf($U['meta'], $config['min_subscribers'], $config['max_subscribers'], $config['days'], $updatedAt, count($rows))) ?>
</p>

<?php if (!empty($pickup)): ?>
<h2><?= h($U['pickup']) ?></h2>
<p class="note"><?= h(sprintf($U['pickup_note'], $config['pickup_max_subscribers'])) ?></p>
<div class="picks">
<?php foreach ($pickup as $p): ?>
  <div class="pick">
    <a class="t" href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['title']) ?></a>
    <a class="a" href="<?= h($p['channel_url']) ?>" target="_blank" rel="noopener"><?= h($p['artist']) ?></a>
    <?php if ($p['x_handle']): ?><a class="a" href="https://x.com/<?= h($p['x_handle']) ?>" target="_blank" rel="noopener">@<?= h($p['x_handle']) ?></a><?php endif; ?>
    <div class="s"><?= h(sprintf($U['stats'], number_format($p['subscribers']), number_format($p['views']))) ?> ・ <?= h($p['published']) ?></div>
    <?php foreach ($p['reason_labels'] as $r): ?><span class="tag"><?= h($r) ?></span><?php endforeach; ?>
    <div><a class="xbtn" href="https://x.com/intent/post" target="_blank" rel="noopener" data-text="<?= h($p['post_text']) ?>"><?= h($U['xbtn']) ?></a><button type="button" class="oshibtn" data-channel="<?= h($p['channel_id']) ?>"><?= h($U['oshi_add']) ?></button></div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($mebuki)): ?>
<h2><?= h($U['mebuki']) ?> <small><?= h(sprintf($U['mebuki_sub'], count($mebuki))) ?></small></h2>
<p class="note"><?= h($U['mebuki_note']) ?></p>
<div class="wrap">
<table class="mebuki">
  <thead>
    <tr><th><?= h($U['rank']) ?></th><?php foreach (array_slice($U['cols'], 1) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($mebuki as $m): ?>
    <tr>
      <td class="num rank"><?= $m['rank'] ?></td>
      <td><a href="<?= h($m['channel_url']) ?>" target="_blank" rel="noopener"><?= h($m['artist']) ?></a></td>
      <td class="num"><?= number_format($m['subscribers']) ?></td>
      <td><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener"><?= h($m['title']) ?></a></td>
      <td class="num"><?= number_format($m['views']) ?></td>
      <td class="num"><?= h($m['published']) ?></td>
      <td><a class="xbtn" href="https://x.com/intent/post" target="_blank" rel="noopener" data-text="<?= h($m['post_text']) ?>"><?= h($U['xbtn']) ?></a><button type="button" class="oshibtn" data-channel="<?= h($m['channel_id']) ?>"><?= h($U['oshi_add']) ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= h($U['list']) ?></h2>
<div class="wrap">
<table>
  <thead>
    <tr><?php foreach ($U['cols'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty"><?= h($U['empty']) ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td class="num"><?= $i + 1 ?></td>
      <td><a href="<?= h($r['channel_url']) ?>" target="_blank" rel="noopener"><?= h($r['artist']) ?></a></td>
      <td class="num"><?= number_format($r['subscribers']) ?></td>
      <td><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['title']) ?></a></td>
      <td class="num"><?= number_format($r['views']) ?></td>
      <td class="num"><?= h($r['published']) ?></td>
      <td><a class="xbtn" href="https://x.com/intent/post" target="_blank" rel="noopener" data-text="<?= h($r['post_text']) ?>"><?= h($U['xbtn']) ?></a><button type="button" class="oshibtn" data-channel="<?= h($r['channel_id']) ?>"><?= h($U['oshi_add']) ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="meta"><?= h($U['credit']) ?></p>
<script>
// 「𝕏 でポスト」: 押したときに投稿文入りの X 投稿画面の URL を作る（ページを軽くするため）
document.addEventListener('click', function (e) {
  var a = e.target.closest('a.xbtn');
  if (a) a.href = 'https://x.com/intent/post?text=' + encodeURIComponent(a.dataset.text);
});

// 初期推し: ログイン状態を確かめ、ボタンを表示する（oshi.php がない・動かないときは何も出さない）
(function () {
  var T = <?= json_encode(['login' => $U['login'], 'logout' => $U['logout'], 'mylist' => $U['mylist'], 'add' => $U['oshi_add'], 'added' => $U['oshi_added'], 'note' => $U['login_note']], JSON_UNESCAPED_UNICODE) ?>;
  var here = location.pathname;
  var state = { logged_in: false, oshi: {} };
  function el(tag, attrs, text) { var e = document.createElement(tag); for (var k in attrs) e.setAttribute(k, attrs[k]); if (text) e.textContent = text; return e; }
  function paint() {
    document.querySelectorAll('.oshibtn').forEach(function (b) {
      var on = !!state.oshi[b.dataset.channel];
      b.classList.toggle('on', on);
      b.textContent = on ? T.added : T.add;
    });
  }
  function send(action, channel) {
    return fetch('oshi.php?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Oshi': '1' }, body: JSON.stringify({ channel_id: channel }) })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  fetch('oshi.php?action=me', { credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
    .then(function (me) {
      state.logged_in = !!me.logged_in;
      (me.oshi || []).forEach(function (c) { state.oshi[c] = true; });
      var bar = document.getElementById('oshi-bar');
      if (me.logged_in) {
        bar.appendChild(document.createTextNode(me.name + '　｜　'));
        bar.appendChild(el('a', { href: 'oshi.php' }, T.mylist));
        bar.appendChild(document.createTextNode('　｜　'));
        bar.appendChild(el('a', { href: 'oshi.php?action=logout&return=' + encodeURIComponent(here) }, T.logout));
      } else {
        bar.appendChild(el('a', { class: 'login', href: 'oshi.php?action=login&return=' + encodeURIComponent(here) }, T.login));
        bar.appendChild(el('span', { class: 'note' }, T.note));
      }
      paint();
      document.body.classList.add('oshi-ready');
    })
    .catch(function () {});
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.oshibtn');
    if (!b) return;
    if (!state.logged_in) { location.href = 'oshi.php?action=login&return=' + encodeURIComponent(here); return; }
    var c = b.dataset.channel, on = !!state.oshi[c];
    b.disabled = true;
    send(on ? 'remove' : 'add', c)
      .then(function () { if (on) delete state.oshi[c]; else state.oshi[c] = true; paint(); })
      .catch(function (err) { alert(err.message); })
      .then(function () { b.disabled = false; });
  });
})();
</script>
</body>
</html>
