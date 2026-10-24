<?php
// 除外ボタンの受け口の本体（公開フォルダの exclude-xxxx.php から読み込まれる）
//   GET  ?token=… → 除外リストを返す
//   POST {"token", "type": "videos"|"channels"|"posted", "id", "title", "add": true|false} → 追加・取り消し
//
// インターネットから直接届く唯一の PHP なので、想定外の入力は処理の前に断る
// （PHP 本体の脆弱性 PHP2Shell などの対策は PHP の更新が本筋。ここでは受け付ける入力を最小限にする）
// API キー（config.php）はここでは読み込まない

require APP_DIR . '/exclusions.php';
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    reply(405, ['error' => 'method not allowed']);
}

if ($method === 'POST') {
    // JSON 以外（フォーム送信・ファイルアップロードなど）と、大きすぎる本文は受け付けない
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        reply(415, ['error' => 'json only']);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        reply(413, ['error' => 'too large']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        reply(413, ['error' => 'too large']);
    }
    $input = json_decode($raw, true, 4);
    if (!is_array($input)) {
        reply(400, ['error' => 'invalid json']);
    }
} else {
    $input = $_GET;
}

// 値はすべて文字列（add だけ真偽値）であること。配列などが来たら断る
$token = $input['token'] ?? '';
if (!is_string($token) || !hash_equals(EXCLUDE_TOKEN, $token)) {
    reply(403, ['error' => 'forbidden']);
}
if ($method === 'GET') {
    reply(200, load_exclusions());
}

$type = $input['type'] ?? '';
$id = $input['id'] ?? '';
$title = $input['title'] ?? '';
$add = $input['add'] ?? false;
if (!is_string($type) || !is_string($id) || !is_string($title) || !is_bool($add)) {
    reply(400, ['error' => 'invalid input']);
}
$valid = (($type === 'videos' || $type === 'posted') && preg_match('/^[A-Za-z0-9_-]{11}$/', $id))
    || ($type === 'channels' && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id));
if (!$valid) {
    reply(400, ['error' => 'invalid id']);
}
$title = mb_substr(strip_tags($title), 0, 200, 'UTF-8');
reply(200, update_exclusion($type, $id, $add, $title));
