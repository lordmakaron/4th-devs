<?php

declare(strict_types=1);

namespace AgenticRag;

use AgenticRag\Helpers\Logger;
use AgenticRag\Helpers\Stats;

/**
 * Interactive REPL loop.
 */
class Repl
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Run the interactive REPL until the user types "exit".
     */
    public function run(): void
    {
        $history = [];

        while (true) {
            $input = $this->prompt("You: ");

            if ($input === false || strtolower($input) === 'exit') {
                break;
            }

            if (strtolower($input) === 'clear') {
                $history = [];
                Stats::reset();
                Logger::success("Conversation cleared\n");
                continue;
            }

            if (trim($input) === '') {
                continue;
            }

            try {
                $result  = $this->agent->run($input, $history);
                $history = $result['conversationHistory'];
                echo "\nAssistant: " . $result['response'] . "\n\n";
            } catch (\Throwable $e) {
                Logger::error("Error", $e->getMessage());
                echo "\n";
            }
        }
    }

    private function prompt(string $label): string|false
    {
        if (function_exists('readline')) {
            $line = readline($label);
            if ($line !== false && $line !== '') {
                readline_add_history($line);
            }
            return $line;
        }

        // Fallback when readline extension is not available
        echo $label;
        $line = fgets(STDIN);
        if ($line === false) {
            return false;
        }
        return rtrim($line, "\n\r");
    }
}
