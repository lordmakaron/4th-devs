<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

$model = resolveModelForProvider('gpt-5.4');

$personSchema = [
    'type'   => 'json_schema',
    'name'   => 'person',
    'strict' => true,
    'schema' => [
        'type'       => 'object',
        'properties' => [
            'name' => [
                'type'        => ['string', 'null'],
                'description' => 'Full name of the person. Use null if not mentioned.',
            ],
            'age' => [
                'type'        => ['number', 'null'],
                'description' => 'Age in years. Use null if not mentioned or unclear.',
            ],
            'occupation' => [
                'type'        => ['string', 'null'],
                'description' => 'Job title or profession. Use null if not mentioned.',
            ],
            'skills' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'List of skills, technologies, or competencies. Empty array if none mentioned.',
            ],
        ],
        'required'             => ['name', 'age', 'occupation', 'skills'],
        'additionalProperties' => false,
    ],
];

function extractPerson(string $text): array
{
    global $model, $personSchema;

    $payload = [
        'model' => $model,
        'input' => "Extract person information from: \"$text\"",
        'text'  => ['format' => $personSchema],
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

    $outputText = extractResponseText($data);

    if ($outputText === '') {
        throw new \RuntimeException('Missing text output in API response');
    }

    return json_decode($outputText, true);
}

function main(): void
{
    $text   = 'John is 30 years old and works as a software engineer. He is skilled in JavaScript, Python, and React.';
    $person = extractPerson($text);

    echo 'Name: '       . ($person['name']       ?? 'unknown') . "\n";
    echo 'Age: '        . ($person['age']         ?? 'unknown') . "\n";
    echo 'Occupation: ' . ($person['occupation']  ?? 'unknown') . "\n";

    $skills = $person['skills'] ?? [];
    echo 'Skills: ' . (count($skills) ? implode(', ', $skills) : 'none') . "\n";
}

try {
    main();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
