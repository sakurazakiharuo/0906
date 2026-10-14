<?php

// 日本語（ひらがな・カタカナ・漢字）が含まれるか
function has_japanese(string $text): bool
{
    return preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text) === 1;
}

// 説明欄などにひらがなが含まれるか
function has_hiragana(string $text): bool
{
    return preg_match('/\p{Hiragana}/u', $text) === 1;
}

// ISO 8601 の再生時間（例: PT4M13S）を秒に変換
function duration_sec(string $iso): int
{
    try {
        $d = new DateInterval($iso);
    } catch (Exception $e) {
        return 0;
    }
    return $d->d * 86400 + $d->h * 3600 + $d->i * 60 + $d->s;
}

// $text に含まれる $words を返す（大文字小文字は区別しない）
function matched_words(string $text, array $words): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $hits = [];
    foreach ($words as $word) {
        $w = mb_strtolower($word, 'UTF-8');
        // 英単語は単語単位で判定（"cover" が "discover" に誤反応しないように）
        $hit = preg_match('/^[a-z0-9 ]+$/', $w)
            ? preg_match('/(?<![a-z0-9])' . preg_quote($w, '/') . '(?![a-z0-9])/u', $text) === 1
            : mb_strpos($text, $w) !== false;
        if ($hit) {
            $hits[] = $word;
        }
    }
    return $hits;
}

// タイトル・タグに除外語が含まれるか
function has_excluded_word(array $snippet, array $words): bool
{
    return (bool)matched_words($snippet['title'] . ' ' . implode(' ', $snippet['tags'] ?? []), $words);
}
