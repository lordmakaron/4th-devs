<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/config.php';

function apiSleep(int $ms): void
{
    usleep($ms * 1000);
}

function isRetryableStatus(int $status): bool
{
    return in_array($status, [429, 500, 502, 503], true);
}

function buildRequestBody(array $options): array
{
    $body = [
        'model' => $options['model'],
        'input' => $options['input'],
    ];

    if (isset($options['textFormat'])) {
        $body['text'] = ['format' => $options['textFormat']];
    }
    if (isset($options['tools'])) {
        $body['tools'] = $options['tools'];
    }
    if (isset($options['include'])) {
        $body['include'] = $options['include'];
    }
    if (isset($options['reasoning'])) {
        $body['reasoning'] = $options['reasoning'];
    }
    if (isset($options['previousResponseId'])) {
        $body['previous_response_id'] = $options['previousResponseId'];
    }

    return $body;
}

function apiChat(array $options): array
{
    $config = getApiConfig();

    if (empty($options['model']) || !is_string($options['model'])) {
        throw new \InvalidArgumentException('chat: model is required and must be a string');
    }
    if (!isset($options['input'])) {
        throw new \InvalidArgumentException('chat: input is required');
    }

    $body        = buildRequestBody($options);
    $jsonBody    = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headerLines = ['Content-Type: application/json', 'Authorization: Bearer ' . AI_API_KEY];
    foreach (EXTRA_API_HEADERS as $key => $value) {
        $headerLines[] = "$key: $value";
    }

    $lastError = null;

    for ($attempt = 0; $attempt < $config['retries']; $attempt++) {
        $ch = curl_init($config['endpoint']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $config['timeoutMs']);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $lastError = new \RuntimeException("Request failed: $curlError");
            if ($attempt < $config['retries'] - 1) {
                $delay = $config['retryDelayMs'] * (2 ** $attempt);
                fwrite(STDERR, "  Retry " . ($attempt + 1) . "/{$config['retries']} after {$delay}ms ({$lastError->getMessage()})\n");
                apiSleep($delay);
            }
            continue;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($responseBody, true);
            if (isset($data['error'])) {
                throw new \RuntimeException($data['error']['message']);
            }
            return $data;
        }

        $lastError = new \RuntimeException("Responses API error ($httpCode): $responseBody");

        if (!isRetryableStatus($httpCode)) {
            throw $lastError;
        }

        if ($attempt < $config['retries'] - 1) {
            $delay = $config['retryDelayMs'] * (2 ** $attempt);
            fwrite(STDERR, "  Retry " . ($attempt + 1) . "/{$config['retries']} after {$delay}ms (status $httpCode)\n");
            apiSleep($delay);
        }
    }

    throw $lastError;
}

function extractText(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
        return $response['output_text'];
    }

    $messages = array_filter($response['output'] ?? [], fn($item) => ($item['type'] ?? '') === 'message');

    foreach ($messages as $msg) {
        foreach ($msg['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                return $part['text'];
            }
        }
    }

    $types = implode(', ', array_map(fn($item) => $item['type'] ?? 'unknown', $response['output'] ?? [])) ?: 'none';
    throw new \RuntimeException("No output_text in response. Found types: $types");
}

function extractJson(array $response, string $label = 'response'): array
{
    $text = extractText($response);

    $decoded = json_decode($text, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        $preview = mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '...' : $text;
        throw new \RuntimeException("Failed to parse JSON for $label: " . json_last_error_msg() . "\nOutput: $preview");
    }

    return $decoded;
}

function extractSources(array $response): array
{
    $calls = array_filter($response['output'] ?? [], fn($item) => ($item['type'] ?? '') === 'web_search_call');

    $callSources = [];
    foreach ($calls as $call) {
        foreach ($call['action']['sources'] ?? [] as $source) {
            if (!empty($source['url'])) {
                $callSources[] = $source;
            }
        }
    }

    $citationSources = [];
    collectCitations($response, $citationSources);

    $sources = array_merge($callSources, $citationSources);
    $seen    = [];
    $unique  = [];

    foreach ($sources as $source) {
        if (!empty($source['url']) && !isset($seen[$source['url']])) {
            $seen[$source['url']] = true;
            $unique[]             = $source;
        }
    }

    return $unique;
}

function collectCitations(mixed $node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {
        if ($key === 'url_citation' && !empty($value['url'])) {
            $out[] = [
                'title' => $value['title'] ?? null,
                'url'   => $value['url'],
            ];
        } else {
            collectCitations($value, $out);
        }
    }
}

// Legacy aliases
function callResponses(array $options): array
{
    return apiChat($options);
}

function parseJsonOutput(array $response, string $label = 'response'): array
{
    return extractJson($response, $label);
}

function getWebSources(array $response): array
{
    return extractSources($response);
}
