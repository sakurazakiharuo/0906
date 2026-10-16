<?php
// config.php にコピーして API キーと出力先を設定する（config.php は公開ディレクトリに置かない）
return [
    // Google Cloud Console で発行した YouTube Data API v3 のキー
    'api_key' => 'YOUR_API_KEY',

    // 生成する HTML の出力先。このフォルダをホーム直下（~/youtube-indie-songs）に置けば
    // ~/www/indie-songs/index.html に出力され、https://アカウント名.sakura.ne.jp/indie-songs/ で見られる
    'output_html' => dirname(__DIR__) . '/www/indie-songs/index.html',

    // X 投稿用ページ（検索エンジンに載らない非公開ページ）。推測されにくい名前にする
    // 不要なら null
    'post_min_duration_sec' => 40, // 投稿用ページでは、この秒数以下の動画を除外する
    'output_post_html' => dirname(__DIR__) . '/www/indie-songs/post-CHANGE-THIS-TO-RANDOM.html',

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

    // AI 扱いしないチャンネル（チャンネルID）。AI に触れた動画でもリストに載せたいチャンネルだけ書く
    'ai_allow_channels' => [],

    // この秒数以上の動画は除外（8分 = 480秒）
    'max_duration_sec' => 480,

    // 抽出条件
    'min_subscribers' => 0,
    'max_subscribers' => 300,
    'days' => 3,

    // ピックアップ（量産型ではなく、作りこまれた、登録者の少ない動画）
    'pickup_max_subscribers' => 10,     // 登録者数の上限
    'pickup_min_score' => 3,            // 作りこみ点数の下限（クレジット・歌詞・フル尺・MV・説明文で加点）
    'pickup_max_uploads_30d' => 12,     // 直近 30 日の歌動画がこれを超えるチャンネルは量産型とみなす
    'pickup_max_uploads_per_week' => 3, // 歌動画の平均投稿ペース（本/週）がこれを超えるチャンネルも量産型
    // 量産型の判定で「歌動画」として数える語（タイトル・説明欄に含まれていれば歌動画）
    'song_words' => ['オリジナル曲', 'オリジナルソング', 'オリジナル楽曲', '新曲', 'MV', 'music video', 'lyric video',
                     '作詞', '作曲', '編曲', '歌詞', 'lyrics', 'vocal', 'ボーカル', '弾き語り', 'song'],
    'pickup_limit' => 10,               // 表示する最大件数

    // 芽吹き（再生数ランキング）の表示件数
    'mebuki_limit' => 10,

    // 1キーワードあたりの検索ページ数（1ページ=50件、100ユニット消費）
    'pages_per_keyword' => 4,
];
