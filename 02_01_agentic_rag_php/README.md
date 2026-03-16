# Agentic RAG — PHP

PHP port of `02_01_agentic_rag`.

## Requirements

- PHP 8.1+ (with `curl` and optionally `readline` and `pcntl` extensions)
- Node.js (for the MCP file server)
- API key: `OPENAI_API_KEY` or `OPENROUTER_API_KEY` in `../.env`

## Usage

```bash
php app.php
```

## Commands

| Command | Description              |
|---------|--------------------------|
| `exit`  | Quit the application     |
| `clear` | Reset conversation state |

## Architecture

```
app.php                  Entry point, bootstrap, confirmation prompt
src/
  Config.php             Reads .env, resolves provider/model/endpoint
  Agent.php              Agentic loop (chat → tools → repeat)
  Repl.php               Interactive REPL
  Mcp/Client.php         MCP client over stdio JSON-RPC 2.0
  Helpers/
    Api.php              Responses API HTTP calls (cURL)
    Logger.php           Coloured terminal output
    Stats.php            Token usage tracking
mcp.json                 MCP server configuration
```

## How it works

1. Spawns the shared MCP file server (`../mcp/files-mcp`) as a subprocess.
2. Performs the MCP JSON-RPC handshake (`initialize` / `notifications/initialized`).
3. Discovers available tools (`list`, `search`, `read`).
4. On each user query, runs the agentic loop:
   - Calls the Responses API with the full conversation history and tools.
   - Extracts tool calls, executes them synchronously via the MCP server.
   - Appends results to history and loops until no more tool calls.
   - Returns the final text response.
5. Maintains conversation history for multi-turn follow-up questions.
