<?php
/**
 * S01E04 — Image Editing Agent (PHP)
 *
 * Autonomous agent that generates and edits images using Gemini via OpenRouter,
 * with quality verification through a vision model.
 *
 * Pipeline:
 *  1. Read workspace/style-guide.md for visual style reference
 *  2. Process user query (generate or edit images)
 *  3. Generate/edit images via Gemini (OpenRouter)
 *  4. Analyze results with vision model for quality
 *  5. Retry if blocking issues found (up to 2 retries)
 *  6. Save accepted images to workspace/output/
 */

require __DIR__ . '/../../lib/init.php';

// =============================================================================
// CONFIG
// =============================================================================

const WORKSPACE = __DIR__;
const MAX_STEPS = 50;

$orModel     = 'openai/gpt-4.1';
$visionModel = 'openai/gpt-4.1';
$imageModel  = 'google/gemini-3.1-flash-image-preview';

$systemPrompt = <<<'PROMPT'
You are an image editing assistant.

<style_guide>
Read workspace/style-guide.md before your first image action.
Use it to shape the prompt, composition, and finish quality.
</style_guide>

<workflow>
1. If the task is about editing or restyling an existing source image, first determine the exact filename in workspace/input.
2. If the filename is missing, ambiguous, or there are multiple matches, list the directory to find it.
3. For edit requests, use the exact workspace-relative path: workspace/input/<exact_filename>.
4. Generate or edit the image.
5. Run analyze_image on the result.
6. If the analyze_image verdict is retry, make a focused retry based on the blocking issues and prompt hint.
7. Stop when the verdict is accept, or after two targeted retries.
</workflow>

<quality_bar>
Aim for a result that satisfies the user's request and the main style-guide constraints.
Acceptable output is allowed when only small polish notes remain.
Retry only for blocking problems such as the wrong subject, broken layout, strong artifacts, unreadable required text, or clear style-guide violations.
</quality_bar>

<filename_rule>
Never guess, shorten, or wildcard filenames for edit requests.
Use the exact filename, for example workspace/input/SCR-20260131-ugqp.jpeg.
</filename_rule>

<communication>
Keep the tone calm and practical.
Run autonomously. Report a summary when complete.
</communication>
PROMPT;

// =============================================================================
// TOOL DEFINITIONS
// =============================================================================

/**
 * Return tool schema array for the agent.
 */
function getTools(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'create_image',
                'description' => 'Generate or edit images. For edits, reference_images must use exact workspace-relative filenames such as workspace/input/SCR-20260131-ugqp.jpeg. Never use wildcards or guessed paths. Empty reference_images array = generate from scratch.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => 'Description of image to generate, or instructions for editing reference images. Be specific about style, composition, colors, changes.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => "Base name for the output file (without extension). Will be saved to workspace/output/",
                        ],
                        'reference_images' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => "Optional exact workspace-relative paths to reference image(s) for editing, for example workspace/input/SCR-20260131-ugqp.jpeg. Empty array = generate from scratch.",
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                            'description' => 'Optional aspect ratio for the output image. If omitted, follow the style guide or user request.',
                        ],
                        'image_size' => [
                            'type'        => 'string',
                            'enum'        => ['1k', '2k', '4k'],
                            'description' => 'Optional image size. If omitted, follow the style guide or user request.',
                        ],
                    ],
                    'required' => ['prompt', 'output_name', 'reference_images'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'analyze_image',
                'description' => 'Analyze a generated or edited image and return an ACCEPT or RETRY verdict. RETRY should be used only for blocking issues, while minor polish notes should still allow ACCEPT.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'image_path' => [
                            'type'        => 'string',
                            'description' => 'Path to the image file relative to the project root',
                        ],
                        'original_prompt' => [
                            'type'        => 'string',
                            'description' => 'The original prompt or instructions used to generate/edit the image',
                        ],
                        'check_aspects' => [
                            'type'        => 'array',
                            'items'       => [
                                'type' => 'string',
                                'enum' => ['prompt_adherence', 'visual_artifacts', 'anatomy', 'text_rendering', 'style_consistency', 'composition'],
                            ],
                            'description' => 'Specific aspects to check. If not provided, checks all aspects.',
                        ],
                    ],
                    'required' => ['image_path', 'original_prompt'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_directory',
                'description' => 'List files and subdirectories at the given path relative to the script directory.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "Directory path relative to the script directory (e.g. 'workspace/input' or 'workspace/output')",
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read_file',
                'description' => 'Read the text contents of a file relative to the script directory.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "File path relative to the script directory (e.g. 'workspace/style-guide.md')",
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
    ];
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Get MIME type from file extension.
 */
function getMimeType(string $filepath): string
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    return match ($ext)
    {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'image/jpeg',
    };
}

/**
 * Get file extension from MIME type.
 */
function getExtension(string $mimeType): string
{
    return match ($mimeType)
    {
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/gif'  => '.gif',
        'image/webp' => '.webp',
        default      => '.png',
    };
}

/**
 * Generate a unique filename with timestamp.
 */
function generateFilename(string $prefix, string $mimeType): string
{
    $timestamp = (int)(microtime(true) * 1000);
    $ext       = getExtension($mimeType);

    return "{$prefix}_{$timestamp}{$ext}";
}

/**
 * Normalize image size string (e.g. "2k" → "2K").
 */
function normalizeImageSize(string $size): string
{
    return str_ends_with($size, 'k')
        ? strtoupper($size)
        : $size;
}

/**
 * Extract a tagged value from analysis text (e.g. "VERDICT: ACCEPT").
 */
function extractTaggedValue(string $text, string $tag): string
{
    if (preg_match('/^' . preg_quote($tag, '/') . ':\s*(.+)$/im', $text, $m))
    {
        return trim($m[1]);
    }

    return '';
}

/**
 * Extract bullet items from a section in analysis text.
 */
function extractBulletSection(string $text, string $section): array
{
    $lines      = explode("\n", $text);
    $header     = strtoupper($section) . ':';
    $startIndex = -1;

    foreach ($lines as $i => $line)
    {
        if (strtoupper(trim($line)) === $header)
        {
            $startIndex = $i;
            break;
        }
    }

    if ($startIndex === -1)
    {
        return [];
    }

    $items = [];

    for ($i = $startIndex + 1; $i < count($lines); $i++)
    {
        $trimmed = trim($lines[$i]);

        if ($trimmed === '')
        {
            continue;
        }

        if (preg_match('/^[A-Z_ ]+:$/', $trimmed))
        {
            break;
        }

        if (str_starts_with($trimmed, '- '))
        {
            $items[] = trim(substr($trimmed, 2));
        }
    }

    return $items;
}

/**
 * Parse a structured analysis report into verdict, score, issues, hints.
 */
function parseAnalysisReport(string $analysis): array
{
    $rawVerdict = strtoupper(extractTaggedValue($analysis, 'VERDICT'));
    $scoreText  = extractTaggedValue($analysis, 'SCORE');
    $score      = is_numeric($scoreText) ? (int)$scoreText : null;

    return [
        'verdict'          => ($rawVerdict === 'RETRY') ? 'retry' : 'accept',
        'score'            => $score,
        'blockingIssues'   => extractBulletSection($analysis, 'BLOCKING_ISSUES'),
        'minorIssues'      => extractBulletSection($analysis, 'MINOR_ISSUES'),
        'nextPromptHints'  => extractBulletSection($analysis, 'NEXT_PROMPT_HINT'),
    ];
}

// =============================================================================
// TOOL HANDLERS
// =============================================================================

/**
 * Dispatch a tool call to the appropriate handler.
 */
function handleTool(string $name, array $args): array
{
    return match ($name)
    {
        'create_image'   => toolCreateImage($args),
        'analyze_image'  => toolAnalyzeImage($args),
        'list_directory' => toolListDirectory($args),
        'read_file'      => toolReadFile($args),
        default          => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Generate or edit an image using Gemini via OpenRouter.
 *
 * For generation: sends a text prompt only.
 * For editing: loads reference images, base64-encodes them, and sends
 * them alongside the prompt as image_url content parts.
 */
function toolCreateImage(array $args): array
{
    global $env, $scriptLabel, $imageModel;

    $prompt          = $args['prompt'];
    $outputName      = $args['output_name'];
    $referenceImages = $args['reference_images'] ?? [];
    $aspectRatio     = $args['aspect_ratio'] ?? null;
    $imageSize       = $args['image_size'] ?? null;
    $isEditing       = !empty($referenceImages);
    $mode            = $isEditing ? 'edit' : 'generate';

    logMsg('IMAGE', ($isEditing ? 'Editing' : 'Generating') . " — {$prompt}", 'info');

    // Build content: text prompt + optional reference images
    if ($isEditing)
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($referenceImages as $imagePath)
        {
            $fullPath = WORKSPACE . '/' . $imagePath;

            if (!file_exists($fullPath))
            {
                return ['error' => "Reference image not found: {$imagePath}"];
            }

            $mimeType = getMimeType($imagePath);
            $base64   = base64_encode(file_get_contents($fullPath));
            $dataUrl  = "data:{$mimeType};base64,{$base64}";

            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        }
    }
    else
    {
        $content = $prompt;
    }

    // Build image config
    $imageConfig = [];
    if ($aspectRatio) $imageConfig['aspect_ratio'] = $aspectRatio;
    if ($imageSize)   $imageConfig['image_size']   = normalizeImageSize($imageSize);

    $payload = [
        'model'      => $imageModel,
        'messages'   => [['role' => 'user', 'content' => $content]],
        'modalities' => ['image', 'text'],
    ];

    if (!empty($imageConfig))
    {
        $payload['image_config'] = $imageConfig;
    }

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        return ['error' => "Image {$mode} failed (HTTP {$result['httpCode']}): {$result['error']}"];
    }

    $decoded = json_decode($result['body'], true);

    if (isset($decoded['usage']))
    {
        $u = $decoded['usage'];
        logMsg('GEMINI', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
    }

    // Extract generated image from response
    $images   = $decoded['choices'][0]['message']['images'] ?? [];
    $imageUrl = $images[0]['image_url']['url'] ?? ($images[0]['imageUrl']['url'] ?? null);

    if (!$imageUrl)
    {
        // Check for text-only response (model refused or gave explanation instead)
        $textContent = $decoded['choices'][0]['message']['content'] ?? 'No response';

        if (is_array($textContent))
        {
            $texts = array_map(
                fn($p) => is_string($p) ? $p : ($p['text'] ?? ''),
                $textContent
            );
            $textContent = implode("\n", $texts);
        }

        return ['error' => "No image output received. Model response: " . substr($textContent, 0, 300)];
    }

    // Parse the data URL: data:<mime>;base64,<data>
    if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $imageUrl, $matches))
    {
        return ['error' => 'Expected image output as a base64 data URL'];
    }

    $outputMimeType = $matches[1];
    $outputData     = $matches[2];

    // Save to workspace/output/
    $outputDir = WORKSPACE . '/workspace/output';

    if (!is_dir($outputDir))
    {
        mkdir($outputDir, 0755, true);
    }

    $filename   = generateFilename($outputName, $outputMimeType);
    $outputPath = $outputDir . '/' . $filename;

    file_put_contents($outputPath, base64_decode($outputData));

    $relativePath = "workspace/output/{$filename}";
    logMsg('IMAGE', "Saved: {$relativePath} ({$outputMimeType})", 'success');

    return [
        'success'          => true,
        'mode'             => $mode,
        'output_path'      => $relativePath,
        'mime_type'        => $outputMimeType,
        'prompt_used'      => $prompt,
        'reference_images' => $referenceImages,
    ];
}

/**
 * Analyze a generated/edited image for quality using vision model.
 *
 * Sends the image base64-encoded to the vision model with a detailed
 * analysis prompt, then parses the structured response into a verdict.
 */
function toolAnalyzeImage(array $args): array
{
    global $env, $scriptLabel, $visionModel;

    $imagePath      = $args['image_path'];
    $originalPrompt = $args['original_prompt'];
    $checkAspects   = $args['check_aspects'] ?? [
        'prompt_adherence', 'visual_artifacts', 'anatomy',
        'text_rendering', 'style_consistency', 'composition',
    ];

    $fullPath = WORKSPACE . '/' . $imagePath;

    if (!file_exists($fullPath))
    {
        return ['error' => "File not found: {$imagePath}"];
    }

    $mimeType = getMimeType($imagePath);
    $base64   = base64_encode(file_get_contents($fullPath));
    $dataUrl  = "data:{$mimeType};base64,{$base64}";

    // Build the analysis prompt based on selected aspects
    $aspectChecks = '';
    $n = 1;
    if (in_array('prompt_adherence', $checkAspects))
        $aspectChecks .= "{$n}. PROMPT ADHERENCE: Does the image accurately represent what was requested? What elements match or are missing?\n" and $n++;
    if (in_array('visual_artifacts', $checkAspects))
        $aspectChecks .= "{$n}. VISUAL ARTIFACTS: Are there any glitches, distortions, blur, noise, or unnatural patterns?\n" and $n++;
    if (in_array('anatomy', $checkAspects))
        $aspectChecks .= "{$n}. ANATOMY: If there are people/animals, check for correct proportions, especially hands, fingers, faces, and limbs.\n" and $n++;
    if (in_array('text_rendering', $checkAspects))
        $aspectChecks .= "{$n}. TEXT RENDERING: If text was requested, is it readable and correctly spelled?\n" and $n++;
    if (in_array('style_consistency', $checkAspects))
        $aspectChecks .= "{$n}. STYLE CONSISTENCY: Is the visual style coherent throughout the image?\n" and $n++;
    if (in_array('composition', $checkAspects))
        $aspectChecks .= "{$n}. COMPOSITION: Is the framing and layout balanced and appropriate?\n" and $n++;

    $analysisPrompt = <<<ANALYSIS
Analyze this AI-generated image for quality issues. The original prompt was:
"{$originalPrompt}"

Please evaluate the following aspects:

{$aspectChecks}
Use this exact output format:

VERDICT: ACCEPT or RETRY
SCORE: <1-10>
BLOCKING_ISSUES:
- <only issues that materially break the brief; use "none" if there are none>
MINOR_ISSUES:
- <optional polish notes that do not require another retry; use "none" if there are none>
NEXT_PROMPT_HINT:
- <targeted retry hint only if VERDICT is RETRY; otherwise use "none">

Decision rules:
- Use ACCEPT when the main subject, layout intent, and style-guide essentials are satisfied, even if minor polish notes remain.
- Use RETRY only when there are blocking issues such as wrong subject, broken composition, unreadable required text, severe artifacts, or clear style-guide violations.
- Do NOT use RETRY for small polish improvements alone.
ANALYSIS;

    logMsg('VISION', "Analyzing: {$imagePath}", 'info');

    $payload = [
        'model'    => $visionModel,
        'messages' => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $analysisPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ],
        ],
    ];

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        return ['error' => "Vision call failed (HTTP {$result['httpCode']}): {$result['error']}"];
    }

    $decoded = json_decode($result['body'], true);

    if (isset($decoded['usage']))
    {
        $u = $decoded['usage'];
        logMsg('VISION', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
    }

    $analysis = $decoded['choices'][0]['message']['content'] ?? 'No response';

    logMsg('VISION', substr($analysis, 0, 150) . '...', 'secondary');

    $report = parseAnalysisReport($analysis);

    return [
        'success'           => true,
        'image_path'        => $imagePath,
        'original_prompt'   => $originalPrompt,
        'aspects_checked'   => $checkAspects,
        'verdict'           => $report['verdict'],
        'score'             => $report['score'],
        'blocking_issues'   => $report['blockingIssues'],
        'minor_issues'      => $report['minorIssues'],
        'next_prompt_hints' => $report['nextPromptHints'],
        'analysis'          => $analysis,
    ];
}

/**
 * List directory entries relative to WORKSPACE.
 */
function toolListDirectory(array $args): array
{
    $path     = $args['path'];
    $fullPath = WORKSPACE . '/' . $path;

    if (!is_dir($fullPath))
    {
        return ['error' => "Directory not found: {$path}"];
    }

    $entries = array_values(array_diff(scandir($fullPath), ['.', '..']));

    return ['path' => $path, 'entries' => $entries];
}

/**
 * Read a text file relative to WORKSPACE.
 */
function toolReadFile(array $args): array
{
    $path     = $args['path'];
    $fullPath = WORKSPACE . '/' . $path;

    if (!file_exists($fullPath))
    {
        return ['error' => "File not found: {$path}"];
    }

    return ['path' => $path, 'content' => file_get_contents($fullPath)];
}

// =============================================================================
// AGENT LOOP
// =============================================================================

/**
 * Call the orchestrating LLM with tool support via Chat Completions API.
 */
function callAgentLLM(array $messages, array $tools): array
{
    global $env, $scriptLabel, $orModel;

    $payload = [
        'model'       => $orModel,
        'messages'    => $messages,
        'tools'       => $tools,
        'tool_choice' => 'auto',
    ];

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        throw new RuntimeException("Agent LLM call failed (HTTP {$result['httpCode']}): {$result['error']}");
    }

    return json_decode($result['body'], true);
}

/**
 * Run the agent loop until the model stops calling tools or MAX_STEPS is reached.
 *
 * Each iteration:
 *  1. Call the LLM with the current message history + tool definitions
 *  2. If the response has no tool_calls → the model is done, return its text
 *  3. Otherwise, append the assistant message to history, execute each tool,
 *     and append a 'tool' role message for each result before the next iteration
 */
function runAgent(string $userMessage, array $tools): string
{
    global $systemPrompt;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMessage],
    ];

    logMsg('AGENT', "Starting — query: {$userMessage}", 'primary');

    for ($step = 1; $step <= MAX_STEPS; $step++)
    {
        logSection("Step {$step} (" . count($messages) . " messages)");

        $response = callAgentLLM($messages, $tools);

        if (isset($response['usage']))
        {
            $u = $response['usage'];
            logMsg('TOKENS', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
        }

        $message   = $response['choices'][0]['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        // No tool calls → the model has produced its final answer
        if (empty($toolCalls))
        {
            return $message['content'] ?? 'No response';
        }

        // Append assistant message (with tool_calls) to history before tool results
        $messages[] = $message;

        // Execute each tool call and append results
        foreach ($toolCalls as $tc)
        {
            $name   = $tc['function']['name'];
            $args   = json_decode($tc['function']['arguments'], true) ?? [];
            $callId = $tc['id'];

            logMsg('TOOL', "{$name} — " . json_encode($args), 'info');

            try
            {
                $result = handleTool($name, $args);
                $output = json_encode($result);
                logMsg('RESULT', substr($output, 0, 200), 'secondary');
            }
            catch (Throwable $e)
            {
                $output = json_encode(['error' => $e->getMessage()]);
                logMsg('RESULT', $e->getMessage(), 'danger');
            }

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $callId,
                'content'      => $output,
            ];
        }
    }

    throw new RuntimeException('Max steps (' . MAX_STEPS . ') reached without a final answer.');
}

// =============================================================================
// MAIN
// =============================================================================

logSection('S01E04 — Image Editing Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('IMAGE', $imageModel, 'secondary');
logMsg('VISION', $visionModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

// Default query — can be overridden via CLI argument
$query = $argv[1] ?? "Restyle workspace/input/SCR-20260131-ugqp.jpeg to match workspace/style-guide.md";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Image editing complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../lib/footer.php';
