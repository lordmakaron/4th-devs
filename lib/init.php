<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', '1');
ini_set('error_reporting', E_ERROR );

ob_implicit_flush(1);
@ob_end_flush();

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');

echo str_repeat(' ', 1024 * 4);
flush();

$scriptName  = basename($_SERVER['SCRIPT_FILENAME'] ?? 'Script');
$scriptParts = array_slice(explode(DIRECTORY_SEPARATOR, $_SERVER['SCRIPT_FILENAME'] ?? 'Script'), -3);
$scriptLabel = implode('/', $scriptParts);

define('APP_REF', 'aidevs4');

?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($scriptName) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
        <style>
            body { background: #1e1e1e; color: #f8f9fa; padding: 20px; }
            #log { font-family: 'Courier New', monospace; font-size: 0.875rem; }
            #log .log-row { padding: 2px 0; }
            #log .log-msg { margin-left: 8px; }
            #log h6 { border-bottom: 1px solid #444; padding-bottom: 4px; margin-top: 16px; }
            #log hr { border-color: #444; }
        </style>
    </head>
<body>
<div class="container-fluid">
    <div class="mb-3">
        <span class="text-secondary"><?= htmlspecialchars($scriptName) ?></span>
    </div>
    <div id="log">
<?php

// --- Load shared functions ---

require_once __DIR__ . '/functions.php';

/**
 * Print a styled log row with an optional badge title and message.
 *
 * @param string $title  Badge label (shown as Bootstrap badge)
 * @param string $msg    Message text shown after the badge
 * @param string $class  Bootstrap background class e.g. 'success', 'danger', 'primary', 'warning', 'secondary'
 */
function logMsg(string $title, string $msg = '', string $class = 'secondary'): void
{
    $badge = $title
            ? "<span class='badge text-bg-{$class}'>" . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>"
            : '';

    $text = $msg
            ? "<span class='log-msg'>" . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>"
            : '';

    echo "<div class='log-row'>{$badge}{$text}</div>\n";
    flush();
}

/**
 * Print a section heading.
 */
function logSection(string $title): void
{
    echo "<h6 class='text-info mt-3'>" . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h6>\n";
    flush();
}

/**
 * Print a horizontal divider.
 */
function logDivider(): void
{
    echo "<hr>\n";
    flush();
}

// --- Load .env ---

$envFile = __DIR__ . '/../.env';

if (!file_exists($envFile))
{
    logMsg('ERROR', ".env file not found at {$envFile}", 'danger');
    require __DIR__ . '/footer.php';
    exit;
}

$env = parse_ini_file($envFile);

if ($env === false)
{
    logMsg('ERROR', 'Failed to parse .env file.', 'danger');
    require __DIR__ . '/footer.php';
    exit;
}

logMsg('INIT', "Environment loaded — running: {$scriptName}", 'secondary');
logDivider();
