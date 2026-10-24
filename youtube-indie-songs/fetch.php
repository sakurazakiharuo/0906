<?php
/**
 * YouTube から「登録者 0〜300 人のチャンネルが直近 3 日以内に投稿したオリジナル曲」を
 * 日本語・繁體中文・한국어 それぞれについて集め、言語ごとの静的 HTML（タブで切り替え）を生成する。
 * cron から毎日実行する想定。言語ごとの検索語・除外語・文言は languages.php。
 *
 *   /usr/local/bin/php /home/YOUR_ACCOUNT/youtube-indie-songs/fetch.php
 *
 * 候補動画の集め方は 2 通り:
 *   A. キーワード検索（search.list。1 日 100 回の上限あり）
 *   B. 記録済みチャンネルの新着動画を直接確認（playlistItems.list。検索の上限を使わない）
 * 一度リストに載ったチャンネルは言語ごとに channels.json（日本語）/ channels-zh.json / channels-ko.json に記録され、
 * 次回から B でも確認される。
 */

date_default_timezone_set('Asia/Tokyo');

$config = require __DIR__ . '/config.php';
$apiKey = $config['api_key'];
require __DIR__ . '/functions.php';
require __DIR__ . '/exclusions.php';
require __DIR__ . '/languages.php';

// 実行結果を fetch.log に追記する（cron の設定に関係なく、止まった理由を後から確認できるように）
function log_line(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    file_put_contents(__DIR__ . '/fetch.log', $line, FILE_APPEND);
}

// 想定外のエラーで止まった場合もログに残す（この場合 HTML は更新されず前回のまま）
set_exception_handler(function (Throwable $e) {
    log_line('ERROR ' . $e->getMessage());
    exit(1);
});

function api_get(string $endpoint, array $params): array
{
    $url = 'https://www.googleapis.com/youtube/v3/' . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        throw new RuntimeException("YouTube API error ($endpoint, HTTP $status): $body");
    }
    return json_decode($body, true);
}

// 複数リクエストを並列に投げる（記録済みチャンネルが多くても短時間で終わるように）
// 失敗したリクエストの結果は null
function api_get_multi(string $endpoint, array $paramsList, int $concurrency = 10): array
{
    $results = [];
    foreach (array_chunk($paramsList, $concurrency, true) as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $key => $params) {
            $ch = curl_init('https://www.googleapis.com/youtube/v3/' . $endpoint . '?' . http_build_query($params));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $key => $ch) {
            $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            $results[$key] = $ok ? json_decode(curl_multi_getcontent($ch), true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

// channels.list で登録者数などを取得（1 ユニット/50 件）
function fetch_channels(array $ids, string $apiKey): array
{
    $channels = [];
    foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
        $res = api_get('channels', [
            'key' => $apiKey,
            'part' => 'snippet,statistics',
            'id' => implode(',', $chunk),
        ]);
        foreach ($res['items'] ?? [] as $c) {
            $channels[$c['id']] = $c;
        }
    }
    return $channels;
}

// キーワード検索（search.list: 1 日 100 回まで。全言語の合計）
// 回数の上限に達したときに特定の言語だけ 0 件にならないよう、言語を順番に回して検索する:
//   1 ページ目: 日本語の 1 語目 → 中文の 1 語目 → 韓国語の 1 語目 → 日本語の 2 語目 → …、そのあと 2 ページ目 … の順
// 戻り値: [言語コード => [videoId => ヒットしたキーワード一覧]]
function search_all_languages(array $profiles, array $config, string $apiKey): array
{
    $publishedAfter = gmdate('Y-m-d\TH:i:s\Z', time() - $config['days'] * 86400);
    $hits = array_fill_keys(array_keys($profiles), []);
    $tokens = [];
    $maxPages = max(array_column($profiles, 'pages_per_keyword'));
    $maxKeywords = max(array_map(fn($P) => count($P['keywords']), $profiles));
    for ($page = 0; $page < $maxPages; $page++) {
        for ($k = 0; $k < $maxKeywords; $k++) {
            foreach ($profiles as $code => $P) {
                $keyword = $P['keywords'][$k] ?? null;
                if ($keyword === null || $page >= $P['pages_per_keyword']) {
                    continue;
                }
                if ($page > 0 && empty($tokens[$code][$keyword])) {
                    continue; // 前のページで結果が終わったキーワード
                }
                $params = [
                    'key' => $apiKey,
                    'part' => 'snippet',
                    'q' => $keyword,
                    'type' => 'video',
                    'publishedAfter' => $publishedAfter,
                    'order' => 'date',
                    'regionCode' => $P['region'],
                    'relevanceLanguage' => $P['relevance_lang'],
                    'maxResults' => 50,
                ];
                // 「MV」など言語を問わない語は無関係な動画が多いので音楽カテゴリに限定する
                if (in_array($keyword, $P['music_category_only_keywords'], true)) {
                    $params['videoCategoryId'] = '10';
                }
                if ($page > 0) {
                    $params['pageToken'] = $tokens[$code][$keyword];
                }
                try {
                    $res = api_get('search', $params);
                } catch (RuntimeException $e) {
                    // 割り当て超過などで検索が失敗したら検索をやめ、集めた分と記録済みチャンネルで続行する
                    log_line("WARN [$code] search stopped ($keyword p" . ($page + 1) . '): ' . preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 300)));
                    return $hits;
                }
                foreach ($res['items'] ?? [] as $item) {
                    $hits[$code][$item['id']['videoId']][] = $keyword;
                }
                $tokens[$code][$keyword] = $res['nextPageToken'] ?? null;
            }
        }
    }
    return $hits;
}

// 1 言語分の処理: 検索結果と記録済みチャンネルの新着を合わせて条件で絞り込み、新着リスト・ピックアップ・芽吹きを作る
// 候補が 1 件もなければ null
function process_language(array $P, array $config, string $apiKey, array $exclusions, array $searchHits): ?array
{
    $code = $P['code'];
    $publishedAfterTs = time() - $config['days'] * 86400;
    $videoIds = $searchHits; // videoId => ヒットしたキーワード一覧（記録済みチャンネル経由は後で本文から判定）
    $searchCount = count($videoIds);

    // 2. 記録済みチャンネル＋手動登録チャンネルの新着動画を集める
    //    （playlistItems.list: 1 ユニット/チャンネル。検索の 1 日 100 回には含まれない）
    $trackedFile = $P['tracked_file'];
    $tracked = is_file($trackedFile) ? (json_decode(file_get_contents($trackedFile), true) ?: []) : [];
    foreach ($P['watch_channels'] as $id) {
        $tracked[$id] = ($tracked[$id] ?? []) + ['title' => '', 'source' => 'manual', 'added' => date('Y-m-d')];
        $tracked[$id]['source'] = 'manual';
    }
    // 除外ボタンで外したチャンネルは記録からも消す（新着も見に行かない）
    $tracked = array_diff_key($tracked, $exclusions['channels']);

    // 登録者数が上限を超えた・非公開になった・消えたチャンネルは記録から外す（手動登録は残す）
    $trackedInfo = $tracked ? fetch_channels(array_keys($tracked), $apiKey) : [];
    foreach (array_keys($tracked) as $id) {
        if ($tracked[$id]['source'] === 'manual') {
            continue;
        }
        $info = $trackedInfo[$id] ?? null;
        if (!$info || !empty($info['statistics']['hiddenSubscriberCount'])
            || (int)($info['statistics']['subscriberCount'] ?? 0) > $config['max_subscribers']
            || subscriber_count_unreliable($info, $config)) {
            unset($tracked[$id]);
        }
    }

    // 各チャンネルの「アップロード動画」プレイリスト（UC... → UU...）から新着を取得
    $playlistParams = [];
    foreach (array_keys($tracked) as $id) {
        if (strpos($id, 'UC') === 0) {
            $playlistParams[$id] = ['key' => $apiKey, 'part' => 'contentDetails', 'playlistId' => 'UU' . substr($id, 2), 'maxResults' => 10];
        }
    }
    $watchCount = 0;
    foreach (api_get_multi('playlistItems', $playlistParams) as $res) {
        foreach ($res['items'] ?? [] as $item) {
            $published = strtotime($item['contentDetails']['videoPublishedAt'] ?? '');
            if ($published && $published >= $publishedAfterTs) {
                $videoIds[$item['contentDetails']['videoId']] ??= [];
                $watchCount++;
            }
        }
    }

    $hitKeywords = $videoIds;
    $videoIds = array_keys($videoIds);
    if (!$videoIds) {
        log_line("ERROR [$code] no candidates found; page not updated");
        return null;
    }

    // 3. 動画の詳細（再生数・言語・再生時間）を取得（videos.list: 1 ユニット/50 件）
    $videos = [];
    foreach (array_chunk($videoIds, 50) as $chunk) {
        $res = api_get('videos', ['key' => $apiKey, 'part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $chunk)]);
        foreach ($res['items'] ?? [] as $v) {
            $videos[] = $v;
        }
    }

    // 4. チャンネルの登録者数を取得（記録済みチャンネルは取得済みの情報を使う）
    $channelIds = array_map(fn($v) => $v['snippet']['channelId'], $videos);
    $channels = $trackedInfo + fetch_channels(array_diff($channelIds, array_keys($trackedInfo)), $apiKey);

    // AI で作ったと書いた動画が 1 本でもあるチャンネルは、ほかの動画も含めてすべて除外する
    // （一度見つかったチャンネルは ai_channels.json に記録し、次回以降も除外）
    $aiFile = __DIR__ . '/ai_channels.json';
    $aiChannels = is_file($aiFile) ? (json_decode(file_get_contents($aiFile), true) ?: []) : [];
    if ($config['exclude_ai_tagged'] ?? true) {
        // config の ai_allow_channels に書いたチャンネルは AI 扱いしない（自分のチャンネルなど）
        $allow = array_flip($config['ai_allow_channels'] ?? []);
        $aiChannels = array_diff_key($aiChannels, $allow);
        foreach ($videos as $v) {
            if (isset($allow[$v['snippet']['channelId']])) {
                continue;
            }
            $sn = $v['snippet'];
            if (!isset($aiChannels[$sn['channelId']]) && (mentions_ai($sn) || text_has_ai($sn['channelTitle']))) {
                $aiChannels[$sn['channelId']] = ['title' => $sn['channelTitle'], 'video' => $v['id'], 'at' => date('Y-m-d')];
            }
        }
        file_put_contents($aiFile . '.tmp', json_encode($aiChannels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($aiFile . '.tmp', $aiFile);
        $tracked = array_filter($tracked, fn($t, $id) => !isset($aiChannels[$id]) || $t['source'] === 'manual', ARRAY_FILTER_USE_BOTH);
    } else {
        $aiChannels = [];
    }

    // 5. 条件で絞り込み
    $rows = [];
    $pickupCandidates = [];
    foreach ($videos as $v) {
        $sn = $v['snippet'];
        // 除外ボタンで外した動画・チャンネルは二度と載せない
        if (isset($exclusions['videos'][$v['id']]) || isset($exclusions['channels'][$sn['channelId']])) {
            continue;
        }
        $ch = $channels[$sn['channelId']] ?? null;
        if (!$ch || !empty($ch['statistics']['hiddenSubscriberCount'])) {
            continue;
        }
        $subs = (int)($ch['statistics']['subscriberCount'] ?? 0);
        if ($subs < $config['min_subscribers'] || $subs > $config['max_subscribers']) {
            continue;
        }
        // API の登録者数が実際より少なく出ているチャンネル（総再生数・1 本あたりの再生数が多すぎる）は除外
        if (subscriber_count_unreliable($ch, $config)) {
            continue;
        }
        // 投稿から数日でこの再生数に届く動画は、登録者 300 人以下のチャンネルではまずない（API の登録者数が誤っている）
        if ((int)($v['statistics']['viewCount'] ?? 0) >= ($config['max_new_video_views'] ?? 10000)) {
            continue;
        }
        // 「〇〇 - Topic」は YouTube が自動で作るチャンネル（本人のチャンネルではない）なので除外
        if (preg_match('/ - Topic$/u', $sn['channelTitle'])) {
            continue;
        }
        // 言語の判定（日本語: かな必須 / 繁體中文: 繁体字が簡体字より多い / 한국어: ハングル）
        if (!is_language($sn, $P['script'])) {
            continue;
        }
        // 記録済みチャンネル経由の動画は、タイトル・タグ・説明欄にキーワードがあるものだけ（雑談動画などを除く）
        $hits = $hitKeywords[$v['id']] ?: matched_words(
            $sn['title'] . ' ' . implode(' ', $sn['tags'] ?? []) . ' ' . ($sn['description'] ?? ''),
            $P['keywords']
        );
        if (!$hits) {
            continue;
        }
        // 「MV」など言語を問わない語でしかヒットしていない動画は、説明欄がその言語で書かれていること
        if (!array_diff($hits, $P['loose_keywords']) && !description_in_language($sn['description'] ?? '', $P['script'])) {
            continue;
        }
        // ランキング・まとめ・解説系の動画は除外（オリジナル曲ではないため）
        if (is_compilation($sn, $P['compilation_regex'])) {
            continue;
        }
        // カバー・歌ってみた・転載・カラオケ・BGM などを除外
        if (has_excluded_word($sn, $P['exclude_words'])) {
            continue;
        }
        // AI で作った動画は除外: タイトル・説明欄・タグ・ハッシュタグ・チャンネル名のどこかに
        // AI / Suno / Udio / 人工知能 などがある動画のチャンネル（上で判定・記録済み）は除外（config に項目がなくても既定で除外する）
        if (isset($aiChannels[$sn['channelId']])) {
            continue;
        }
        // ファンが有名歌手の曲を載せているチャンネル（「〇〇ファン」「왕팬」「粉絲」など）は除外
        if (is_fan_channel($sn['channelTitle'])) {
            continue;
        }
        // 8分以上の動画は除外
        if (duration_sec($v['contentDetails']['duration'] ?? '') >= $config['max_duration_sec']) {
            continue;
        }
        // ライブ配信・プレミア公開予定は除外
        if (($sn['liveBroadcastContent'] ?? 'none') !== 'none') {
            continue;
        }
        $row = [
            'artist' => $sn['channelTitle'],
            'channel_url' => 'https://www.youtube.com/channel/' . $sn['channelId'],
            'subscribers' => $subs,
            'title' => $sn['title'],
            'url' => 'https://www.youtube.com/watch?v=' . $v['id'],
            'views' => (int)($v['statistics']['viewCount'] ?? 0),
            'published' => date('Y-m-d H:i', strtotime($sn['publishedAt'])),
            // X のアカウント（動画説明欄 → チャンネル説明文の順に探す）
            'x_handle' => find_x_handle($sn['description'] ?? '') ?? find_x_handle($ch['snippet']['description'] ?? ''),
            'channel_id' => $sn['channelId'],
            'duration_sec' => duration_sec($v['contentDetails']['duration'] ?? ''),
        ];
        $row['post_text'] = build_list_post_text($P, $row);
        $rows[] = $row;

        // ピックアップ候補（登録者が少なく、作りこみの点数が基準以上）
        if ($subs <= $config['pickup_max_subscribers'] && !mentions_ai($sn)) {
            [$score, $reasons] = craft_score($v);
            if ($score >= $config['pickup_min_score']) {
                $pickupCandidates[] = $row + [
                    'channel_id' => $sn['channelId'],
                    'channel_created' => strtotime($ch['snippet']['publishedAt'] ?? 'now'),
                    'score' => $score,
                    'reasons' => $reasons,
                    'duration_sec' => duration_sec($v['contentDetails']['duration'] ?? ''),
                ];
            }
        }

        // リストに載ったチャンネルを記録（次回から検索に頼らず新着を確認する）
        $id = $sn['channelId'];
        $tracked[$id] = ($tracked[$id] ?? ['source' => 'search', 'added' => date('Y-m-d')]);
        $tracked[$id]['title'] = $sn['channelTitle'];
        $tracked[$id]['last_listed'] = date('Y-m-d');
    }

    // 量産型の除外: 3 日間にフル尺（90 秒以上）の曲を 5 本以上出しているチャンネルは、人の手では難しい量なので
    // AI などで量産しているとみなし、そのチャンネルの動画をすべて外す（記録からも外す）。短いティーザーは数えない
    $fullCount = [];
    foreach ($rows as $r) {
        if ($r['duration_sec'] >= 90) {
            $fullCount[$r['channel_id']] = ($fullCount[$r['channel_id']] ?? 0) + 1;
        }
    }
    $maxFull = $config['max_full_songs_per_window'] ?? 4;
    $burst = array_keys(array_filter($fullCount, fn($n) => $n > $maxFull));
    if ($burst) {
        $rows = array_values(array_filter($rows, fn($r) => !in_array($r['channel_id'], $burst, true)));
        $pickupCandidates = array_values(array_filter($pickupCandidates, fn($r) => !in_array($r['channel_id'], $burst, true)));
        foreach ($burst as $id) {
            if (($tracked[$id]['source'] ?? '') !== 'manual') {
                unset($tracked[$id]);
            }
        }
        log_line("INFO [$code] mass-production channels excluded: " . count($burst));
    }

    // 記録数の上限を超えたら、最後にリストに載った日が古いものから外す（手動登録は残す）
    $max = $config['max_tracked_channels'] ?? 3000;
    if (count($tracked) > $max) {
        uasort($tracked, fn($a, $b) => ($b['source'] === 'manual') <=> ($a['source'] === 'manual')
            ?: strcmp($b['last_listed'] ?? '', $a['last_listed'] ?? ''));
        $tracked = array_slice($tracked, 0, $max, true);
    }
    file_put_contents($trackedFile . '.tmp', json_encode($tracked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($trackedFile . '.tmp', $trackedFile);

    // 6. ピックアップ: 量産型のチャンネルを除き、作りこみの点数が高い順に選ぶ
    //    量産型の判定は「歌動画」だけで数える（雑談・ゲームなど歌以外の投稿は数えない）
    //      - 直近 30 日の歌動画が多い
    //      - 歌動画の平均投稿ペース（本/週）が速い … 最新 50 本の範囲で計算
    //    歌動画かどうかはタイトル・説明欄の語（言語ごとの song_words）で判定
    $songStats = [];
    $params = [];
    foreach (array_unique(array_column($pickupCandidates, 'channel_id')) as $id) {
        $params[$id] = ['key' => $apiKey, 'part' => 'snippet,contentDetails', 'playlistId' => 'UU' . substr($id, 2), 'maxResults' => 50];
    }
    foreach (api_get_multi('playlistItems', $params) as $id => $res) {
        $items = $res['items'] ?? [];
        $songTimes = [];
        $oldest = time();
        foreach ($items as $i) {
            $t = strtotime($i['contentDetails']['videoPublishedAt'] ?? $i['snippet']['publishedAt'] ?? '') ?: time();
            $oldest = min($oldest, $t);
            if (matched_words($i['snippet']['title'] . ' ' . ($i['snippet']['description'] ?? ''), $P['song_words'])) {
                $songTimes[] = $t;
            }
        }
        $songStats[$id] = [
            'songs_30d' => count(array_filter($songTimes, fn($t) => $t >= time() - 30 * 86400)),
            'songs' => count($songTimes),
            // 50 本未満ならチャンネル開設から、50 本ちょうどなら最古の 1 本からの期間で割る
            'span_from' => count($items) < 50 ? null : $oldest,
        ];
    }
    $pickup = [];
    foreach ($pickupCandidates as $c) {
        $st = $songStats[$c['channel_id']] ?? ['songs_30d' => 0, 'songs' => 0, 'span_from' => null];
        $spanDays = max(30, (time() - ($st['span_from'] ?? $c['channel_created'])) / 86400);
        $songsPerWeek = $st['songs'] / $spanDays * 7;
        if ($st['songs_30d'] > $config['pickup_max_uploads_30d'] || $songsPerWeek > $config['pickup_max_uploads_per_week']) {
            continue;
        }
        // 1 チャンネル 1 本まで（点数が高いものを優先）
        if (isset($pickup[$c['channel_id']]) && $pickup[$c['channel_id']]['score'] >= $c['score']) {
            continue;
        }
        $pickup[$c['channel_id']] = $c;
    }
    usort($pickup, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['views'] <=> $b['views']);
    // 投稿用ページは「ポスト済み」を除いた残りから選ぶので、上限で切る前の全候補も持っておく
    foreach ($pickup as &$p) {
        $p['post_text'] = build_post_text($P, $p);
        $p['reason_labels'] = array_map(fn($r) => reason_label($r, $P['reasons']), $p['reasons']);
    }
    unset($p);
    $pickupPool = $pickup;
    $pickup = array_slice($pickup, 0, $config['pickup_limit']);

    // 7. 芽吹き: 再生数の多い順トップ 10
    $mebuki = $rows;
    usort($mebuki, fn($a, $b) => $b['views'] <=> $a['views']);
    $mebuki = array_slice($mebuki, 0, $config['mebuki_limit'] ?? 10);
    foreach ($mebuki as $i => &$m) {
        $m['rank'] = $i + 1;
        $m['post_text'] = build_mebuki_post_text($P, $m, $i + 1);
    }
    unset($m);

    // 新着リストは再生数の昇順（少ない順）
    usort($rows, fn($a, $b) => $a['views'] <=> $b['views']);

    log_line(sprintf('OK [%s] search=%d tracked_channels=%d tracked_new_videos=%d listed=%d pickup=%d',
        $code, $searchCount, count($tracked), $watchCount, count($rows), count($pickup)));
    return compact('rows', 'pickup', 'mebuki', 'pickupPool');
}

// HTML を書き出す（一時ファイル → rename で差し替え、閲覧中に壊れたページを出さない）
function render_to(string $template, string $path, array $vars): void
{
    extract($vars);
    ob_start();
    require __DIR__ . '/' . $template;
    $html = ob_get_clean();
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path . '.tmp', $html);
    rename($path . '.tmp', $path);
}

// 除外ボタンの受け口（投稿用ページと同じフォルダに自動で置く。このアプリのフォルダの場所を書き込む）
function write_exclude_endpoint(array $config): string
{
    $token = exclusion_token($config);
    $path = dirname($config['output_post_html']) . '/exclude-' . substr(hash('sha256', $token), 0, 16) . '.php';
    $code = "<?php\n// fetch.php が自動生成。編集しても次の実行で上書きされます\n"
        . 'define(\'APP_DIR\', ' . var_export(__DIR__, true) . ");\n"
        . 'define(\'EXCLUDE_TOKEN\', ' . var_export($token, true) . ");\n"
        . "require APP_DIR . '/exclude_api.php';\n";
    if (!is_file($path) || file_get_contents($path) !== $code) {
        file_put_contents($path, $code);
    }
    return $path;
}

// 初期推し機能の入り口（公開ページと同じフォルダに自動で置く。中身はこのアプリのフォルダの oshi_web.php）
function write_oshi_endpoints(array $config): void
{
    $dir = dirname($config['output_html']);
    foreach (['oshi.php' => '', 'oshi-callback.php' => "define('OSHI_CALLBACK', true);\n", 'ranking.php' => "define('OSHI_RANKING', true);\n"] as $name => $extra) {
        $code = "<?php\n// fetch.php が自動生成。編集しても次の実行で上書きされます\n"
            . 'define(\'APP_DIR\', ' . var_export(__DIR__, true) . ");\n"
            . $extra
            . "require APP_DIR . '/oshi_web.php';\n";
        if (!is_file("$dir/$name") || file_get_contents("$dir/$name") !== $code) {
            file_put_contents("$dir/$name", $code);
        }
    }
}

// ---- 実行 ----
$profiles = language_profiles($config);
$exclusions = load_exclusions();
$searchHits = search_all_languages($profiles, $config, $apiKey);
$results = [];
foreach ($profiles as $code => $P) {
    $results[$code] = process_language($P, $config, $apiKey, $exclusions, $searchHits[$code]);
}

if ($config['oshi_enabled'] ?? true) {
    write_oshi_endpoints($config);
}

// タブ（各言語のページへのリンク）
$tabs = array_map(fn($P) => ['code' => $P['code'], 'label' => $P['tab'], 'href' => $P['href']], $profiles);
$updatedAt = date('Y-m-d H:i');
foreach ($profiles as $code => $P) {
    if ($results[$code] !== null) {
        render_to('template.php', $P['output'], $results[$code] + compact('P', 'tabs', 'config', 'updatedAt'));
    } elseif (!is_file($P['output'])) {
        // 候補が 0 件で、まだページがない言語は空のページを作る（タブのリンク先がないと困るため）
        // 前回のページがあればそのまま残す
        render_to('template.php', $P['output'], ['rows' => [], 'pickup' => [], 'mebuki' => []] + compact('P', 'tabs', 'config', 'updatedAt'));
    }
}

// X 投稿用ページ（検索エンジンに載らないよう noindex。トップページからはリンクしない）
// 言語ごとのピックアップをタブで切り替えて表示する。短すぎる動画（既定 40 秒以下）は除く
if (!empty($config['output_post_html'])) {
    $minSec = $config['post_min_duration_sec'] ?? 40;
    $sections = [];
    foreach ($profiles as $code => $P) {
        $sections[] = [
            'code' => $code,
            'label' => $P['tab'],
            // 「ポスト」を押した動画と短すぎる動画を除き、残りの候補から上限まで（公開ページのピックアップには影響しない）
            'pickup' => array_slice(array_values(array_filter($results[$code]['pickupPool'] ?? [],
                fn($p) => $p['duration_sec'] > $minSec && !isset($exclusions['posted'][substr($p['url'], -11)]))), 0, $config['pickup_limit']),
        ];
    }
    render_to('template_post.php', $config['output_post_html'], compact('sections', 'updatedAt') + [
        'excludeToken' => exclusion_token($config),
        'excludeEndpoint' => basename(write_exclude_endpoint($config)),
    ]);
}

if (in_array(null, $results, true)) {
    exit(1);
}
