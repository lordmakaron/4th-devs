<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$apiKey = getenv('OPENAI_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "\033[31mError: OPENAI_API_KEY is not set\033[0m\n");
    exit(1);
}

const MODEL    = 'gpt-4o-mini';
const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
const MAX_STEPS = 5;

// ---------------------------------------------------------------------------
// Tool definitions (JSON Schema)
// ---------------------------------------------------------------------------

$tools = [
    [
        'type'     => 'function',
        'function' => [
            'name'        => 'get_weather',
            'description' => 'Get current weather for a given location',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required'             => ['location'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type'     => 'function',
        'function' => [
            'name'        => 'send_email',
            'description' => 'Send a short email message to a recipient',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'to'      => ['type' => 'string', 'description' => 'Recipient email address'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject'],
                    'body'    => ['type' => 'string', 'description' => 'Plain-text email body'],
                ],
                'required'             => ['to', 'subject', 'body'],
                'additionalProperties' => false,
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Tool handlers
// ---------------------------------------------------------------------------

function handle_get_weather(array $args): array
{
    $city = trim($args['location'] ?? '');
    if ($city === '') {
        throw new InvalidArgumentException('"location" must be a non-empty string');
    }

    $data = [
        'Kraków' => ['temp' => -2, 'conditions' => 'snow'],
        'London' => ['temp' =>  8, 'conditions' => 'rain'],
        'Tokyo'  => ['temp' => 15, 'conditions' => 'cloudy'],
    ];

    return $data[$city] ?? ['temp' => null, 'conditions' => 'unknown'];
}

function handle_send_email(array $args): array
{
    $to      = trim($args['to']      ?? '');
    $subject = trim($args['subject'] ?? '');
    $body    = trim($args['body']    ?? '');

    if ($to === '' || $subject === '' || $body === '') {
        throw new InvalidArgumentException('"to", "subject", and "body" must be non-empty strings');
    }

    return [
        'success' => true,
        'status'  => 'sent',
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
    ];
}

// ---------------------------------------------------------------------------
// API call
// ---------------------------------------------------------------------------

function call_api(array $messages, array $tools): array
{
    global $apiKey;

    $payload = json_encode([
        'model'    => MODEL,
        'messages' => $messages,
        'tools'    => $tools,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ch = curl_init(ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
    ]);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('cURL request failed');
    }

    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if ($status !== 200) {
        $msg = $data['error']['message'] ?? "Request failed ({$status})";
        throw new RuntimeException($msg);
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Colored output helpers
// ---------------------------------------------------------------------------

function colorize(string $text, string ...$codes): string
{
    if (!stream_isatty(STDOUT)) {
        return $text;
    }
    $seq = implode('', array_map(fn($c) => "\033[{$c}m", $codes));
    return "{$seq}{$text}\033[0m";
}

function log_question(string $text): void
{
    $label = colorize('[USER]', '1', '34');       // bold blue
    echo "{$label} {$text}\n\n";
}

function log_tool_call(string $name, array $args): void
{
    $label = colorize('[TOOL]', '1', '35');        // bold magenta
    $n     = colorize($name, '1');
    $json  = colorize(json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), '2');
    echo "{$label} {$n}\n";
    echo colorize('Arguments:', '36') . "\n";
    echo "{$json}\n";
}

function log_tool_result(array $result): void
{
    $json = colorize(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), '2');
    echo colorize('Result:', '33') . "\n";
    echo "{$json}\n\n";
}

function log_answer(string $text): void
{
    $label = colorize('[ASSISTANT]', '1', '32');   // bold green
    echo "{$label} {$text}\n";
}

// ---------------------------------------------------------------------------
// Tool-calling loop
// ---------------------------------------------------------------------------

function execute_tool(string $name, array $args): array
{
    log_tool_call($name, $args);

    $result = match ($name) {
        'get_weather' => handle_get_weather($args),
        'send_email'  => handle_send_email($args),
        default       => throw new RuntimeException("Unknown tool: {$name}"),
    };

    log_tool_result($result);
    return $result;
}

function chat(array $messages, array $tools): string
{
    for ($step = 0; $step < MAX_STEPS; $step++) {
        $response = call_api($messages, $tools);
        $choice   = $response['choices'][0];
        $message  = $choice['message'];

        // Append the assistant turn (may contain tool_calls)
        $messages[] = $message;

        if ($choice['finish_reason'] !== 'tool_calls') {
            return $message['content'] ?? '';
        }

        // Execute each requested tool and append results
        foreach ($message['tool_calls'] as $call) {
            $args   = json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR);
            $result = execute_tool($call['function']['name'], $args);

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }
    }

    throw new RuntimeException('Tool calling did not finish within ' . MAX_STEPS . ' steps.');
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

$query = "What is the weather in Kraków? Then send a short email with the answer to student@example.com.";
log_question($query);

$answer = chat([['role' => 'user', 'content' => $query]], $tools);
log_answer($answer);
