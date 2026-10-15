<?php
/**
 * YouTube から「登録者 0〜300 人のチャンネルが直近 3 日以内に投稿した日本語の歌動画」を集め、
 * 再生数の昇順で並べた静的 HTML を生成する。cron から毎日実行する想定。
 *
 *   /usr/local/bin/php /home/YOUR_ACCOUNT/youtube-indie-songs/fetch.php
 *
 * 候補動画の集め方は 2 通り:
 *   A. キーワード検索（search.list。1 日 100 回の上限あり）
 *   B. 記録済みチャンネルの新着動画を直接確認（playlistItems.list。検索の上限を使わない）
 * 一度リストに載ったチャンネルは channels.json に記録され、次回から B でも確認される。
 */

date_default_timezone_set('Asia/Tokyo');

$config = require __DIR__ . '/config.php';
$apiKey = $config['api_key'];
require __DIR__ . '/functions.php';

const CHANNELS_FILE = __DIR__ . '/channels.json';

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

$publishedAfterTs = time() - $config['days'] * 86400;
$videoIds = []; // videoId => ヒットしたキーワード一覧（記録済みチャンネル経由は後で本文から判定）

// 1. キーワード検索で候補動画を集める（search.list: 1 日 100 回まで）
$musicOnly = $config['music_category_only_keywords'] ?? [];
foreach ($config['keywords'] as $keyword) {
    $pageToken = null;
    for ($page = 0; $page < $config['pages_per_keyword']; $page++) {
        $params = [
            'key' => $apiKey,
            'part' => 'snippet',
            'q' => $keyword,
            'type' => 'video',
            'publishedAfter' => gmdate('Y-m-d\TH:i:s\Z', $publishedAfterTs),
            'order' => 'date',
            'regionCode' => 'JP',
            'relevanceLanguage' => 'ja',
            'maxResults' => 50,
        ];
        // 「MV」「music video」は無関係な動画が多いので音楽カテゴリに限定する
        if (in_array($keyword, $musicOnly, true)) {
            $params['videoCategoryId'] = '10';
        }
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }
        try {
            $res = api_get('search', $params);
        } catch (RuntimeException $e) {
            // 割り当て超過などで検索が失敗したら、それまでに集めた分で続行する
            log_line('WARN search stopped (' . $keyword . ' p' . ($page + 1) . '): ' . preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 300)));
            break 2;
        }
        foreach ($res['items'] ?? [] as $item) {
            $videoIds[$item['id']['videoId']][] = $keyword;
        }
        $pageToken = $res['nextPageToken'] ?? null;
        if (!$pageToken) {
            break;
        }
    }
}
$searchCount = count($videoIds);

// 2. 記録済みチャンネル＋手動登録チャンネルの新着動画を集める
//    （playlistItems.list: 1 ユニット/チャンネル。検索の 1 日 100 回には含まれない）
$tracked = is_file(CHANNELS_FILE) ? (json_decode(file_get_contents(CHANNELS_FILE), true) ?: []) : [];
$manual = $config['watch_channels'] ?? [];
foreach ($manual as $id) {
    $tracked[$id] = ($tracked[$id] ?? []) + ['title' => '', 'source' => 'manual', 'added' => date('Y-m-d')];
    $tracked[$id]['source'] = 'manual';
}

// 登録者数が上限を超えた・非公開になった・消えたチャンネルは記録から外す（手動登録は残す）
$trackedInfo = $tracked ? fetch_channels(array_keys($tracked), $apiKey) : [];
foreach (array_keys($tracked) as $id) {
    if ($tracked[$id]['source'] === 'manual') {
        continue;
    }
    $info = $trackedInfo[$id] ?? null;
    if (!$info || !empty($info['statistics']['hiddenSubscriberCount'])
        || (int)($info['statistics']['subscriberCount'] ?? 0) > $config['max_subscribers']) {
        unset($tracked[$id]);
    }
}

// 各チャンネルの「アップロード動画」プレイリスト（UC... → UU...）から新着を取得
$playlistParams = [];
foreach (array_keys($tracked) as $id) {
    if (strpos($id, 'UC') === 0) {
        $playlistParams[$id] = [
            'key' => $apiKey,
            'part' => 'contentDetails',
            'playlistId' => 'UU' . substr($id, 2),
            'maxResults' => 10,
        ];
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
    log_line('ERROR no candidates found; HTML not updated');
    exit(1);
}

// 3. 動画の詳細（再生数・言語・再生時間）を取得（videos.list: 1 ユニット/50 件）
$videos = [];
foreach (array_chunk($videoIds, 50) as $chunk) {
    $res = api_get('videos', [
        'key' => $apiKey,
        'part' => 'snippet,statistics,contentDetails',
        'id' => implode(',', $chunk),
    ]);
    foreach ($res['items'] ?? [] as $v) {
        $videos[] = $v;
    }
}

// 4. チャンネルの登録者数を取得（記録済みチャンネルは取得済みの情報を使う）
$channelIds = array_map(fn($v) => $v['snippet']['channelId'], $videos);
$channels = $trackedInfo + fetch_channels(array_diff($channelIds, array_keys($trackedInfo)), $apiKey);

// 5. 条件で絞り込み
$rows = [];
$pickupCandidates = [];
foreach ($videos as $v) {
    $sn = $v['snippet'];
    $ch = $channels[$sn['channelId']] ?? null;
    if (!$ch || !empty($ch['statistics']['hiddenSubscriberCount'])) {
        continue;
    }
    $subs = (int)($ch['statistics']['subscriberCount'] ?? 0);
    if ($subs < $config['min_subscribers'] || $subs > $config['max_subscribers']) {
        continue;
    }
    // 日本語判定: 音声言語が ja、またはタイトル/説明文に日本語を含む
    $lang = $sn['defaultAudioLanguage'] ?? $sn['defaultLanguage'] ?? '';
    if (strpos($lang, 'ja') !== 0 && !has_japanese($sn['title'] . ' ' . ($sn['description'] ?? ''))) {
        continue;
    }
    // 記録済みチャンネル経由の動画は、タイトル・タグ・説明欄にキーワードがあるものだけ（雑談動画などを除く）
    $hits = $hitKeywords[$v['id']] ?: matched_words(
        $sn['title'] . ' ' . implode(' ', $sn['tags'] ?? []) . ' ' . ($sn['description'] ?? ''),
        $config['keywords']
    );
    if (!$hits) {
        continue;
    }
    // 「MV」「music video」でしかヒットしていない動画は、説明欄にひらがなが必要
    if (!array_diff($hits, $config['keywords_require_hiragana']) && !has_hiragana($sn['description'] ?? '')) {
        continue;
    }
    // 歌ってみた・カバー・BGM などを除外
    if (has_excluded_word($sn, $config['exclude_words'])) {
        continue;
    }
    // AI 生成のタグ・ハッシュタグ（#AI, #AI音楽, #suno など）が付いた動画は除外
    if (!empty($config['exclude_ai_tagged']) && has_ai_tag($sn)) {
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
    ];
    $rows[] = $row;

    // ピックアップ候補（登録者が少なく、作りこみの点数が基準以上）
    if ($subs <= $config['pickup_max_subscribers'] && !mentions_ai($sn)) {
        [$score, $reasons] = craft_score($v);
        if ($score >= $config['pickup_min_score']) {
            $pickupCandidates[] = $row + [
                'channel_id' => $sn['channelId'],
                'channel_videos' => (int)($ch['statistics']['videoCount'] ?? 0),
                'channel_created' => strtotime($ch['snippet']['publishedAt'] ?? 'now'),
                'score' => $score,
                'reasons' => $reasons,
                // X のアカウント（動画説明欄 → チャンネル説明文の順に探す）
                'x_handle' => find_x_handle($sn['description'] ?? '') ?? find_x_handle($ch['snippet']['description'] ?? ''),
            ];
        }
    }

    // リストに載ったチャンネルを記録（次回から検索に頼らず新着を確認する）
    $id = $sn['channelId'];
    $tracked[$id] = ($tracked[$id] ?? ['source' => 'search', 'added' => date('Y-m-d')]);
    $tracked[$id]['title'] = $sn['channelTitle'];
    $tracked[$id]['last_listed'] = date('Y-m-d');
}

// 記録数の上限を超えたら、最後にリストに載った日が古いものから外す（手動登録は残す）
$max = $config['max_tracked_channels'] ?? 3000;
if (count($tracked) > $max) {
    uasort($tracked, fn($a, $b) => ($b['source'] === 'manual') <=> ($a['source'] === 'manual')
        ?: strcmp($b['last_listed'] ?? '', $a['last_listed'] ?? ''));
    $tracked = array_slice($tracked, 0, $max, true);
}
file_put_contents(CHANNELS_FILE . '.tmp', json_encode($tracked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
rename(CHANNELS_FILE . '.tmp', CHANNELS_FILE);

// 6. ピックアップ: 量産型のチャンネルを除き、作りこみの点数が高い順に選ぶ
//    量産型の判定 = 直近 30 日の投稿数が多い、または開設以来の平均投稿ペースが速い
$recentUploads = [];
$params = [];
foreach (array_unique(array_column($pickupCandidates, 'channel_id')) as $id) {
    $params[$id] = ['key' => $apiKey, 'part' => 'contentDetails', 'playlistId' => 'UU' . substr($id, 2), 'maxResults' => 50];
}
foreach (api_get_multi('playlistItems', $params) as $id => $res) {
    $recentUploads[$id] = count(array_filter($res['items'] ?? [],
        fn($i) => strtotime($i['contentDetails']['videoPublishedAt'] ?? '') >= time() - 30 * 86400));
}
$pickup = [];
foreach ($pickupCandidates as $c) {
    $ageDays = max(30, (time() - $c['channel_created']) / 86400);
    $perWeek = $c['channel_videos'] / $ageDays * 7;
    if (($recentUploads[$c['channel_id']] ?? 0) > $config['pickup_max_uploads_30d'] || $perWeek > $config['pickup_max_uploads_per_week']) {
        continue;
    }
    // 1 チャンネル 1 本まで（点数が高いものを優先）
    if (isset($pickup[$c['channel_id']]) && $pickup[$c['channel_id']]['score'] >= $c['score']) {
        continue;
    }
    $pickup[$c['channel_id']] = $c;
}
usort($pickup, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['views'] <=> $b['views']);
$pickup = array_slice($pickup, 0, $config['pickup_limit']);
foreach ($pickup as &$p) {
    $p['post_text'] = build_post_text($p);
}
unset($p);

// 7. 再生数の昇順（少ない順）
usort($rows, fn($a, $b) => $a['views'] <=> $b['views']);

// 8. HTML を書き出す（一時ファイル → rename で差し替え、閲覧中に壊れたページを出さない）
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

$updatedAt = date('Y-m-d H:i');
$vars = compact('rows', 'pickup', 'config', 'updatedAt');
render_to('template.php', $config['output_html'], $vars);

// X 投稿用ページ（検索エンジンに載らないよう noindex。トップページからはリンクしない）
if (!empty($config['output_post_html'])) {
    render_to('template_post.php', $config['output_post_html'], $vars);
}

log_line(sprintf('OK search=%d tracked_channels=%d tracked_new_videos=%d listed=%d pickup=%d',
    $searchCount, count($tracked), $watchCount, count($rows), count($pickup)));
