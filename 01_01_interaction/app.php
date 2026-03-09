<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

$model = resolveModelForProvider('gpt-5.2');

function chat(string $input, array $history = []): array
{
    global $model;

    $payload = [
        'model'     => $model,
        'input'     => array_merge($history, [toMessage('user', $input)]),
        'reasoning' => ['effort' => 'medium'],
    ];

    $headerLines = ['Content-Type: application/json', 'Authorization: Bearer ' . AI_API_KEY];
    foreach (EXTRA_API_HEADERS as $key => $value) {
        $headerLines[] = "$key: $value";
    }

    $ch = curl_init(RESPONSES_API_ENDPOINT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($responseBody, true);

    if ($httpCode < 200 || $httpCode >= 300 || isset($data['error'])) {
        $message = $data['error']['message'] ?? "Request failed with status $httpCode";
        throw new \RuntimeException($message);
    }

    $text = extractResponseText($data);

    if ($text === '') {
        throw new \RuntimeException('Missing text output in API response');
    }

    return [
        'text'            => $text,
        'reasoningTokens' => $data['usage']['output_tokens_details']['reasoning_tokens'] ?? 0,
    ];
}

function main(): void
{
    $firstQuestion = 'What is 25 * 48?';
    $firstAnswer   = chat($firstQuestion);

    $secondQuestion = 'Divide that by 4.';
    $secondQuestionContext = [
        ['type' => 'message', 'role' => 'user',      'content' => $firstQuestion],
        ['type' => 'message', 'role' => 'assistant',  'content' => $firstAnswer['text']],
    ];
    $secondAnswer = chat($secondQuestion, $secondQuestionContext);

    echo 'Q: ' . $firstQuestion . "\n";
    echo 'A: ' . $firstAnswer['text'] . ' (' . $firstAnswer['reasoningTokens'] . " reasoning tokens)\n";
    echo 'Q: ' . $secondQuestion . "\n";
    echo 'A: ' . $secondAnswer['text'] . ' (' . $secondAnswer['reasoningTokens'] . " reasoning tokens)\n";
}

try {
    main();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
