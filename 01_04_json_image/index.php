<?php
/**
 * S01E04 — JSON Image Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous image generation agent that uses JSON-based prompt templates
 * for consistent, token-efficient image creation. Instead of writing full
 * prompts each time, the agent copies a structured JSON template, edits only
 * the "subject" section, and passes the complete JSON to the image generator.
 * This locks style, lighting, palette, and composition across generations.
 *
 *   User query → Agent loop → [Tool calls <-> Results] → Final answer
 *
 * JSON TEMPLATE APPROACH
 * ----------------------
 * Templates (workspace/template.json, workspace/character-template.json)
 * contain fixed style definitions — medium, line work, coloring, palette,
 * composition, lighting, constraints, negative prompts. The agent only fills
 * in the "subject" section (main, details, orientation, pose, scale), keeping
 * everything else locked. Each generation gets a versioned copy in
 * workspace/prompts/ for history.
 *
 * THREE MODELS, THREE ROLES
 * -------------------------
 *  - Orchestrator (gpt-4.1)                  — plans, reasons, calls tools
 *  - Image generator (gemini-3.1-flash)      — produces/edits images from JSON prompts
 *  - Vision analyzer (gpt-4.1)               — evaluates generated images for quality
 *
 * The orchestrator never sees images directly — it delegates vision to the
 * analyze_image tool and acts on the structured analysis it gets back.
 *
 * TOOLS
 * -----
 *  - read_file        Read text/JSON files (templates, style guides)
 *  - write_file       Write/create files (prompt JSON copies with edited subjects)
 *  - list_directory   List files in workspace folders
 *  - copy_file        Copy template.json to workspace/prompts/ for editing
 *  - create_image     Call Gemini via OpenRouter to generate or edit images
 *  - analyze_image    Send a result to the vision model for quality scoring
 *
 * AGENT LOOP
 * ----------
 * The Chat Completions API is stateless, so the full conversation history is
 * resent every iteration. The LLM sees its previous tool calls and their results,
 * allowing it to reason about what happened and decide what to do next.
 *
 *   while (steps < 50):
 *       response = LLM(messages + tools)
 *       if no tool_calls → return text (done)
 *       for each tool_call: execute → append result to messages
 *       loop back
 *
 * TYPICAL EXECUTION FLOW
 * ----------------------
 *  1. Agent copies workspace/template.json → workspace/prompts/{subject}_{timestamp}.json
 *  2. Agent edits ONLY the "subject" section in the copied file
 *  3. Agent reads the complete JSON from the prompt file
 *  4. Agent calls create_image with the JSON content + technical settings from template
 *  5. Agent optionally calls analyze_image for quality verification
 *  6. Agent reports the generated image path and prompt file path
 */

require __DIR__ . '/../../../lib/init.php';

// =============================================================================
// CONFIG
// =============================================================================

define('WORKSPACE', __DIR__);
define('MAX_STEPS', 50);

$orModel     = 'openai/gpt-4.1';
$visionModel = 'openai/gpt-4.1';
$imageModel  = 'google/gemini-3.1-flash-image-preview';

$systemPrompt = <<<'PROMPT'
You are an image generation agent using JSON-based prompting with minimal token usage.

## WORKFLOW (Token-Efficient)

1. **COPY template**: Copy workspace/template.json → workspace/prompts/{subject_name}_{timestamp}.json
2. **EDIT subject only**: Use write_file to edit ONLY the "subject" section in the copied file
3. **READ prompt file**: Read the complete JSON from the prompt file
4. **GENERATE**: Pass the JSON content to create_image with format settings from the template
5. **REPORT**: Return the generated image path and prompt file path

## PROCESS STEPS

1. Copy template.json to workspace/prompts/ with descriptive filename
   Example: workspace/prompts/phoenix_1769959315686.json

2. Edit the copied file - ONLY modify the "subject" object:
   {
     "subject": {
       "main": "phoenix",
       "details": "rising from flames, wings fully spread, feathers transforming to fire",
       "orientation": "three-quarter view, facing slightly left",
       "position": "centered horizontally and vertically",
       "scale": "occupies 60% of frame height"
     }
   }
   Keep orientation, position, scale from template unless user specifies otherwise.

3. Read the complete JSON from the prompt file

4. Pass JSON content to create_image. Extract technical settings from the JSON:
   - aspect_ratio: use technical.aspect_ratio from JSON (e.g., "1:1", "16:9")
   - image_size: use technical.resolution from JSON (e.g., "1k", "2k", "4k")

## RULES
- **COPY FIRST**: Always create a new prompt file, never edit template.json directly
- **MINIMAL EDITS**: Only edit the "subject" section, preserve everything else
- **VERSION FILES**: Each generation gets its own prompt file for history
- **READ BEFORE GENERATE**: Always read the complete JSON before passing to create_image
- **USE TEMPLATE SETTINGS**: Always use aspect_ratio and resolution from the template's technical section

## FILE NAMING
- Format: {subject_slug}_{timestamp}.json
- Example: dragon_breathing_fire_1769959315686.json
- Keep names short but descriptive

## COMMUNICATION
Keep the tone calm and practical.
Run autonomously. Report a summary when complete.
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
                'description' => 'Generate or edit images using Gemini. If reference_images is empty, generates from prompt. If reference_images provided, edits/combines them based on the prompt.',
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
                            'description' => "Optional paths to reference image(s) for editing. Empty array = generate from scratch.",
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                            'description' => 'Aspect ratio of the output image. Default is 1:1.',
                        ],
                        'image_size' => [
                            'type'        => 'string',
                            'enum'        => ['1k', '2k', '4k'],
                            'description' => 'Resolution of the output image. Default is 1k.',
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
                'description' => 'Analyze a generated or edited image for quality issues. Checks for prompt adherence, visual artifacts, style consistency, and common AI generation mistakes.',
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
                            'description' => "Directory path relative to the script directory (e.g. 'workspace/input' or 'workspace/prompts')",
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
                            'description' => "File path relative to the script directory (e.g. 'workspace/template.json')",
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'write_file',
                'description' => 'Write text content to a file relative to the script directory. Creates parent directories automatically. Use to create prompt JSON files from templates.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "File path relative to the script directory (e.g. 'workspace/prompts/phoenix_123456.json')",
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'The text content to write to the file',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copy_file',
                'description' => 'Copy a file from source to destination (both relative to the script directory). Creates destination directories automatically.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'source' => [
                            'type'        => 'string',
                            'description' => "Source file path relative to the script directory",
                        ],
                        'destination' => [
                            'type'        => 'string',
                            'description' => "Destination file path relative to the script directory (e.g. 'workspace/prompts/phoenix_123456.json')",
                        ],
                    ],
                    'required' => ['source', 'destination'],
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
        'write_file'     => toolWriteFile($args),
        'copy_file'      => toolCopyFile($args),
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

    logMsg('IMAGE', ($isEditing ? 'Editing' : 'Generating') . " — " . substr($prompt, 0, 100) . '...', 'info');

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
 * analysis prompt. Returns raw analysis text (unlike 01_04_image_editing
 * which parses into structured ACCEPT/RETRY verdicts).
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

Please evaluate the following aspects and provide a detailed assessment:

{$aspectChecks}
Provide:
- An overall quality score (1-10)
- List of specific issues found
- Whether the image is acceptable or needs regeneration
- Suggestions for improving the prompt if regeneration is needed
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

    return [
        'success'         => true,
        'image_path'      => $imagePath,
        'original_prompt' => $originalPrompt,
        'aspects_checked' => $checkAspects,
        'analysis'        => $analysis,
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

/**
 * Write content to a file relative to WORKSPACE.
 * Creates parent directories automatically.
 *
 * Used by the agent to create prompt JSON files from templates
 * (copy template, then overwrite with edited subject section).
 */
function toolWriteFile(array $args): array
{
    $path     = $args['path'];
    $content  = $args['content'];
    $fullPath = WORKSPACE . '/' . $path;

    $dir = dirname($fullPath);

    if (!is_dir($dir))
    {
        mkdir($dir, 0755, true);
    }

    if (file_put_contents($fullPath, $content) !== false)
    {
        return ['success' => true, 'path' => $path];
    }

    return ['error' => "Failed to write: {$path}"];
}

/**
 * Copy a file, creating destination directories as needed.
 *
 * Used by the agent to copy template.json → workspace/prompts/{name}.json
 * before editing the subject section.
 */
function toolCopyFile(array $args): array
{
    $source  = $args['source'];
    $dest    = $args['destination'];
    $srcFull = WORKSPACE . '/' . $source;
    $dstFull = WORKSPACE . '/' . $dest;

    if (!file_exists($srcFull))
    {
        return ['error' => "Source not found: {$source}"];
    }

    $dstDir = dirname($dstFull);

    if (!is_dir($dstDir))
    {
        mkdir($dstDir, 0755, true);
    }

    if (copy($srcFull, $dstFull))
    {
        return ['success' => true, 'source' => $source, 'destination' => $dest];
    }

    return ['error' => "Failed to copy {$source} to {$dest}"];
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

logSection('S01E04 — JSON Image Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('IMAGE', $imageModel, 'secondary');
logMsg('VISION', $visionModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

// Default query — can be overridden via CLI argument
$query = "Generate an image of a samurai warrior in battle stance using the template.json style";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Image generation complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../../lib/footer.php';
