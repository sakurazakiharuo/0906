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
