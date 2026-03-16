<?php

declare(strict_types=1);

namespace AgenticRag\Helpers;

class Logger
{
    // ANSI color codes
    private const RESET   = "\x1b[0m";
    private const BRIGHT  = "\x1b[1m";
    private const DIM     = "\x1b[2m";
    private const RED     = "\x1b[31m";
    private const GREEN   = "\x1b[32m";
    private const YELLOW  = "\x1b[33m";
    private const BLUE    = "\x1b[34m";
    private const MAGENTA = "\x1b[35m";
    private const CYAN    = "\x1b[36m";
    private const WHITE   = "\x1b[37m";
    private const BG_BLUE = "\x1b[44m";

    private static function ts(): string
    {
        return date('H:i:s');
    }

    public static function info(string $msg): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . " {$msg}\n";
    }

    public static function success(string $msg): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::GREEN . '✓' . self::RESET . " {$msg}\n";
    }

    public static function error(string $title, string $msg = ''): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::RED . "✗ {$title}" . self::RESET . " {$msg}\n";
    }

    public static function warn(string $msg): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::YELLOW . '⚠' . self::RESET . " {$msg}\n";
    }

    public static function start(string $msg): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::CYAN . '→' . self::RESET . " {$msg}\n";
    }

    public static function box(string $text): void
    {
        $lines = explode("\n", $text);
        $width = max(array_map('mb_strlen', $lines)) + 4;
        $line = str_repeat('─', $width);

        echo "\n" . self::CYAN . $line . self::RESET . "\n";
        foreach ($lines as $l) {
            $padded = str_pad($l, $width - 3);
            echo self::CYAN . '│' . self::RESET . ' ' . self::BRIGHT . $padded . self::RESET . self::CYAN . '│' . self::RESET . "\n";
        }
        echo self::CYAN . $line . self::RESET . "\n\n";
    }

    public static function query(string $q): void
    {
        echo "\n" . self::BG_BLUE . self::WHITE . ' QUERY ' . self::RESET . " {$q}\n\n";
    }

    public static function response(string $r): void
    {
        $truncated = mb_strlen($r) > 500 ? mb_substr($r, 0, 500) . '...' : $r;
        echo "\n" . self::GREEN . 'Response:' . self::RESET . " {$truncated}\n\n";
    }

    public static function api(string $step, int $msgCount): void
    {
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::MAGENTA . '◆' . self::RESET . " {$step} ({$msgCount} messages)\n";
    }

    public static function apiDone(?array $usage): void
    {
        if (!$usage) {
            return;
        }
        $cached    = $usage['input_tokens_details']['cached_tokens'] ?? 0;
        $reasoning = $usage['output_tokens_details']['reasoning_tokens'] ?? 0;
        $visible   = ($usage['output_tokens'] ?? 0) - $reasoning;

        $parts = [($usage['input_tokens'] ?? 0) . ' in'];
        if ($cached > 0) {
            $parts[] = "{$cached} cached";
        }
        $parts[] = ($usage['output_tokens'] ?? 0) . ' out';
        if ($reasoning > 0) {
            $parts[] = self::CYAN . "{$reasoning} reasoning" . self::DIM . " + {$visible} visible" . self::RESET;
        }

        echo self::DIM . '         tokens: ' . implode(' / ', $parts) . self::RESET . "\n";
    }

    public static function reasoning(array $summaries): void
    {
        if (empty($summaries)) {
            return;
        }
        echo self::DIM . '         ' . self::CYAN . 'reasoning:' . self::RESET . "\n";
        foreach ($summaries as $summary) {
            foreach (explode("\n", $summary) as $line) {
                echo self::DIM . '           ' . $line . self::RESET . "\n";
            }
        }
    }

    public static function tool(string $name, mixed $args): void
    {
        $argStr    = json_encode($args, JSON_UNESCAPED_UNICODE);
        $truncated = mb_strlen($argStr) > 300 ? mb_substr($argStr, 0, 300) . '...' : $argStr;
        echo self::DIM . '[' . self::ts() . ']' . self::RESET . ' ' . self::YELLOW . '⚡' . self::RESET . " {$name} " . self::DIM . $truncated . self::RESET . "\n";
    }

    public static function toolResult(string $name, bool $success, string $output): void
    {
        $icon      = $success
            ? self::GREEN . '✓' . self::RESET
            : self::RED . '✗' . self::RESET;
        $truncated = mb_strlen($output) > 500 ? mb_substr($output, 0, 500) . '...' : $output;
        echo self::DIM . '         ' . $icon . ' ' . $truncated . self::RESET . "\n";
    }
}
