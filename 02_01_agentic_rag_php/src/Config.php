<?php

declare(strict_types=1);

namespace AgenticRag;

class Config
{
    public static string $model;
    public static string $apiKey;
    public static string $endpoint;
    public static string $provider;
    public static int $maxOutputTokens = 16384;
    public static array $reasoning = ['effort' => 'medium', 'summary' => 'auto'];
    public static string $instructions = <<<'INST'
You are an agent that answers questions by searching and reading available documents. You have tools to explore file structures, search content, and read specific fragments. Use them to find evidence before answering.

## SEARCH GUIDANCE

- **Scan:** If no specific path is given, start by exploring the resource structure — scan folder hierarchies, file names, and headings of potentially relevant documents.
- **Deepen (multi-phase):** This is an iterative process, not a single step:
  1. Search with initial keywords, synonyms, and related terms (at least 3–5 angles).
  2. Read the most promising fragments from search results.
  3. While reading, collect new terminology, concepts, section names, and proper names you did not know before.
  4. Run follow-up searches using these newly discovered terms to find sections you would have missed.
  5. Repeat steps 2–4 until no significant new terms emerge.
- **Explore:** Look for related aspects arising from the topic — cause/effect, part/whole, problem/solution, limitations/workarounds, requirements/configuration — investigating each as a separate lead.
- **Verify coverage:** Before answering, check whether you have enough knowledge to address key questions (definitions, numbers/limits, edge cases, steps, exceptions, etc.). If gaps remain, go back to the Deepen phase with new search terms.

## EFFICIENCY

- NEVER read entire files upfront. Always search for relevant content first using keywords, synonyms, and related terms.
- Do NOT jump to reading fragments after just one or two searches. Exhaust your keyword variations first — the goal is to discover all relevant sections across documents before loading any content.
- Use search results (file paths + matching lines) to identify which fragments matter, then read only those specific line ranges.
- Reading a full file is a last resort — only justified when search results suggest the entire document is relevant and short enough to warrant it.

## RULES

- Ground your answers in the actual content of files — cite specific documents and fragments
- If the information is not found in available resources, say so explicitly
- When multiple documents are relevant, synthesize information across them
- Report which files you consulted so the user can verify

## CONTEXT

Your knowledge base consists of AI_devs course materials stored as S01*.md files. The content is written in Polish — use Polish keywords when searching. Always respond in English.
INST;

    public static function init(): void
    {
        // Load .env from parent directory
        $envFile = dirname(__DIR__, 1) . '/../.env';
        if (file_exists($envFile)) {
            self::loadEnv($envFile);
        }

        $openaiKey = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
        $openrouterKey = trim((string)(getenv('OPENROUTER_API_KEY') ?: ''));
        $requestedProvider = strtolower(trim((string)(getenv('AI_PROVIDER') ?: '')));

        if (!$openaiKey && !$openrouterKey) {
            fwrite(STDERR, "\033[31mError: API key is not set\033[0m\n");
            fwrite(STDERR, "       Create .env in the repository root\n");
            fwrite(STDERR, "       Add one of:\n");
            fwrite(STDERR, "       OPENAI_API_KEY=sk-...\n");
            fwrite(STDERR, "       OPENROUTER_API_KEY=sk-or-v1-...\n");
            exit(1);
        }

        if ($requestedProvider === 'openai') {
            if (!$openaiKey) {
                fwrite(STDERR, "\033[31mError: AI_PROVIDER=openai requires OPENAI_API_KEY\033[0m\n");
                exit(1);
            }
            self::$provider = 'openai';
        } elseif ($requestedProvider === 'openrouter') {
            if (!$openrouterKey) {
                fwrite(STDERR, "\033[31mError: AI_PROVIDER=openrouter requires OPENROUTER_API_KEY\033[0m\n");
                exit(1);
            }
            self::$provider = 'openrouter';
        } else {
            self::$provider = $openaiKey ? 'openai' : 'openrouter';
        }

        self::$apiKey = self::$provider === 'openai' ? $openaiKey : $openrouterKey;
        self::$endpoint = self::$provider === 'openai'
            ? 'https://api.openai.com/v1/responses'
            : 'https://openrouter.ai/api/v1/responses';

        // Resolve model for provider
        $baseModel = 'gpt-4o';
        self::$model = self::resolveModel($baseModel);
    }

    public static function resolveModel(string $model): string
    {
        if (self::$provider !== 'openrouter' || str_contains($model, '/')) {
            return $model;
        }
        return "openai/{$model}";
    }

    public static function extraHeaders(): array
    {
        if (self::$provider !== 'openrouter') {
            return [];
        }
        $headers = [];
        $referer = getenv('OPENROUTER_HTTP_REFERER');
        $appName = getenv('OPENROUTER_APP_NAME');
        if ($referer) {
            $headers['HTTP-Referer'] = $referer;
        }
        if ($appName) {
            $headers['X-Title'] = $appName;
        }
        return $headers;
    }

    private static function loadEnv(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!getenv($key)) {
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
