<?php

declare(strict_types=1);

namespace AgenticRag\Helpers;

class Stats
{
    private static int $input    = 0;
    private static int $output   = 0;
    private static int $reasoning = 0;
    private static int $cached   = 0;
    private static int $requests = 0;

    public static function record(?array $usage): void
    {
        if (!$usage) {
            return;
        }
        self::$input    += (int)($usage['input_tokens'] ?? 0);
        self::$output   += (int)($usage['output_tokens'] ?? 0);
        self::$reasoning += (int)($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
        self::$cached   += (int)($usage['input_tokens_details']['cached_tokens'] ?? 0);
        self::$requests += 1;
    }

    public static function reset(): void
    {
        self::$input    = 0;
        self::$output   = 0;
        self::$reasoning = 0;
        self::$cached   = 0;
        self::$requests = 0;
    }

    public static function log(): void
    {
        $visible = self::$output - self::$reasoning;
        $summary = self::$requests . ' requests, ' . self::$input . ' in';
        if (self::$cached > 0) {
            $summary .= ' (' . self::$cached . ' cached)';
        }
        $summary .= ', ' . self::$output . ' out';
        if (self::$reasoning > 0) {
            $summary .= ' (' . self::$reasoning . ' reasoning + ' . $visible . ' visible)';
        }
        echo "\n📊 Stats: {$summary}\n\n";
    }
}
