# AI_devs 4 — PHP Translation Guide

## What This Is

Node.js AI course examples being translated to PHP. JS and PHP live side by side
in each example directory. PHP files run in a browser (not CLI), outputting
Bootstrap-styled HTML logs.

## Shared Library — lib/

Every PHP example starts and ends with:

```php
require __DIR__ . '/../../lib/init.php';
// ... example code ...
require __DIR__ . '/../../lib/footer.php';
```

### lib/init.php provides:

- HTML page skeleton (Bootstrap 5 dark theme, streaming output)
- `.env` loading → `$env` global array
- `$scriptLabel` global (auto-derived from script path)
- `APP_REF` constant (`'aidevs4'`)
- `logMsg(string $title, string $msg, string $class)` — Bootstrap badge log row
  - Classes: `'primary'`, `'success'`, `'danger'`, `'warning'`, `'secondary'`, `'info'`
- `logSection(string $title)` — section heading
- `logDivider()` — horizontal rule

### lib/functions.php provides:

- `curlPost(string $url, array $payload, array $headers): array` → `['httpCode', 'body', 'error']`
- `curlGet(string $url, array $headers): array` → same shape
- `callLLM(string $model, array $messages, string|array|null $responseFormat): ?string`
  - Calls OpenRouter Chat Completions, logs token usage, returns content text
  - Supports `'json_object'` or full JSON schema for structured output
- `fetchCsv(string $url): ?array` — fetch + parse remote CSV
- `submitToHub(string $apiKey, string $task, mixed $answer): array` — course task verification

### lib/footer.php:

- Closes HTML tags, includes Bootstrap JS + jQuery

## Translation Principles

### 1. Conceptual Rewrite, Not a Line-by-Line Port

Preserve the architecture, workflow, system prompts, and tool definitions from the JS version.
Rewrite idiomatically for PHP:

- `match` expressions (not switch)
- Heredoc `<<<'PROMPT'` for multi-line strings
- Native file functions (`file_get_contents`, `file_put_contents`, `copy`, `scandir`)
- Procedural style with functions, not classes
- Global variables for config (`global $env, $orModel`)

### 2. Comprehensive Opening Docblock

Every index.php opens with a large `/** */` block explaining:

- **CONCEPT**: What the agent/example does
- **Architecture**: Which models play which roles
- **TOOLS**: Available tools and their purposes
- **AGENT LOOP**: How the loop works (if applicable)
- **TYPICAL EXECUTION FLOW**: Step-by-step walkthrough

### 3. File Structure

**Default**: Everything in one `index.php`. Only split into `src/*.php` if:

- File exceeds ~1000 lines
- There are clearly separable concerns (e.g. dedicated MCP client, complex tool implementations)

### 4. Use callLLM() for Simple Cases

For examples that just need a single LLM call (no tool use, no agent loop),
use the shared `callLLM()` from functions.php. Only build custom `curlPost()`
calls when you need tool_calls handling, streaming, or special parameters.

### 5. API Integration

- All LLM calls go through **OpenRouter** (`openrouter.ai/api/v1/chat/completions`)
- Image generation: `google/gemini-3.1-flash-image-preview` via OpenRouter
- Default orchestrator model: `openai/gpt-4.1`
- Headers on every request: `Authorization`, `HTTP-Referer: APP_REF`, `X-Title: $scriptLabel`

### 6. Tool Definitions (Agent Examples)

Return schemas from `getTools(): array` in OpenAI function-calling format:

```php
['type' => 'function', 'function' => ['name' => '...', 'parameters' => [...]]]
```

### 7. Tool Dispatch Pattern

```php
function handleTool(string $name, array $args): array {
    return match ($name) {
        'tool_a' => toolA($args),
        default  => ['error' => "Unknown tool: {$name}"],
    };
}
```

### 8. Tool Handler Convention

- Naming: `tool{PascalCase}(array $args): array`
- Return: `['success' => true, ...]` or `['error' => '...']`
- Paths: resolve relative to `WORKSPACE` constant (set to `__DIR__`)
- Auto-create dirs: `if (!is_dir($dir)) mkdir($dir, 0755, true)`

### 9. Agent Loop Pattern (Complex Examples)

```php
const MAX_STEPS = 50;

function runAgent(string $userMessage, array $tools): string {
    // system + user messages → loop:
    //   call LLM → check tool_calls → execute → append results → repeat
    //   no tool_calls = done, return content
}
```

### 10. Image Tool Patterns (When Applicable)

- `create_image`: prompt → Gemini via OpenRouter → base64 data URL → save to workspace/output/
- Reference images as `data:{mime};base64,{data}` in `image_url` content parts
- `analyze_image`: vision model evaluates quality
- Filenames with timestamps: `{name}_{timestamp}.{ext}`

### 11. HTML-to-PDF (When Applicable)

Use Chrome headless `--print-to-pdf` (no Puppeteer/npm needed):

- Search common Chrome/Chromium binary locations
- `--no-sandbox --print-background --no-pdf-header-footer`
- Template CSS handles page layout (zero Chrome margins)

### 12. Main Execution Block

```php
logSection('S01E04 — Example Title');
logMsg('MODEL', $orModel, 'secondary');

$tools = getTools();  // if agent example
$query = $argv[1] ?? "default query";

try {
    $finalAnswer = runAgent($query, $tools);
    logSection('Complete');
    logMsg('DONE', $finalAnswer, 'success');
} catch (Throwable $e) {
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../lib/footer.php';
```

## What NOT to Do

- Don't use composer, autoload, or external PHP packages
- Don't use classes/OOP — keep procedural
- Don't modify JS files or workspace assets (shared between JS and PHP)
- Don't create separate PHP config files — embed config in index.php
- Don't add CLI output — everything renders as HTML in browser
- Don't duplicate what's already in lib/functions.php (especially callLLM, curlPost)

## Environment Variables (from .env)

- `OPENROUTER_API_KEY` — required for all LLM and image calls
- `GEMINI_API_KEY` — optional, for native Gemini image generation fallback
- `OPENAI_API_KEY` — optional, not used in PHP versions (OpenRouter preferred)
- `HUB_BASE_URL` — course task submission endpoint
