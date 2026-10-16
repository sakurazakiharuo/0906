<?php

// HTML エスケープ
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

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


// 「作りこまれている」度合いを動画の情報から推定する（再生・視聴はできないので説明欄や長さなどの手がかりで判定）
// 戻り値: [点数, 理由の一覧]。理由は言語に依存しない形（reason_label() で各言語の文言にする）
function craft_score(array $video): array
{
    $sn = $video['snippet'];
    $desc = $sn['description'] ?? '';
    $descLower = mb_strtolower($desc, 'UTF-8');
    $text = mb_strtolower($sn['title'] . "\n" . $desc, 'UTF-8');
    $score = 0;
    $reasons = [];

    // 説明欄のクレジット（作詞・作曲・編曲・歌・映像・イラスト・ミックス・演奏）の種類数。日本語・中国語・韓国語・英語に対応
    $roles = [
        'lyrics' => '作詞|作词|lyrics?\s*[:：]|詞\s*[:：]|词\s*[:：]|작사',
        'compose' => '作曲|compose[rd]?|music\s*[:：]|曲\s*[:：]|작곡',
        'arrange' => '編曲|编曲|arrange|편곡',
        'vocal' => 'vocal|ボーカル|歌唱|演唱|主唱|vo\.|歌\s*[:：]|보컬|노래\s*[:：]',
        'video' => '映像|動画制作|影像|導演|导演|攝影|摄影|movie|撮影|mv\s*[:：]|video\s*[:：]|監督|director|영상|감독|촬영',
        'illust' => 'イラスト|illust|絵\s*[:：]|アニメーション|animation|插畫|插画|動畫|일러스트|그림\s*[:：]|애니메이션',
        'mix' => 'mix|ミックス|マスタリング|master|録音|錄音|录音|混音|母帶|rec\s*[:：]|エンジニア|믹싱|마스터링|녹음',
        'play' => 'guitar|ギター|bass|ベース|drums?|ドラム|piano|ピアノ|keyboard|strings|吉他|貝斯|贝斯|鼓\s*[:：]|鋼琴|钢琴|기타|베이스|드럼|피아노',
    ];
    $found = [];
    foreach ($roles as $name => $re) {
        if (preg_match('/' . $re . '/u', $descLower)) {
            $found[] = $name;
        }
    }
    if (count($found) >= 4) {
        $score += 2;
        $reasons[] = ['credit_rich', $found];
    } elseif (count($found) >= 2) {
        $score += 1;
        $reasons[] = ['credit', $found];
    }

    // 説明欄に歌詞が載っている（日本語・中国語・韓国語の短い行が 8 行以上）
    $lyricLines = 0;
    foreach (preg_split('/\R/u', $desc) as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 40 && preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $line)
            && !preg_match('/https?:|[:：]|#/u', $line)) {
            $lyricLines++;
        }
    }
    if ($lyricLines >= 8) {
        $score += 1;
        $reasons[] = ['lyrics'];
    }

    // 1 曲まるごとの長さ（2〜8 分）
    $sec = duration_sec($video['contentDetails']['duration'] ?? '');
    if ($sec >= 120) {
        $score += 1;
        $reasons[] = ['full', $sec];
    }

    // MV・リリックビデオとして作られている
    if (preg_match('/(?<![a-z])(mv|music\s*video|lyric\s*video|official)(?![a-z])|ミュージックビデオ|リリックビデオ|뮤직비디오/u', mb_strtolower($sn['title'], 'UTF-8'))) {
        $score += 1;
        $reasons[] = ['mv'];
    }

    // 説明欄が丁寧（300 文字以上）
    if (mb_strlen($desc) >= 300) {
        $score += 1;
        $reasons[] = ['desc'];
    }

    // ショート動画（切り抜き・宣伝）は減点
    if ($sec < 60 || strpos($text, '#shorts') !== false) {
        $score -= 2;
    }

    return [$score, $reasons];
}

// craft_score の理由を、言語ごとの文言にする（$short = true なら「（作詞・作曲）」などの詳細を付けない）
function reason_label(array $reason, array $L, bool $short = false): string
{
    [$code, $arg] = $reason + [1 => null];
    switch ($code) {
        case 'credit_rich':
        case 'credit':
            $roles = implode($L['sep'], array_map(fn($r) => $L['roles'][$r], $arg));
            return $short ? preg_replace('/\s*[（(].*$/u', '', sprintf($L[$code], '')) : sprintf($L[$code], $roles);
        case 'full':
            return sprintf($L['full'], intdiv($arg, 60), $arg % 60);
        default:
            return $L[$code];
    }
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

// X の投稿文を組み立てる（280 字に収まるようにタイトルを切り詰める）
//   $P: 言語の設定（languages.php）、$header: 1 行目（null なら付けない）、$note: アーティスト行の次の 1 行（'' なら付けない）
function compose_post(array $P, ?string $header, array $p, string $note = ''): string
{
    $artist = $p['artist'] . (!empty($p['x_handle']) ? ' @' . $p['x_handle'] : '');
    $subs = sprintf($P['post']['subs'], $p['subscribers']);
    $make = fn($title) => ($header !== null ? "{$header}\n" : '')
        . "「{$title}」\n"
        . "{$artist}{$subs}\n"
        . ($note !== '' ? "{$note}\n" : '')
        . "{$p['url']}\n"
        . $P['post']['tags'];
    $title = $p['title'];
    while (x_length($make($title)) > 280 && mb_strlen($title) > 1) {
        $title = mb_substr($title, 0, mb_strlen($title) - 2) . '…';
    }
    return $make($title);
}

// ピックアップの投稿文
function build_post_text(array $P, array $p): string
{
    $highlights = implode($P['reasons']['sep'], array_map(fn($r) => reason_label($r, $P['reasons'], true), array_slice($p['reasons'], 0, 3)));
    return compose_post($P, $P['post']['pickup'], $p, $highlights);
}

// 芽吹き（再生数ランキング）の投稿文
function build_mebuki_post_text(array $P, array $p, int $rank): string
{
    return compose_post($P, sprintf($P['post']['mebuki'], $rank), $p, sprintf($P['post']['views'], number_format($p['views'])));
}

// 新着リストの投稿文（ピックアップ・芽吹きとは書かない）
function build_list_post_text(array $P, array $p): string
{
    return compose_post($P, null, $p);
}

// X の投稿画面を、投稿文を入れた状態で開く URL
function x_intent_url(string $text): string
{
    return 'https://x.com/intent/post?text=' . rawurlencode($text);
}

// AI 生成を示す語のパターン（小文字化・半角化した文字列に使う）
//   - 単独の "ai"（AI作曲, AI☆, #AI など。aiko・Aimer・Haiku のような語の一部は対象外）
//   - "ai" + 英単語（AIMusic, AIgenerated, AIcover, AIsongs, AImusicvideo など）
//   - AI 音楽・画像・動画の生成サービス名（suno, udio, mureka, midjourney など）
function ai_regex(): string
{
    return '/(?<![a-z])ai(?![a-z])'
        . '|(?<![a-z])ai(?:music|song|generat|vocal|voice|cover|art|mv|pop|jpop|rock|singer|idol|girl|boy|anime|video|image|illust|creat|made|compos|produc|band|beat|lofi|ballad|edm|track|tune|lyric|sing)'
        . '|suno|udio|mureka|riffusion|soundraw|boomy|aiva(?![a-z])|midjourney|nijijourney|stable\s*diffusion|dall-?e|chatgpt|(?<![a-z])gpt|(?<![a-z])sora(?![a-z])|kling|生成ai|生成式ai|ai生成|人工知能|人工智慧|人工智能|인공지능|えーあい/u';
}

function text_has_ai(string $text): bool
{
    $text = mb_strtolower(mb_convert_kana($text, 'a', 'UTF-8'), 'UTF-8'); // 全角 ＡＩ → ai
    return preg_match(ai_regex(), $text) === 1;
}

// タグ・ハッシュタグに AI 生成を示す語があるか（#AI, #AI音楽, #AIMusic, #suno など）
// YouTube の「改変または合成されたコンテンツ」ラベルは API で取得できないため、タグで判定する
function has_ai_tag(array $snippet): bool
{
    preg_match_all('/[#＃]([^\s#＃]+)/u', $snippet['title'] . ' ' . ($snippet['description'] ?? ''), $m);
    foreach (array_merge($snippet['tags'] ?? [], $m[1]) as $tag) {
        if (text_has_ai($tag)) {
            return true;
        }
    }
    return false;
}

// タイトル・説明欄・タグのどこかで AI 生成に触れているか（ピックアップ用。タグ判定より厳しい）
function mentions_ai(array $snippet): bool
{
    return text_has_ai($snippet['title'] . ' ' . ($snippet['description'] ?? '') . ' ' . implode(' ', $snippet['tags'] ?? []));
}

// オリジナル曲ではない「ランキング・まとめ・解説」系の動画か（タイトルで判定。config に関係なく常に適用）
// タグには「おすすめ曲」などを SEO 目的で付けるオリジナル曲も多いので、タグは見ない
function is_compilation(array $snippet, string $regex): bool
{
    $title = mb_strtolower(mb_convert_kana($snippet['title'], 'a', 'UTF-8'), 'UTF-8');
    return preg_match('/' . $regex . '/u', $title) === 1;
}

// ---- 言語の判定 ----

function count_matches(string $re, string $text): int
{
    return preg_match_all($re, $text);
}

// 繁体字だけで使う字・簡体字だけで使う字を数える（よく使う字の組）
function hant_hans_scores(string $text): array
{
    $hant = '們這個來說時為會學國發後對聽樂創詞編愛與開長話還過讓見覺歡動電點頻願戀夢憶聲種從裡麼嗎無東風飛號經現實體認當邊間問頭錄製謝訂閱專輯單視紅綠藍淚漸歲變親陽雲雙壓氣傳華語際義歷總網護說讀寫劃戲員場題級';
    $hans = '们这个来说时为会学国发后对听乐创词编爱与开长话还过让见觉欢动电点频愿恋梦忆声种从里么吗无东风飞号经现实体认当边间问头录制谢订阅专辑单视红绿蓝泪渐岁变亲阳云双压气传华语际义历总网护说读写划戏员场题级';
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $t = $s = 0;
    foreach ($chars as $c) {
        if (mb_strpos($hant, $c) !== false) {
            $t++;
        } elseif (mb_strpos($hans, $c) !== false) {
            $s++;
        }
    }
    return [$t, $s];
}

// その言語の動画か（音声の言語設定、なければタイトル・説明欄の文字で判定）
function is_language(array $snippet, string $script): bool
{
    $audio = strtolower($snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? '');
    $text = $snippet['title'] . ' ' . ($snippet['description'] ?? '');
    $kana = count_matches('/[\x{3040}-\x{30FF}]/u', $text);
    $hangul = count_matches('/[\x{AC00}-\x{D7AF}]/u', $text);
    switch ($script) {
        case 'ja':
            // 漢字だけだと中国語と区別できないので、かなを必須にする
            return strpos($audio, 'ja') === 0 || $kana >= 1;
        case 'ko':
            return strpos($audio, 'ko') === 0 || $hangul >= 2;
        case 'zh_hant':
            if (preg_match('/^zh-(hant|tw|hk|mo)/', $audio)) {
                return true;
            }
            if (preg_match('/^zh-(hans|cn|sg)/', $audio) || strpos($audio, 'ja') === 0 || strpos($audio, 'ko') === 0) {
                return false;
            }
            // 日本語（かな）・韓国語（ハングル）の文章は除き、繁体字が簡体字より多いこと
            [$t, $s] = hant_hans_scores($text);
            return $kana <= 2 && $hangul <= 2 && $t >= 1 && $t > $s;
    }
    return false;
}

// 説明欄がその言語で書かれているか（「MV」など言語を問わない語だけで見つかった動画の確認用）
function description_in_language(string $desc, string $script): bool
{
    switch ($script) {
        case 'ja':
            return has_hiragana($desc);
        case 'ko':
            return count_matches('/[\x{AC00}-\x{D7AF}]/u', $desc) >= 2;
        case 'zh_hant':
            [$t, $s] = hant_hans_scores($desc);
            return $t >= 1 && $t > $s && count_matches('/[\x{3040}-\x{30FF}]/u', $desc) <= 2;
    }
    return false;
}

// ファンが有名歌手の曲を載せているチャンネルか（チャンネル名で判定）
function is_fan_channel(string $channelTitle): bool
{
    $t = mb_strtolower($channelTitle, 'UTF-8');
    return preg_match('/ファンチャンネル|ファンクラブ|ファン(?![ァ-ヶー])|粉絲|粉丝|後援會|应援|왕팬|찐팬|팬클럽|팬채널|팬영상|(?<![가-힣])팬(?![가-힣])|(?<![a-z])fans?\s*(club|channel|page|account|made)?(?![a-z])/u', $t) === 1;
}
