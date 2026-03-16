#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Agentic RAG — PHP port
 *
 * Usage: php app.php
 * Commands: exit | clear
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Helpers/Logger.php';
require_once __DIR__ . '/src/Helpers/Stats.php';
require_once __DIR__ . '/src/Helpers/Api.php';
require_once __DIR__ . '/src/Mcp/Client.php';
require_once __DIR__ . '/src/Agent.php';
require_once __DIR__ . '/src/Repl.php';

use AgenticRag\Config;
use AgenticRag\Helpers\Logger;
use AgenticRag\Helpers\Stats;
use AgenticRag\Mcp\Client;
use AgenticRag\Agent;
use AgenticRag\Repl;

const DEMO_FILE = 'demo/example.md';

// ─────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────

Config::init();

Logger::box("Agentic RAG (PHP)\nCommands: 'exit' | 'clear'");

// Confirmation prompt
echo "\n⚠️  UWAGA: Uruchomienie tego agenta może zużyć zauważalną liczbę tokenów.\n";
echo "   Jeśli nie chcesz uruchamiać go teraz, najpierw sprawdź plik demo:\n";
echo "   Demo: " . DEMO_FILE . "\n\n";
echo "Czy chcesz kontynuować? (yes/y): ";

$answer = trim((string)fgets(STDIN));
if (!in_array(strtolower($answer), ['yes', 'y'], true)) {
    echo "Przerwano.\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// MCP + Agent setup
// ─────────────────────────────────────────────────────────────

$mcp = null;

// Register shutdown handler to clean up MCP process and print stats
register_shutdown_function(static function () use (&$mcp): void {
    Stats::log();
    if ($mcp instanceof Client) {
        $mcp->close();
    }
});

// Trap SIGINT (Ctrl-C) for graceful exit
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, static function () use (&$mcp): void {
        echo "\n";
        Stats::log();
        if ($mcp instanceof Client) {
            $mcp->close();
        }
        exit(0);
    });
    pcntl_async_signals(true);
}

try {
    Logger::start("Connecting to MCP server...");
    $mcp = Client::create('files');

    $mcpTools = $mcp->listTools();
    $names    = implode(', ', array_column($mcpTools, 'name'));
    Logger::success("MCP tools: {$names}\n");

    $agent = new Agent($mcp, $mcpTools);
    $repl  = new Repl($agent);
    $repl->run();

} catch (\Throwable $e) {
    Logger::error("Error", $e->getMessage());
    if ($mcp instanceof Client) {
        $mcp->close();
    }
    exit(1);
}
