<?php

declare(strict_types=1);

namespace AgenticRag\Mcp;

use AgenticRag\Helpers\Logger;

/**
 * MCP Client over stdio using JSON-RPC 2.0.
 *
 * Implements the Model Context Protocol (MCP) client that communicates
 * with an MCP server via a spawned subprocess (stdin/stdout JSON-RPC).
 */
class Client
{
    private $process;
    /** @var resource */
    private $stdin;
    /** @var resource */
    private $stdout;
    private int $nextId = 1;

    private function __construct()
    {
    }

    /**
     * Create and connect an MCP client using config from mcp.json.
     *
     * @param string $serverName Server key in mcp.json (default: "files")
     * @return self
     * @throws \RuntimeException
     */
    public static function create(string $serverName = 'files'): self
    {
        $projectRoot = dirname(__DIR__, 2);
        $configPath  = $projectRoot . '/mcp.json';

        if (!file_exists($configPath)) {
            throw new \RuntimeException("mcp.json not found at: {$configPath}");
        }

        $config       = json_decode(file_get_contents($configPath), true);
        $serverConfig = $config['mcpServers'][$serverName] ?? null;

        if (!$serverConfig) {
            throw new \RuntimeException("MCP server \"{$serverName}\" not found in mcp.json");
        }

        $command = $serverConfig['command'];
        $args    = $serverConfig['args'] ?? [];
        $envVars = $serverConfig['env'] ?? [];

        Logger::info("Spawning MCP server: {$serverName}");
        Logger::info("Command: {$command} " . implode(' ', $args));

        // Build full command string
        $cmdParts = array_merge([$command], $args);
        $cmd      = implode(' ', array_map('escapeshellarg', $cmdParts));

        // Merge server env vars with current environment
        $env = array_merge(
            ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => getenv('HOME') ?: ''],
            $envVars
        );

        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[] = "{$k}={$v}";
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin  (we write to server)
            1 => ['pipe', 'w'],  // stdout (we read from server)
            2 => STDERR,         // stderr (inherited)
        ];

        $instance = new self();
        $instance->process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $projectRoot,
            $env
        );

        if (!is_resource($instance->process)) {
            throw new \RuntimeException("Failed to spawn MCP server process");
        }

        $instance->stdin  = $pipes[0];
        $instance->stdout = $pipes[1];

        // Make stdout non-blocking so we can implement a timeout
        stream_set_blocking($instance->stdout, false);

        // MCP handshake: initialize
        $instance->sendRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'php-agentic-rag', 'version' => '1.0.0'],
        ]);

        // Read initialize response
        $initResponse = $instance->readResponse(10);
        if (!$initResponse) {
            throw new \RuntimeException("MCP initialize handshake timed out");
        }

        // Send initialized notification (no response expected)
        $instance->sendNotification('notifications/initialized', new \stdClass());

        Logger::success("Connected to {$serverName} via stdio");

        return $instance;
    }

    /**
     * List all tools available on the MCP server.
     */
    public function listTools(): array
    {
        $this->sendRequest('tools/list', new \stdClass());
        $response = $this->readResponse(15);

        if (!$response || isset($response['error'])) {
            $err = $response['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("MCP tools/list failed: {$err}");
        }

        return $response['result']['tools'] ?? [];
    }

    /**
     * Call a tool on the MCP server.
     *
     * @param string $name Tool name
     * @param array  $arguments Tool arguments
     * @return mixed Parsed result
     */
    public function callTool(string $name, array $arguments): mixed
    {
        $this->sendRequest('tools/call', [
            'name'      => $name,
            'arguments' => $arguments ?: new \stdClass(),
        ]);

        $response = $this->readResponse(60);

        if (!$response || isset($response['error'])) {
            $err = $response['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("MCP tools/call '{$name}' failed: {$err}");
        }

        $result  = $response['result'] ?? [];
        $content = $result['content'] ?? [];

        // Find text content and try to parse as JSON
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text') {
                $text = $item['text'];
                $decoded = json_decode($text, true);
                return ($decoded !== null) ? $decoded : $text;
            }
        }

        return $result;
    }

    /**
     * Convert MCP tool definitions to OpenAI function format.
     */
    public static function toOpenAiTools(array $mcpTools): array
    {
        return array_map(static fn($tool) => [
            'type'        => 'function',
            'name'        => $tool['name'],
            'description' => $tool['description'] ?? '',
            'parameters'  => $tool['inputSchema'] ?? new \stdClass(),
            'strict'      => false,
        ], $mcpTools);
    }

    /**
     * Close the MCP client and terminate the subprocess.
     */
    public function close(): void
    {
        try {
            fclose($this->stdin);
            fclose($this->stdout);
            proc_terminate($this->process);
            proc_close($this->process);
            Logger::info("MCP client closed");
        } catch (\Throwable $e) {
            Logger::warn("Error closing MCP client: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private JSON-RPC helpers
    // ─────────────────────────────────────────────────────────────

    private function sendRequest(string $method, mixed $params): void
    {
        $msg = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $this->nextId++,
            'method'  => $method,
            'params'  => $params,
        ], JSON_UNESCAPED_UNICODE);

        fwrite($this->stdin, $msg . "\n");
    }

    private function sendNotification(string $method, mixed $params): void
    {
        $msg = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
        ], JSON_UNESCAPED_UNICODE);

        fwrite($this->stdin, $msg . "\n");
    }

    /**
     * Read the next JSON-RPC response, waiting up to $timeout seconds.
     */
    private function readResponse(int $timeout = 30): ?array
    {
        $deadline = microtime(true) + $timeout;
        $buffer   = '';

        while (microtime(true) < $deadline) {
            $chunk = fread($this->stdout, 65536);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }

            // Try to find a complete JSON line
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if ($decoded !== null && isset($decoded['id'])) {
                    return $decoded;
                }
                // Ignore notifications / non-response messages
            }

            usleep(5000); // 5 ms
        }

        return null;
    }
}
