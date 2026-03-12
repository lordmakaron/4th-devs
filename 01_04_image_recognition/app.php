<?php

/**
 * Image Recognition Agent (PHP version)
 *
 * Autonomous agent that classifies images into folders based on character
 * knowledge profiles using the Responses API with vision capabilities.
 */

// ── Configuration ────────────────────────────────────────────────────────────

define('PROJECT_ROOT', __DIR__);
define('REPO_ROOT', dirname(__DIR__));
define('MAX_STEPS', 100);

$ENDPOINTS = [
    'openai'     => 'https://api.openai.com/v1/responses',
    'openrouter' => 'https://openrouter.ai/api/v1/responses',
];

// Load .env from repository root
$envFile = REPO_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

$openaiKey     = trim(getenv('OPENAI_API_KEY') ?: '');
$openrouterKey = trim(getenv('OPENROUTER_API_KEY') ?: '');
$requestedProvider = strtolower(trim(getenv('AI_PROVIDER') ?: ''));

if (!$openaiKey && !$openrouterKey) {
    logError('API key is not set. Set OPENAI_API_KEY or OPENROUTER_API_KEY in ' . $envFile);
    exit(1);
}

if ($requestedProvider && !in_array($requestedProvider, ['openai', 'openrouter'], true)) {
    logError('AI_PROVIDER must be one of: openai, openrouter');
    exit(1);
}

if ($requestedProvider === 'openai' && !$openaiKey) {
    logError('AI_PROVIDER=openai requires OPENAI_API_KEY');
    exit(1);
}
if ($requestedProvider === 'openrouter' && !$openrouterKey) {
    logError('AI_PROVIDER=openrouter requires OPENROUTER_API_KEY');
    exit(1);
}

$provider = $requestedProvider ?: ($openaiKey ? 'openai' : 'openrouter');
$apiKey   = $provider === 'openai' ? $openaiKey : $openrouterKey;
$endpoint = $ENDPOINTS[$provider];

$extraHeaders = [];
if ($provider === 'openrouter') {
    $referer = getenv('OPENROUTER_HTTP_REFERER');
    $appName = getenv('OPENROUTER_APP_NAME');
    if ($referer) $extraHeaders['HTTP-Referer'] = $referer;
    if ($appName) $extraHeaders['X-Title'] = $appName;
}

function resolveModel(string $model): string {
    global $provider;
    if ($provider !== 'openrouter' || str_contains($model, '/')) {
        return $model;
    }
    return str_starts_with($model, 'gpt-') ? "openai/$model" : $model;
}

$MODEL       = resolveModel('gpt-5.2');
$VISION_MODEL = resolveModel('gpt-5.2');
$MAX_TOKENS  = 16384;

$INSTRUCTIONS = <<<'PROMPT'
You are an autonomous classification agent.

## GOAL
Classify items from images/ into categories based on profiles in knowledge/.
Output to images/organized/<category>/ folders.

## PROCESS
Read profiles first, then process items incrementally - complete each before moving to next. You can read the same image multiple times if you need to.

## REASONING

1. EVIDENCE
   Only use what you can clearly observe.
   "Not visible" means unknown, not absent.
   Criteria require visible features: if the feature is hidden, the criterion is unevaluable → no match.

2. MATCHING
   Profiles are minimum requirements, not exhaustive descriptions.
   Match when ALL stated criteria are satisfied—nothing more.
   Extra observed traits not in the profile are irrelevant; ignore them entirely.
   Profiles define sufficiency: a 1-criterion profile needs only that 1 criterion to match.
   If direct match fails, use elimination: rule out profiles until one remains.

3. AMBIGUITY
   Multiple matches → copy to all matching folders.
   No match possible → unclassified.
   Observation unclear (can't see features) → unclassified.
   Clear observation + criteria satisfied → classify; don't add hesitation.

4. COMPOSITES
   Items containing multiple subjects: evaluate each separately.
   Never combine traits from different subjects.

5. RECOVERY
   Mistakes can be corrected by moving files.

Run autonomously. Report summary when complete.
PROMPT;

// ── Token stats ──────────────────────────────────────────────────────────────

$stats = ['input' => 0, 'output' => 0, 'requests' => 0];

function recordUsage(?array $usage): void {
    global $stats;
    if (!$usage) return;
    $stats['input']    += $usage['input_tokens']  ?? 0;
    $stats['output']   += $usage['output_tokens'] ?? 0;
    $stats['requests'] += 1;
}

function printStats(): void {
    global $stats;
    $total = $stats['input'] + $stats['output'];
    logMsg("Stats: {$stats['requests']} requests, {$stats['input']} input tokens, {$stats['output']} output tokens, $total total");
}

// ── Logging ──────────────────────────────────────────────────────────────────

function timestamp(): string {
    return date('H:i:s');
}

function logMsg(string $msg): void {
    echo "[" . timestamp() . "] $msg\n";
}

function logSuccess(string $msg): void {
    echo "[" . timestamp() . "] ✓ $msg\n";
}

function logError(string $msg): void {
    fwrite(STDERR, "[" . timestamp() . "] ✗ $msg\n");
}

function logTool(string $name, array $args): void {
    $argStr = json_encode($args);
    if (strlen($argStr) > 100) $argStr = substr($argStr, 0, 100) . '...';
    echo "[" . timestamp() . "] ⚡ $name $argStr\n";
}

function logToolResult(string $name, bool $success, string $output): void {
    $icon = $success ? '✓' : '✗';
    if (strlen($output) > 150) $output = substr($output, 0, 150) . '...';
    echo "         $icon $output\n";
}

// ── HTTP / API ───────────────────────────────────────────────────────────────

function apiRequest(string $url, array $body, array $headers): array {
    $ch = curl_init($url);
    $jsonBody = json_encode($body);

    $curlHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("HTTP request failed: $error");
    }

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300 || isset($data['error'])) {
        $msg = $data['error']['message'] ?? "Responses API request failed ($httpCode)";
        throw new RuntimeException($msg);
    }

    return $data;
}

function chat(array $input, array $tools): array {
    global $endpoint, $apiKey, $extraHeaders, $MODEL, $MAX_TOKENS, $INSTRUCTIONS;

    $body = [
        'model'            => $MODEL,
        'input'            => $input,
        'instructions'     => $INSTRUCTIONS,
        'max_output_tokens' => $MAX_TOKENS,
    ];
    if ($tools) {
        $body['tools']       = $tools;
        $body['tool_choice'] = 'auto';
    }

    $headers = array_merge(
        ['Authorization' => "Bearer $apiKey"],
        $extraHeaders
    );

    $data = apiRequest($endpoint, $body, $headers);
    recordUsage($data['usage'] ?? null);
    return $data;
}

function vision(string $imageBase64, string $mimeType, string $question): string {
    global $endpoint, $apiKey, $extraHeaders, $VISION_MODEL;

    $body = [
        'model' => $VISION_MODEL,
        'input' => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'input_text',  'text' => $question],
                    ['type' => 'input_image', 'image_url' => "data:$mimeType;base64,$imageBase64"],
                ],
            ],
        ],
    ];

    $headers = array_merge(
        ['Authorization' => "Bearer $apiKey"],
        $extraHeaders
    );

    $data = apiRequest($endpoint, $body, $headers);
    recordUsage($data['usage'] ?? null);
    return extractText($data) ?? 'No response';
}

// ── Response helpers ─────────────────────────────────────────────────────────

function extractToolCalls(array $response): array {
    $output = $response['output'] ?? [];
    return array_values(array_filter($output, fn($item) => ($item['type'] ?? '') === 'function_call'));
}

function extractText(array $response): ?string {
    if (!empty($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']) ?: null;
    }

    $messages = array_filter($response['output'] ?? [], fn($item) => ($item['type'] ?? '') === 'message');
    foreach ($messages as $message) {
        foreach ($message['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                return $part['text'];
            }
        }
    }

    return null;
}

// ── MIME type helper ─────────────────────────────────────────────────────────

function getMimeType(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'image/jpeg',
    };
}

// ── Tool definitions ─────────────────────────────────────────────────────────

function getToolDefinitions(): array {
    return [
        [
            'type'        => 'function',
            'name'        => 'understand_image',
            'description' => "Analyze an image and answer questions about it. Use this to identify people, objects, scenes, or any visual content in images.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'image_path' => [
                        'type'        => 'string',
                        'description' => "Path to the image file relative to the project root (e.g., 'images/photo.jpg')",
                    ],
                    'question' => [
                        'type'        => 'string',
                        'description' => "Question to ask about the image (e.g., 'Who is in this image?', 'Describe the person\\'s appearance')",
                    ],
                ],
                'required'             => ['image_path', 'question'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
        [
            'type'        => 'function',
            'name'        => 'list_directory',
            'description' => 'List files and directories at the given path relative to the project root.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => "Directory path relative to project root (e.g., 'images' or 'knowledge')",
                    ],
                ],
                'required'             => ['path'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
        [
            'type'        => 'function',
            'name'        => 'read_file',
            'description' => 'Read the contents of a text file relative to the project root.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => "File path relative to project root (e.g., 'knowledge/adam.md')",
                    ],
                ],
                'required'             => ['path'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
        [
            'type'        => 'function',
            'name'        => 'copy_file',
            'description' => 'Copy a file from source to destination (both relative to project root). Creates destination directories automatically.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'source' => [
                        'type'        => 'string',
                        'description' => "Source file path relative to project root",
                    ],
                    'destination' => [
                        'type'        => 'string',
                        'description' => "Destination file path relative to project root (e.g., 'images/organized/adam/photo.jpg')",
                    ],
                ],
                'required'             => ['source', 'destination'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
    ];
}

// ── Tool handlers ────────────────────────────────────────────────────────────

function handleTool(string $name, array $args): array {
    return match ($name) {
        'understand_image' => handleUnderstandImage($args),
        'list_directory'   => handleListDirectory($args),
        'read_file'        => handleReadFile($args),
        'copy_file'        => handleCopyFile($args),
        default            => ['error' => "Unknown tool: $name"],
    };
}

function handleUnderstandImage(array $args): array {
    $imagePath = $args['image_path'];
    $question  = $args['question'];
    $fullPath  = PROJECT_ROOT . '/' . $imagePath;

    try {
        if (!file_exists($fullPath)) {
            throw new RuntimeException("File not found: $imagePath");
        }
        $imageBase64 = base64_encode(file_get_contents($fullPath));
        $mimeType    = getMimeType($imagePath);
        $answer      = vision($imageBase64, $mimeType, $question);
        return ['answer' => $answer, 'image_path' => $imagePath];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'image_path' => $imagePath];
    }
}

function handleListDirectory(array $args): array {
    $path    = $args['path'];
    $fullPath = PROJECT_ROOT . '/' . $path;

    if (!is_dir($fullPath)) {
        return ['error' => "Directory not found: $path"];
    }

    $entries = array_values(array_diff(scandir($fullPath), ['.', '..']));
    return ['path' => $path, 'entries' => $entries];
}

function handleReadFile(array $args): array {
    $path    = $args['path'];
    $fullPath = PROJECT_ROOT . '/' . $path;

    if (!file_exists($fullPath)) {
        return ['error' => "File not found: $path"];
    }

    return ['path' => $path, 'content' => file_get_contents($fullPath)];
}

function handleCopyFile(array $args): array {
    $source = $args['source'];
    $dest   = $args['destination'];
    $srcFull  = PROJECT_ROOT . '/' . $source;
    $dstFull  = PROJECT_ROOT . '/' . $dest;

    if (!file_exists($srcFull)) {
        return ['error' => "Source file not found: $source"];
    }

    $dstDir = dirname($dstFull);
    if (!is_dir($dstDir)) {
        mkdir($dstDir, 0755, true);
    }

    if (copy($srcFull, $dstFull)) {
        return ['success' => true, 'source' => $source, 'destination' => $dest];
    }

    return ['error' => "Failed to copy $source to $dest"];
}

// ── Agent loop ───────────────────────────────────────────────────────────────

function runAgent(string $query, array $tools): string {
    $messages = [['role' => 'user', 'content' => $query]];

    echo "\n QUERY  $query\n\n";

    for ($step = 1; $step <= MAX_STEPS; $step++) {
        logMsg("Step $step (" . count($messages) . " messages)");

        $response  = chat($messages, $tools);
        $usage     = $response['usage'] ?? null;
        if ($usage) {
            echo "         tokens: " . ($usage['input_tokens'] ?? 0) . " in / " . ($usage['output_tokens'] ?? 0) . " out\n";
        }

        $toolCalls = extractToolCalls($response);

        if (empty($toolCalls)) {
            return extractText($response) ?? 'No response';
        }

        // Add the model's output (including function_call items) to messages
        foreach ($response['output'] as $item) {
            $messages[] = $item;
        }

        // Execute each tool call and append results
        foreach ($toolCalls as $tc) {
            $args = json_decode($tc['arguments'], true);
            logTool($tc['name'], $args);

            try {
                $result = handleTool($tc['name'], $args);
                $output = json_encode($result);
                logToolResult($tc['name'], true, $output);
            } catch (Throwable $e) {
                $output = json_encode(['error' => $e->getMessage()]);
                logToolResult($tc['name'], false, $e->getMessage());
            }

            $messages[] = [
                'type'    => 'function_call_output',
                'call_id' => $tc['call_id'],
                'output'  => $output,
            ];
        }
    }

    throw new RuntimeException('Max steps (' . MAX_STEPS . ') reached');
}

// ── Main ─────────────────────────────────────────────────────────────────────

echo "\n──────────────────────────────────────\n";
echo "│ Image Recognition Agent (PHP)     │\n";
echo "│ Classify images by character       │\n";
echo "──────────────────────────────────────\n\n";

logMsg("Provider: $provider | Model: $MODEL");

$query = "Classify all images in the images/ folder based on the character knowledge files.\n"
       . "Read the knowledge files first, then analyze each image and copy it to the appropriate character folder(s).";

$tools = getToolDefinitions();
logSuccess('Tools: ' . implode(', ', array_map(fn($t) => $t['name'], $tools)));

try {
    logMsg('Starting image classification...');
    $result = runAgent($query, $tools);
    logSuccess('Classification complete');
    echo "\n$result\n\n";
    printStats();
} catch (Throwable $e) {
    logError($e->getMessage());
    exit(1);
}
