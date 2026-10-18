<?php
// 除外リスト（X 投稿用ページの「除外」ボタンで追加される）
// excluded.json に保存し、fetch.php は次回以降これに入った動画・チャンネルをどのリストにも載せない

const EXCLUSIONS_FILE = __DIR__ . '/excluded.json';

function load_exclusions(): array
{
    $data = is_file(EXCLUSIONS_FILE) ? json_decode(file_get_contents(EXCLUSIONS_FILE), true) : null;
    return [
        'videos' => $data['videos'] ?? [],
        'channels' => $data['channels'] ?? [],
        'posted' => $data['posted'] ?? [], // X 投稿用ページで「ポスト」を押した動画（投稿用ページにだけ出さない）
    ];
}

// $type: 'videos' | 'channels' | 'posted'、$add: true で追加・false で取り消し
function update_exclusion(string $type, string $id, bool $add, string $title = ''): array
{
    $fp = fopen(EXCLUSIONS_FILE . '.lock', 'c');
    flock($fp, LOCK_EX); // 同時に押されても壊れないように
    $ex = load_exclusions();
    if ($add) {
        $ex[$type][$id] = ['title' => $title, 'at' => date('Y-m-d H:i')];
    } else {
        unset($ex[$type][$id]);
    }
    file_put_contents(EXCLUSIONS_FILE . '.tmp', json_encode($ex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename(EXCLUSIONS_FILE . '.tmp', EXCLUSIONS_FILE);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ex;
}

// 除外ボタン用の合言葉（config に exclude_token があればそれ、なければ API キーから自動で作る）
function exclusion_token(array $config): string
{
    return $config['exclude_token'] ?? substr(hash('sha256', 'exclude:' . $config['api_key']), 0, 32);
}
