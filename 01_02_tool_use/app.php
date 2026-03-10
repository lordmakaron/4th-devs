<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$apiKey = getenv('OPENROUTER_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "\033[31mError: OPENROUTER_API_KEY is not set\033[0m\n");
    exit(1);
}

const MODEL         = 'google/gemini-2.5-flash';
const ENDPOINT      = 'https://openrouter.ai/api/v1/chat/completions';
const MAX_ROUNDS    = 10;
const SANDBOX_DIR   = __DIR__ . '/sandbox';
const INSTRUCTIONS  = 'You are a helpful assistant with access to a sandboxed filesystem. '
                    . 'You can list, read, write, and delete files within the sandbox. '
                    . 'Always use the available tools to interact with files. '
                    . 'Be concise in your responses.';

// ---------------------------------------------------------------------------
// Sandbox helpers
// ---------------------------------------------------------------------------

function init_sandbox(): void
{
    if (is_dir(SANDBOX_DIR)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(SANDBOX_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    } else {
        mkdir(SANDBOX_DIR, 0755, true);
    }
}

function resolve_sandbox_path(string $relative): string
{
    $resolved = realpath(SANDBOX_DIR . '/' . $relative);

    // If file doesn't exist yet, resolve the parent and append the basename
    if ($resolved === false) {
        $parent  = realpath(dirname(SANDBOX_DIR . '/' . $relative));
        $resolved = ($parent !== false ? $parent : SANDBOX_DIR) . '/' . basename($relative);
    }

    // Block path traversal
    $sandboxReal = realpath(SANDBOX_DIR);
    if (!str_starts_with($resolved, $sandboxReal . '/') && $resolved !== $sandboxReal) {
        throw new RuntimeException("Access denied: path \"{$relative}\" is outside sandbox");
    }

    return $resolved;
}

// ---------------------------------------------------------------------------
// Tool definitions (JSON Schema)
// ---------------------------------------------------------------------------

$tools = [
    ['type' => 'function', 'function' => [
        'name'        => 'list_files',
        'description' => 'List files and directories at a given path within the sandbox',
        'parameters'  => ['type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => "Relative path within sandbox. Use '.' for root directory."]],
            'required' => ['path'], 'additionalProperties' => false],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'read_file',
        'description' => 'Read the contents of a file',
        'parameters'  => ['type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file within sandbox']],
            'required' => ['path'], 'additionalProperties' => false],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'write_file',
        'description' => 'Write content to a file (creates or overwrites)',
        'parameters'  => ['type' => 'object',
            'properties' => [
                'path'    => ['type' => 'string', 'description' => 'Relative path to the file within sandbox'],
                'content' => ['type' => 'string', 'description' => 'Content to write to the file'],
            ],
            'required' => ['path', 'content'], 'additionalProperties' => false],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'delete_file',
        'description' => 'Delete a file',
        'parameters'  => ['type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file to delete']],
            'required' => ['path'], 'additionalProperties' => false],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'create_directory',
        'description' => 'Create a directory (and parent directories if needed)',
        'parameters'  => ['type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path for the new directory']],
            'required' => ['path'], 'additionalProperties' => false],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'file_info',
        'description' => 'Get metadata about a file or directory',
        'parameters'  => ['type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file or directory']],
            'required' => ['path'], 'additionalProperties' => false],
    ]],
];

// ---------------------------------------------------------------------------
// Tool handlers
// ---------------------------------------------------------------------------

function handle_list_files(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    if (!is_dir($full)) {
        throw new RuntimeException("Not a directory: {$args['path']}");
    }
    $entries = [];
    foreach (scandir($full) as $name) {
        if ($name === '.' || $name === '..') continue;
        $entries[] = ['name' => $name, 'type' => is_dir($full . '/' . $name) ? 'directory' : 'file'];
    }
    return $entries;
}

function handle_read_file(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    if (!is_file($full)) {
        throw new RuntimeException("File not found: {$args['path']}");
    }
    return ['content' => file_get_contents($full)];
}

function handle_write_file(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    $dir  = dirname($full);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($full, $args['content']);
    return ['success' => true, 'message' => "File written: {$args['path']}"];
}

function handle_delete_file(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    if (!is_file($full)) {
        throw new RuntimeException("File not found: {$args['path']}");
    }
    unlink($full);
    return ['success' => true, 'message' => "File deleted: {$args['path']}"];
}

function handle_create_directory(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    mkdir($full, 0755, true);
    return ['success' => true, 'message' => "Directory created: {$args['path']}"];
}

function handle_file_info(array $args): array
{
    $full = resolve_sandbox_path($args['path']);
    if (!file_exists($full)) {
        throw new RuntimeException("Path not found: {$args['path']}");
    }
    $s = stat($full);
    return [
        'size'        => $s['size'],
        'isDirectory' => is_dir($full),
        'created'     => date('c', $s['ctime']),
        'modified'    => date('c', $s['mtime']),
    ];
}

function execute_tool(string $name, array $args): array
{
    return match ($name) {
        'list_files'       => handle_list_files($args),
        'read_file'        => handle_read_file($args),
        'write_file'       => handle_write_file($args),
        'delete_file'      => handle_delete_file($args),
        'create_directory' => handle_create_directory($args),
        'file_info'        => handle_file_info($args),
        default            => throw new RuntimeException("Unknown tool: {$name}"),
    };
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

    $raw    = curl_exec($ch);
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
// Query processor
// ---------------------------------------------------------------------------

function process_query(string $query, array $tools): string
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Query: {$query}\n";
    echo str_repeat('=', 60) . "\n";

    $messages = [
        ['role' => 'system', 'content' => INSTRUCTIONS],
        ['role' => 'user',   'content' => $query],
    ];

    for ($round = 0; $round < MAX_ROUNDS; $round++) {
        $response = call_api($messages, $tools);
        $choice   = $response['choices'][0];
        $message  = $choice['message'];

        $messages[] = $message;

        $toolCalls = $message['tool_calls'] ?? [];

        if (empty($toolCalls)) {
            $text = $message['content'] ?? 'No response';
            echo "\nA: {$text}\n";
            return $text;
        }

        echo "\nTool calls: " . count($toolCalls) . "\n";

        foreach ($toolCalls as $call) {
            $name = $call['function']['name'];
            $args = json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR);
            echo "  → {$name}(" . json_encode($args) . ")\n";

            try {
                $result  = execute_tool($name, $args);
                $output  = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                echo "    ✓ Success\n";
            } catch (Throwable $e) {
                $output  = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                echo "    ✗ Error: {$e->getMessage()}\n";
            }

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => $output,
            ];
        }
    }

    echo "\nA: Max tool rounds reached\n";
    return 'Max tool rounds reached';
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

init_sandbox();
echo "Sandbox prepared: empty state\n";

$queries = [
    "What files are in the sandbox?",
    "Create a file called hello.txt with content: 'Hello, World!'",
    "Read the hello.txt file",
    "Get info about hello.txt",
    "Create a directory called 'docs'",
    "Create a file docs/readme.txt with content: 'Documentation folder'",
    "List files in the docs directory",
    "Delete the hello.txt file",
    "Try to read ../config.js",
];

foreach ($queries as $query) {
    process_query($query, $tools);
}
