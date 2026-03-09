<?php

function extractResponseText(array $data): string
{
    if (isset($data['output_text']) && is_string($data['output_text']) && trim($data['output_text']) !== '') {
        return $data['output_text'];
    }

    $messages = [];
    if (isset($data['output']) && is_array($data['output'])) {
        $messages = array_values(array_filter(
            $data['output'],
            fn($item) => ($item['type'] ?? '') === 'message'
        ));
    }

    foreach ($messages as $message) {
        $content = $message['content'] ?? [];
        if (is_array($content)) {
            foreach ($content as $part) {
                if (
                    ($part['type'] ?? '') === 'output_text' &&
                    isset($part['text']) &&
                    is_string($part['text'])
                ) {
                    return $part['text'];
                }
            }
        }
    }

    return '';
}
