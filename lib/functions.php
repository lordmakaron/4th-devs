<?php

/**
 * Send a JSON POST request via curl.
 *
 * @param  string  $url
 * @param  array   $payload   Data to JSON-encode as request body
 * @param  array   $headers   Additional HTTP headers
 * @return array{httpCode: int, body: string, error: string}
 */
function curlPost(string $url, array $payload, array $headers = []): array
{
    $defaultHeaders = ['Content-Type: application/json'];
    $allHeaders     = array_merge($defaultHeaders, $headers);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_TIMEOUT        => 240, //todo change it into param for longer requests
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'body'     => $body ?: '',
        'error'    => $error,
    ];
}

/**
 * Send a GET request via curl and return the response.
 *
 * @param  string $url
 * @param  array  $headers  Additional HTTP headers
 * @return array{httpCode: int, body: string, error: string}
 */
function curlGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'aidevs4-php/1.0',
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'body'     => $body ?: '',
        'error'    => $error,
    ];
}


/**
 * Fetch a remote CSV file and return it as an array of associative records.
 * First row is treated as headers.
 *
 * @param  string $url
 * @return array|null  Array of assoc arrays, or null on failure
 */
function fetchCsv(string $url): ?array
{
    $raw = file_get_contents($url);

    if ($raw === false)
    {
        return null;
    }

    // Normalise line endings
    $raw   = str_replace("\r\n", "\n", $raw);
    $raw   = str_replace("\r", "\n", $raw);
    $lines = explode("\n", trim($raw));

    if (empty($lines))
    {
        return null;
    }

    $headers = str_getcsv(array_shift($lines));
    $records = [];

    foreach ($lines as $line)
    {
        $line = trim($line);
        if (empty($line)) continue;

        $row = str_getcsv($line);
        if (count($row) !== count($headers)) continue;

        $records[] = array_combine($headers, $row);
    }

    return $records;
}


/**
 * Call an LLM via OpenRouter and return the text content of the response.
 *
 * @param  string            $model          Full model string e.g. 'google/gemini-2.5-flash'
 * @param  array             $messages       Array of ['role' => ..., 'content' => ...] objects
 * @param  string|array|null $responseFormat 'json_object' for basic JSON, full schema array for strict output, null for plain text
 * @return string|null                       Raw text content, or null on failure
 */
function callLLM(string $model, array $messages, string|array|null $responseFormat = null): ?string
{
    global $env, $scriptLabel;

    $orKey = $env['OPENROUTER_API_KEY'] ?? null;

    if (!$orKey) return null;

    $payload = [
        'model'    => $model,
        'messages' => $messages,
    ];

    if (is_array($responseFormat))
    {
        $payload['response_format'] = [
            'type'        => 'json_schema',
            'json_schema' => [
                'name'   => 'response',
                'strict' => true,
                'schema' => $responseFormat,
            ],
        ];
    }
    elseif ($responseFormat !== null)
    {
        $payload['response_format'] = ['type' => $responseFormat];
    }

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . $orKey,
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        return null;
    }

    $decoded = json_decode($result['body'], true);

    if (isset($decoded['usage']))
    {
        $u = $decoded['usage'];
        logMsg('TOKENS', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
    }

    return $decoded['choices'][0]['message']['content'] ?? null;
}

/**
 * Submit an answer to the AI_devs hub and return the parsed response.
 *
 * @param  string $apiKey   Course API key
 * @param  string $task     Task name e.g. 'people'
 * @param  mixed  $answer   Answer payload (array or string)
 * @return array{httpCode: int, body: string, error: string}
 */
function submitToHub(string $apiKey, string $task, mixed $answer): array
{
    global $env;
    return curlPost(
        $env['HUB_BASE_URL'].'/verify',
        [
            'apikey' => $apiKey,
            'task'   => $task,
            'answer' => $answer,
        ]
    );
}
