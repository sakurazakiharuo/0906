<?php
/**
 * 言語ごとの設定（検索語・除外語・判定方法・画面の文言・投稿文）
 *
 * 日本語は config.php の従来の項目（keywords, exclude_words など）をそのまま使う。
 * 繁體中文・한국어 はここに既定値を持ち、config.php の 'languages' => ['zh' => [...], 'ko' => [...]] で上書きできる。
 * 使う言語は config.php の 'enabled_languages'（既定: ['ja', 'zh', 'ko']）。
 */

function language_profiles(array $config): array
{
    $dir = dirname($config['output_html']);
    $profiles = [
        'ja' => [
            'tab' => '日本語',
            'html_lang' => 'ja',
            'output' => $config['output_html'],
            'href' => './',
            'tracked_file' => __DIR__ . '/channels.json',
            'region' => 'JP',
            'relevance_lang' => 'ja',
            'script' => 'ja',
            'keywords' => $config['keywords'],
            'music_category_only_keywords' => $config['music_category_only_keywords'] ?? ['MV', 'music video'],
            'loose_keywords' => $config['keywords_require_hiragana'] ?? ['MV', 'music video'],
            'exclude_words' => $config['exclude_words'],
            'compilation_regex' => 'ランキング|ranking|(?<![a-z])top\s*\d+|ベスト\s*\d+|まとめ|再生回数|リアクション|reaction|解説|歴代|人気曲|ヒット曲|名曲|聴き比べ|作業用|(?<![a-z])vs\.?(?![a-z])',
            'song_words' => $config['song_words'] ?? ['オリジナル曲', 'オリジナルソング', 'オリジナル楽曲', '新曲', 'MV', 'music video', 'lyric video',
                '作詞', '作曲', '編曲', '歌詞', 'lyrics', 'vocal', 'ボーカル', '弾き語り', 'song'],
            'pages_per_keyword' => $config['pages_per_keyword'],
            'watch_channels' => $config['watch_channels'] ?? [],
            'ui' => [
                'title' => '初期推し（YouTube歌動画低再生数リスト）',
                'h1' => '初期推し', 'sub' => "まだ知られていない才能に出会える「初期推し」。\n未来の人気アーティストを、誰よりも早く見つけよう！",
                'meta' => '登録者 %d〜%d 人 / %d 日以内の投稿 / 再生数の少ない順　｜　最終更新: %s（%d 件）',
                'pickup' => '✨ ピックアップ｜こだわりの新人アーティスト',
                // 説明文は段落ごと。%1$d = ピックアップの登録者数の上限
                'pickup_note' => [
                    'チャンネル登録者%1$d人以下のアーティストから、歌詞や映像、楽曲制作にこだわりが感じられる動画を自動でピックアップ！',
                    'まだほとんど知られていない、あなた好みの音楽が見つかるかもしれません。',
                    '（YouTubeチャンネル登録%1$d人以下での新着動画抽出）',
                ],
                'mebuki' => '芽吹き｜注目を集めはじめた楽曲 TOP%d',
                // %1$d = 表示件数、%2$d = 新着リストの登録者数の上限
                'mebuki_note' => [
                    'まだ知名度は低くても、再生されはじめている楽曲があります。',
                    '新着動画の中から、再生数が多い%1$d曲をランキングで紹介！',
                    '未来の人気アーティストが、ここから生まれるかもしれません。',
                    '（YouTubeチャンネル登録%2$d人以下の新着動画再生数ランキング）',
                ],
                'list' => '新着リスト',
                'cols' => ['#', 'アーティスト名', '登録者数', '動画タイトル', '再生数', '投稿日時'], 'rank' => '順位',
                'stats' => '登録者 %s 人 ・ 再生 %s 回',
                'empty' => '該当する動画がありません',
                'xbtn' => '𝕏 でポスト',
                'credit' => 'データ提供: YouTube Data API',
                'login' => 'Google でログイン', 'logout' => 'ログアウト', 'mylist' => 'MY推し一覧', 'ranking' => 'みんなのMY推しランキング',
                'oshi_add' => '＋MY推し', 'oshi_added' => '✓MY推し',
                'login_note' => 'Google アカウントでログインすると、気になったチャンネルを「MY推し」に登録して、登録した時点からの登録者数の伸びを確かめられます。',
            ],
            'post' => [
                'pickup' => '🎤 初期推しピックアップ',
                'mebuki' => '🌱 芽吹き｜再生数ランキング %d位',
                'views' => '再生 %s回',
                'subs' => '（登録者%d人）',
                'tags' => '#初期推し #オリジナル曲',
            ],
            'reasons' => [
                'roles' => ['lyrics' => '作詞', 'compose' => '作曲', 'arrange' => '編曲', 'vocal' => '歌', 'video' => '映像', 'illust' => 'イラスト', 'mix' => 'ミックス', 'play' => '演奏'],
                'credit_rich' => 'クレジット充実（%s）', 'credit' => 'クレジットあり（%s）', 'sep' => '・',
                'lyrics' => '歌詞掲載', 'full' => 'フル尺 %d分%d秒', 'mv' => 'MV', 'desc' => '説明文が丁寧',
            ],
        ],

        'zh' => [
            'tab' => '繁體中文',
            'html_lang' => 'zh-Hant',
            'output' => $dir . '/zh-tw.html',
            'href' => 'zh-tw.html',
            'tracked_file' => __DIR__ . '/channels-zh.json',
            'region' => 'TW',
            'relevance_lang' => 'zh-Hant',
            'script' => 'zh_hant',
            'keywords' => ['原創歌曲', '原創曲', '自創曲', '創作歌曲', 'MV'],
            'music_category_only_keywords' => ['MV'],
            'loose_keywords' => ['MV'],
            // 翻唱＝カバー、動態歌詞・抖音・無損＝他人の曲の転載によくある語、伴奏・KTV＝カラオケ、歌單＝プレイリスト
            'exclude_words' => ['翻唱', 'cover', '翻自', 'Cover by', '動態歌詞', '动态歌词', '抖音', '無損', '无损', '高音質', 'KTV', '卡拉OK',
                '伴奏', '歌單', '歌单', 'playlist', 'BGM', '純音樂', '一小時', '1小時', '循環播放', 'Pinyin'],
            'compilation_regex' => '排行|排名|(?<![a-z])top\s*\d+|合集|串燒|串烧|盤點|反應|reaction|解說|歷代|熱門歌曲|經典歌曲|名曲|(?<![a-z])vs\.?(?![a-z])',
            'song_words' => ['原創', '自創', '創作', '新歌', '新曲', '單曲', 'MV', 'music video', 'lyric video',
                '作詞', '作曲', '編曲', '歌詞', '演唱', '主唱', '自彈自唱', 'song'],
            'pages_per_keyword' => 3,
            'watch_channels' => [],
            'ui' => [
                'title' => '初期推し（YouTube 原創歌曲・低播放清單）',
                'h1' => '初期推し', 'sub' => "在這裡遇見還沒被發現的才華——「初期推し」。\n比任何人都更早，找到未來的人氣歌手！",
                'meta' => '訂閱 %d〜%d 人 / %d 天內上傳 / 依播放次數由少到多　｜　最後更新：%s（%d 部）',
                'pickup' => '✨ 精選推薦｜用心創作的新人歌手',
                'pickup_note' => [
                    '從頻道訂閱 %1$d 人以下的歌手中，自動挑選在歌詞、影像與音樂製作上能感受到用心的影片！',
                    '說不定能找到幾乎還沒人知道、正合你口味的音樂。',
                    '（從 YouTube 頻道訂閱 %1$d 人以下的最新影片中挑選）',
                ],
                'mebuki' => '萌芽｜開始受到關注的歌曲 TOP%d',
                'mebuki_note' => [
                    '雖然知名度還不高，卻已經開始被聽見的歌曲。',
                    '從最新影片中，以排行介紹播放次數最多的 %1$d 首歌！',
                    '未來的人氣歌手，說不定就從這裡誕生。',
                    '（YouTube 頻道訂閱 %2$d 人以下的最新影片播放次數排行）',
                ],
                'list' => '最新清單',
                'cols' => ['#', '歌手／頻道', '訂閱數', '影片標題', '播放次數', '上傳時間'], 'rank' => '名次',
                'stats' => '訂閱 %s 人 ・ 播放 %s 次',
                'empty' => '目前沒有符合條件的影片',
                'xbtn' => '𝕏 分享',
                'credit' => '資料來源：YouTube Data API',
                'login' => 'Google 登入', 'logout' => '登出', 'mylist' => 'MY推し 清單', 'ranking' => '大家的 MY推し 排行',
                'oshi_add' => '＋MY推し', 'oshi_added' => '✓MY推し',
                'login_note' => '用 Google 帳號登入後，可以把喜歡的頻道登記為「MY推し」，追蹤登記之後訂閱數的成長。',
            ],
            'post' => [
                'pickup' => '🎤 初期推し 精選推薦',
                'mebuki' => '🌱 萌芽｜播放次數排行 第%d名',
                'views' => '播放 %s 次',
                'subs' => '（訂閱 %d 人）',
                'tags' => '#初期推し #原創歌曲',
            ],
            'reasons' => [
                'roles' => ['lyrics' => '作詞', 'compose' => '作曲', 'arrange' => '編曲', 'vocal' => '演唱', 'video' => '影像', 'illust' => '插畫', 'mix' => '混音', 'play' => '演奏'],
                'credit_rich' => '製作名單完整（%s）', 'credit' => '附製作名單（%s）', 'sep' => '・',
                'lyrics' => '附歌詞', 'full' => '完整版 %d分%d秒', 'mv' => 'MV', 'desc' => '說明用心',
            ],
        ],

        'ko' => [
            'tab' => '한국어',
            'html_lang' => 'ko',
            'output' => $dir . '/ko.html',
            'href' => 'ko.html',
            'tracked_file' => __DIR__ . '/channels-ko.json',
            'region' => 'KR',
            'relevance_lang' => 'ko',
            'script' => 'ko',
            // 자작곡＝自作曲（インディーのオリジナル曲を指す最も一般的な言い方）
            'keywords' => ['자작곡', '창작곡', '오리지널곡', 'MV', '뮤직비디오'],
            'music_category_only_keywords' => ['MV', '뮤직비디오'],
            'loose_keywords' => ['MV', '뮤직비디오'],
            // 커버＝カバー、불러보았다＝歌ってみた、노래방＝カラオケ、MR・반주＝伴奏、플리＝プレイリスト、가사/해석＝転載によくある語
            'exclude_words' => ['커버', 'cover', '불러보았다', '불러봤다', '불러봄', '노래방', 'MR', '반주', '플레이리스트', 'playlist', '플리',
                'BGM', '1시간', '반복재생', '가사/해석', '가사 해석', '한글자막', '번역'],
            // 정규N집・타이틀곡＝プロのアルバム曲（ファンによる紹介動画に多い）
            'compilation_regex' => '랭킹|순위|(?<![a-z])top\s*\d+|모음|메들리|리액션|reaction|해설|역대|명곡|인기곡|(?<![a-z])vs\.?(?![a-z])|정규\s*\d+\s*집|타이틀곡',
            'song_words' => ['자작곡', '창작곡', '오리지널', '신곡', '싱글', 'MV', '뮤직비디오', 'music video', 'lyric video',
                '작사', '작곡', '편곡', '가사', '보컬', 'song'],
            'pages_per_keyword' => 3,
            'watch_channels' => [],
            'ui' => [
                'title' => '初期推し（YouTube 자작곡・저조회수 리스트）',
                'h1' => '初期推し', 'sub' => "아직 알려지지 않은 재능을 만나는 「初期推し」.\n미래의 인기 아티스트를 누구보다 먼저 찾아보세요!",
                'meta' => '구독자 %d~%d명 / %d일 이내 업로드 / 조회수 적은 순　｜　최종 업데이트: %s (%d개)',
                'pickup' => '✨ 픽업｜공들인 신인 아티스트',
                'pickup_note' => [
                    '채널 구독자 %1$d명 이하의 아티스트 중에서 가사・영상・음악 제작에 정성이 느껴지는 영상을 자동으로 골랐습니다!',
                    '아직 거의 알려지지 않은, 당신 취향의 음악을 만날지도 몰라요.',
                    '(YouTube 채널 구독자 %1$d명 이하의 신규 영상에서 추출)',
                ],
                'mebuki' => '새싹｜주목받기 시작한 곡 TOP%d',
                'mebuki_note' => [
                    '아직 인지도는 낮아도, 조금씩 재생되기 시작한 곡이 있습니다.',
                    '신규 영상 중에서 조회수가 많은 %1$d곡을 랭킹으로 소개합니다!',
                    '미래의 인기 아티스트가 여기서 탄생할지도 몰라요.',
                    '(YouTube 채널 구독자 %2$d명 이하 신규 영상의 조회수 랭킹)',
                ],
                'list' => '신규 리스트',
                'cols' => ['#', '아티스트', '구독자 수', '영상 제목', '조회수', '업로드 일시'], 'rank' => '순위',
                'stats' => '구독자 %s명 ・ 조회수 %s회',
                'empty' => '조건에 맞는 영상이 없습니다',
                'xbtn' => '𝕏 공유',
                'credit' => '데이터 제공: YouTube Data API',
                'login' => 'Google 로그인', 'logout' => '로그아웃', 'mylist' => 'MY推し 목록', 'ranking' => '모두의 MY推し 랭킹',
                'oshi_add' => '＋MY推し', 'oshi_added' => '✓MY推し',
                'login_note' => 'Google 계정으로 로그인하면 마음에 드는 채널을 「MY推し」로 등록하고, 등록한 시점부터 구독자 수가 얼마나 늘었는지 확인할 수 있습니다.',
            ],
            'post' => [
                'pickup' => '🎤 初期推し 픽업',
                'mebuki' => '🌱 새싹｜조회수 랭킹 %d위',
                'views' => '조회수 %s회',
                'subs' => ' (구독자 %d명)',
                'tags' => '#初期推し #자작곡',
            ],
            'reasons' => [
                'roles' => ['lyrics' => '작사', 'compose' => '작곡', 'arrange' => '편곡', 'vocal' => '보컬', 'video' => '영상', 'illust' => '일러스트', 'mix' => '믹싱', 'play' => '연주'],
                'credit_rich' => '크레디트 충실 (%s)', 'credit' => '크레디트 있음 (%s)', 'sep' => '・',
                'lyrics' => '가사 수록', 'full' => '풀버전 %d분%d초', 'mv' => 'MV', 'desc' => '설명이 정성스러움',
            ],
        ],
    ];

    // すべての X 投稿文の最後に入れる行（config.php の 'post_footer' で変更可。'' なら入れない）
    $footer = $config['post_footer'] ?? '初期推しサイト:https://song.nakamurakozo.tv/';
    foreach ($profiles as &$profile) {
        $profile['post']['footer'] = $footer;
    }
    unset($profile);

    // config.php の 'languages' で上書き（例: 'languages' => ['zh' => ['watch_channels' => ['UC...']]]）
    foreach ($config['languages'] ?? [] as $code => $override) {
        if (isset($profiles[$code])) {
            $profiles[$code] = array_replace($profiles[$code], $override);
        }
    }
    $enabled = $config['enabled_languages'] ?? ['ja', 'zh', 'ko'];
    $result = [];
    foreach ($enabled as $code) {
        if (isset($profiles[$code])) {
            $result[$code] = $profiles[$code] + ['code' => $code];
        }
    }
    return $result;
}
