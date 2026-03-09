<?php

function stableStringify(mixed $value): string
{
    if (is_array($value) && array_is_list($value)) {
        $parts = array_map('stableStringify', $value);
        return '[' . implode(',', $parts) . ']';
    }

    if (is_array($value)) {
        $keys = array_keys($value);
        sort($keys);
        $entries = array_map(
            fn($key) => json_encode((string) $key) . ':' . stableStringify($value[$key]),
            $keys
        );
        return '{' . implode(',', $entries) . '}';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if ($value === null) {
        return 'null';
    }

    return json_encode($value);
}

function hashText(string $text): string
{
    return hash('sha256', $text);
}

function hashObject(mixed $obj): string
{
    return hashText(stableStringify($obj));
}
