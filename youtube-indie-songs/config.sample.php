<?php
// config.php にコピーして API キーと出力先を設定する（config.php は公開ディレクトリに置かない）
return [
    // Google Cloud Console で発行した YouTube Data API v3 のキー
    'api_key' => 'YOUR_API_KEY',

    // 生成する HTML の出力先（さくらのレンタルサーバなら ~/www 配下）
    'output_html' => '/home/YOUR_ACCOUNT/www/indie-songs/index.html',

    // 検索キーワード
    'keywords' => ['オリジナル曲', 'オリジナルソング', 'オリジナル楽曲', 'MV', 'music video'],

    // 抽出条件
    'min_subscribers' => 10,
    'max_subscribers' => 300,
    'days' => 3,

    // 1キーワードあたりの検索ページ数（1ページ=50件、100ユニット消費）
    'pages_per_keyword' => 4,
];
