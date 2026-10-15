<?php
// 除外ボタンの受け口の本体（公開フォルダの exclude-xxxx.php から読み込まれる）
//   GET  ?token=… → 除外リストを返す
//   POST {"token", "type": "videos"|"channels", "id", "title", "add": true|false} → 追加・取り消し

require APP_DIR . '/exclusions.php';
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_GET;
if (!hash_equals(EXCLUDE_TOKEN, (string)($input['token'] ?? ''))) {
    reply(403, ['error' => 'forbidden']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(200, load_exclusions());
}

$type = $input['type'] ?? '';
$id = (string)($input['id'] ?? '');
$valid = ($type === 'videos' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id))
    || ($type === 'channels' && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id));
if (!$valid) {
    reply(400, ['error' => 'invalid id']);
}
$title = mb_substr(strip_tags((string)($input['title'] ?? '')), 0, 200);
reply(200, update_exclusion($type, $id, !empty($input['add']), $title));
