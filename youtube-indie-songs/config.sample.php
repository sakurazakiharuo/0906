<?php
// config.php にコピーして API キーと出力先を設定する（config.php は公開ディレクトリに置かない）
return [
    // Google Cloud Console で発行した YouTube Data API v3 のキー
    'api_key' => 'YOUR_API_KEY',

    // 生成する HTML の出力先。このフォルダをホーム直下（~/youtube-indie-songs）に置けば
    // ~/www/indie-songs/index.html に出力され、https://アカウント名.sakura.ne.jp/indie-songs/ で見られる
    'output_html' => dirname(__DIR__) . '/www/indie-songs/index.html',

    // 検索キーワード
    'keywords' => ['オリジナル曲', 'オリジナルソング', 'オリジナル楽曲', 'MV', 'music video'],

    // これらのキーワードは音楽カテゴリの動画だけを検索する（それ以外はカテゴリを問わない）
    'music_category_only_keywords' => ['MV', 'music video'],

    // 毎回新着を確認するチャンネル（チャンネルID: UC で始まる文字列）
    // リストに載ったチャンネルは channels.json に自動で記録されるので、ここには必ず確認したいものだけ書く
    'watch_channels' => [
        'UCnn0hwrrHt1VBL0tbCmwodQ', // 蘇芳れな(SUOU RENA)
    ],

    // 自動で記録するチャンネル数の上限（1 チャンネルにつき 1 ユニット/回。1 日の上限 10,000 ユニット）
    'max_tracked_channels' => 3000,

    // これらのキーワードでしかヒットしなかった動画は、説明欄にひらがながあるものだけ残す
    'keywords_require_hiragana' => ['MV', 'music video'],

    // タイトル・タグにこれらの語を含む動画は除外（大文字小文字は区別しない）
    'exclude_words' => ['歌ってみた', '歌って見た', 'うたってみた', 'カバー', 'cover', 'BGM'],

    // タグ・ハッシュタグに AI 生成を示す語（AI, AI音楽, AIMusic, suno など）がある動画を除外
    'exclude_ai_tagged' => true,

    // この秒数以上の動画は除外（8分 = 480秒）
    'max_duration_sec' => 480,

    // 抽出条件
    'min_subscribers' => 0,
    'max_subscribers' => 300,
    'days' => 3,

    // 1キーワードあたりの検索ページ数（1ページ=50件、100ユニット消費）
    'pages_per_keyword' => 4,
];
