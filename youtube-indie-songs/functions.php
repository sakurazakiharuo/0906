<?php

// 日本語（ひらがな・カタカナ・漢字）が含まれるか
function has_japanese(string $text): bool
{
    return preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text) === 1;
}

// 説明欄などにひらがなが含まれるか
function has_hiragana(string $text): bool
{
    return preg_match('/\p{Hiragana}/u', $text) === 1;
}

// ISO 8601 の再生時間（例: PT4M13S）を秒に変換
function duration_sec(string $iso): int
{
    try {
        $d = new DateInterval($iso);
    } catch (Exception $e) {
        return 0;
    }
    return $d->d * 86400 + $d->h * 3600 + $d->i * 60 + $d->s;
}

// $text に含まれる $words を返す（大文字小文字は区別しない）
function matched_words(string $text, array $words): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $hits = [];
    foreach ($words as $word) {
        $w = mb_strtolower($word, 'UTF-8');
        // 英単語は単語単位で判定（"cover" が "discover" に誤反応しないように）
        $hit = preg_match('/^[a-z0-9 ]+$/', $w)
            ? preg_match('/(?<![a-z0-9])' . preg_quote($w, '/') . '(?![a-z0-9])/u', $text) === 1
            : mb_strpos($text, $w) !== false;
        if ($hit) {
            $hits[] = $word;
        }
    }
    return $hits;
}

// タイトル・タグに除外語が含まれるか
function has_excluded_word(array $snippet, array $words): bool
{
    return (bool)matched_words($snippet['title'] . ' ' . implode(' ', $snippet['tags'] ?? []), $words);
}

// タグ・ハッシュタグに AI 生成を示す語があるか（#AI, #AI音楽, #AIMusic, #suno など）
// YouTube の「改変または合成されたコンテンツ」ラベルは API で取得できないため、タグで判定する
function has_ai_tag(array $snippet): bool
{
    preg_match_all('/[#＃]([^\s#＃]+)/u', $snippet['title'] . ' ' . ($snippet['description'] ?? ''), $m);
    foreach (array_merge($snippet['tags'] ?? [], $m[1]) as $tag) {
        $tag = mb_strtolower(mb_convert_kana($tag, 'a', 'UTF-8'), 'UTF-8'); // 全角 ＡＩ → ai
        if (preg_match('/(?<![a-z])ai(?:music|songs?|generated|vocals?|covers?|art|mv|pop|jpop)?(?![a-z])|suno|udio|生成ai|人工知能/u', $tag)) {
            return true;
        }
    }
    return false;
}

// 「作りこまれている」度合いを動画の情報から推定する（再生・視聴はできないので説明欄や長さなどの手がかりで判定）
// 戻り値: [点数, 理由の一覧]
function craft_score(array $video): array
{
    $sn = $video['snippet'];
    $desc = $sn['description'] ?? '';
    $text = mb_strtolower($sn['title'] . "\n" . $desc, 'UTF-8');
    $score = 0;
    $reasons = [];

    // 説明欄のクレジット（作詞・作曲・編曲・歌・映像・イラスト・ミックス・演奏）の種類数
    $roles = [
        '作詞' => '作詞|lyrics?\s*[:：]|詞\s*[:：]',
        '作曲' => '作曲|compose[rd]?|music\s*[:：]|曲\s*[:：]',
        '編曲' => '編曲|arrange',
        '歌' => 'vocal|ボーカル|歌唱|vo\.|歌\s*[:：]',
        '映像' => '映像|動画制作|movie|撮影|mv\s*[:：]|video\s*[:：]|監督|director',
        'イラスト' => 'イラスト|illust|絵\s*[:：]|アニメーション|animation',
        'ミックス' => 'mix|ミックス|マスタリング|master|録音|rec\s*[:：]|エンジニア',
        '演奏' => 'guitar|ギター|bass|ベース|drums?|ドラム|piano|ピアノ|keyboard|strings',
    ];
    $found = [];
    foreach ($roles as $name => $re) {
        if (preg_match('/' . $re . '/u', mb_strtolower($desc, 'UTF-8'))) {
            $found[] = $name;
        }
    }
    if (count($found) >= 4) {
        $score += 2;
        $reasons[] = 'クレジット充実（' . implode('・', $found) . '）';
    } elseif (count($found) >= 2) {
        $score += 1;
        $reasons[] = 'クレジットあり（' . implode('・', $found) . '）';
    }

    // 説明欄に歌詞が載っている（日本語の短い行が 8 行以上）
    $lyricLines = 0;
    foreach (preg_split('/\R/u', $desc) as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 40 && has_japanese($line) && !preg_match('/https?:|[:：]|#/u', $line)) {
            $lyricLines++;
        }
    }
    if ($lyricLines >= 8) {
        $score += 1;
        $reasons[] = '歌詞掲載';
    }

    // 1 曲まるごとの長さ（2〜8 分）
    $sec = duration_sec($video['contentDetails']['duration'] ?? '');
    if ($sec >= 120) {
        $score += 1;
        $reasons[] = 'フル尺 ' . intdiv($sec, 60) . '分' . ($sec % 60) . '秒';
    }

    // MV・リリックビデオとして作られている
    if (preg_match('/(?<![a-z])(mv|music\s*video|lyric\s*video|official)(?![a-z])|ミュージックビデオ|リリックビデオ/u', mb_strtolower($sn['title'], 'UTF-8'))) {
        $score += 1;
        $reasons[] = 'MV';
    }

    // 説明欄が丁寧（300 文字以上）
    if (mb_strlen($desc) >= 300) {
        $score += 1;
        $reasons[] = '説明文が丁寧';
    }

    // ショート動画（切り抜き・宣伝）は減点
    if ($sec < 60 || strpos($text, '#shorts') !== false) {
        $score -= 2;
    }

    return [$score, $reasons];
}

// タイトル・説明欄のどこかで AI 生成に触れているか（ピックアップ用。タグ判定より厳しい）
function mentions_ai(array $snippet): bool
{
    $text = mb_strtolower(mb_convert_kana($snippet['title'] . ' ' . ($snippet['description'] ?? ''), 'a', 'UTF-8'), 'UTF-8');
    return preg_match('/(?<![a-z])ai(?![a-z])|suno|udio|生成ai|人工知能/u', $text) === 1;
}

// 説明文から X（旧 Twitter）のアカウントを探す。見つからなければ null
//   x.com/xxx・twitter.com/xxx の URL、または「X: @xxx」「Twitter：@xxx」「𝕏 @xxx」の書き方に対応
function find_x_handle(string $text): ?string
{
    $reserved = ['intent', 'share', 'home', 'search', 'hashtag', 'i', 'explore', 'settings', 'login', 'signup', 'messages', 'notifications', 'compose', 'tos', 'privacy'];
    preg_match_all('#(?:https?://)?(?:www\.|mobile\.)?(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})(?![A-Za-z0-9_])#iu', $text, $m);
    foreach ($m[1] as $h) {
        if (!in_array(strtolower($h), $reserved, true)) {
            return $h;
        }
    }
    if (preg_match('/(?:(?<![a-z])x|𝕏|twitter|ツイッター|エックス)\s*[:：]?\s*[@＠]([A-Za-z0-9_]{1,15})(?![A-Za-z0-9_])/iu', $text, $m)) {
        return $m[1];
    }
    return null;
}

// X の文字数（日本語などは 2、URL は一律 23 として数える。上限 280）
function x_length(string $text): int
{
    $len = 0;
    $text = preg_replace_callback('#https?://\S+#u', function () use (&$len) {
        $len += 23;
        return '';
    }, $text);
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        $narrow = $cp <= 0x10FF || ($cp >= 0x2000 && $cp <= 0x200D) || ($cp >= 0x2010 && $cp <= 0x201F) || ($cp >= 0x2032 && $cp <= 0x2037);
        $len += $narrow ? 1 : 2;
    }
    return $len;
}

// ピックアップ 1 件分の投稿文（280 字に収まるようにタイトルを切り詰める）
function build_post_text(array $p): string
{
    $artist = $p['artist'] . ($p['x_handle'] ? ' @' . $p['x_handle'] : '');
    $highlights = implode('・', array_map(fn($r) => preg_replace('/（.*）$/u', '', $r), array_slice($p['reasons'], 0, 3)));
    $make = fn($title) => "🎤 初期推しピックアップ\n"
        . "「{$title}」\n"
        . "{$artist}（登録者{$p['subscribers']}人）\n"
        . ($highlights !== '' ? "{$highlights}\n" : '')
        . "{$p['url']}\n"
        . '#初期推し #オリジナル曲';
    $title = $p['title'];
    while (x_length($make($title)) > 280 && mb_strlen($title) > 1) {
        $title = mb_substr($title, 0, mb_strlen($title) - 2) . '…';
    }
    return $make($title);
}

// X の投稿画面を、投稿文を入れた状態で開く URL
function x_intent_url(string $text): string
{
    return 'https://x.com/intent/post?text=' . rawurlencode($text);
}
