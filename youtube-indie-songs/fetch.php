<?php
/**
 * YouTube から「登録者 10〜300 人のチャンネルが直近 3 日以内に投稿した日本語の歌動画」を集め、
 * 再生数の降順で並べた静的 HTML を生成する。cron から毎朝 7 時に実行する想定。
 *
 *   /usr/local/bin/php /home/YOUR_ACCOUNT/youtube-indie-songs/fetch.php
 */

date_default_timezone_set('Asia/Tokyo');

$config = require __DIR__ . '/config.php';
$apiKey = $config['api_key'];
require __DIR__ . '/functions.php';

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

// 1. キーワード検索で候補動画を集める（search.list: 100 ユニット/回）
$publishedAfter = gmdate('Y-m-d\TH:i:s\Z', time() - $config['days'] * 86400);
$videoIds = []; // videoId => ヒットしたキーワード一覧
foreach ($config['keywords'] as $keyword) {
    $pageToken = null;
    for ($page = 0; $page < $config['pages_per_keyword']; $page++) {
        $params = [
            'key' => $apiKey,
            'part' => 'snippet',
            'q' => $keyword,
            'type' => 'video',
            'videoCategoryId' => '10', // Music
            'publishedAfter' => $publishedAfter,
            'order' => 'date',
            'regionCode' => 'JP',
            'relevanceLanguage' => 'ja',
            'maxResults' => 50,
        ];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }
        $res = api_get('search', $params);
        foreach ($res['items'] ?? [] as $item) {
            $videoIds[$item['id']['videoId']][] = $keyword;
        }
        $pageToken = $res['nextPageToken'] ?? null;
        if (!$pageToken) {
            break;
        }
    }
}
$hitKeywords = $videoIds;
$videoIds = array_keys($videoIds);

// 2. 動画の詳細（再生数・言語）を取得（videos.list: 1 ユニット/50 件）
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

// 3. チャンネルの登録者数を取得（channels.list: 1 ユニット/50 件）
$channelIds = array_values(array_unique(array_map(fn($v) => $v['snippet']['channelId'], $videos)));
$channels = [];
foreach (array_chunk($channelIds, 50) as $chunk) {
    $res = api_get('channels', [
        'key' => $apiKey,
        'part' => 'snippet,statistics',
        'id' => implode(',', $chunk),
    ]);
    foreach ($res['items'] ?? [] as $c) {
        $channels[$c['id']] = $c;
    }
}

// 4. 条件で絞り込み
$rows = [];
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
    // 「MV」「music video」でしかヒットしていない動画は、説明欄にひらがなが必要
    $onlyLooseKeywords = !array_diff($hitKeywords[$v['id']] ?? [], $config['keywords_require_hiragana']);
    if ($onlyLooseKeywords && !has_hiragana($sn['description'] ?? '')) {
        continue;
    }
    // 歌ってみた・カバー・BGM などを除外
    if (has_excluded_word($sn, $config['exclude_words'])) {
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
    $rows[] = [
        'artist' => $sn['channelTitle'],
        'channel_url' => 'https://www.youtube.com/channel/' . $sn['channelId'],
        'subscribers' => $subs,
        'title' => $sn['title'],
        'url' => 'https://www.youtube.com/watch?v=' . $v['id'],
        'views' => (int)($v['statistics']['viewCount'] ?? 0),
        'published' => date('Y-m-d H:i', strtotime($sn['publishedAt'])),
    ];
}

// 5. 再生数の降順
usort($rows, fn($a, $b) => $b['views'] <=> $a['views']);

// 6. HTML を書き出す（一時ファイル → rename で差し替え、閲覧中に壊れたページを出さない）
$updatedAt = date('Y-m-d H:i');
ob_start();
require __DIR__ . '/template.php';
$html = ob_get_clean();

$out = $config['output_html'];
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0755, true);
}
$tmp = $out . '.tmp';
file_put_contents($tmp, $html);
rename($tmp, $out);

echo "[$updatedAt] candidates=" . count($videoIds) . " listed=" . count($rows) . "\n";
