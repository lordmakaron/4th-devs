<?php

const MAX_HEADER_EXPORT = 1;
const MAX_BODY_EXPORT    = 5;

function splitParagraphs(string $markdown): array
{
    $normalized = str_replace("\r\n", "\n", $markdown);
    $blocks     = preg_split('/\n\s*\n+/', $normalized);
    $result     = [];
    foreach ($blocks as $block) {
        $trimmed = trim($block);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }
    return $result;
}

function chunkArray(array $items, int $size): array
{
    return array_chunk($items, $size);
}

function truncateText(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 3) . '...';
}

function getParagraphType(string $paragraph): string
{
    return preg_match('/^#{1,6}\s+/', $paragraph) ? 'header' : 'body';
}

function getTargetCount(string $paragraphType): string
{
    return $paragraphType === 'header'
        ? '0-' . MAX_HEADER_EXPORT
        : '2-' . MAX_BODY_EXPORT;
}
