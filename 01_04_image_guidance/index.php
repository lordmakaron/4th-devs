<?php
/**
 * S01E04 — Image Guidance Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous, pose-guided image generation agent. Generates cell-shaded 3D
 * style characters in specific poses (walking, running, etc.) by combining
 * JSON style templates with pose reference images. Every generated image must
 * include a pose reference from workspace/reference/ to ensure consistent
 * body positioning.
 *
 *   User query → Agent loop → [Tool calls <-> Results] → Final answer
 *
 * POSE-GUIDED GENERATION
 * ----------------------
 * Unlike plain image generation, every create_image call REQUIRES a pose
 * reference image. The agent infers the pose from the user's description:
 *  - "running knight"          → running-pose.png
 *  - "warrior charging"        → inferred running → running-pose.png
 *  - "wizard" (neutral)        → default walking → walking-pose.png
 *  - "sitting" (no file)       → refuse, ask user to add the pose reference
 *
 * JSON TEMPLATE APPROACH
 * ----------------------
 * workspace/template.json contains fixed style definitions — cell-shaded 3D,
 * rough sketchy outlines, hard-edged shadows, 3:4 vertical portrait format.
 * The agent copies the template to workspace/prompts/, edits ONLY the subject
 * section (main, details), then passes the full JSON + pose reference to Gemini.
 *
 * THREE MODELS, THREE ROLES
 * -------------------------
 *  - Orchestrator (gpt-4.1)                  — plans, reasons, calls tools
 *  - Image generator (gemini-3.1-flash)      — produces images from JSON + pose ref
 *  - Vision analyzer (gpt-4.1)               — evaluates images for quality
 *
 * TOOLS
 * -----
 *  - read_file        Read text/JSON files (templates, style configs)
 *  - write_file       Write/create files (prompt JSON copies with edited subjects)
 *  - list_directory   List files in workspace folders (reference poses, outputs)
 *  - copy_file        Copy template.json to workspace/prompts/ for editing
 *  - create_image     Call Gemini via OpenRouter with pose reference image
 *  - analyze_image    Vision model quality check with ACCEPT/RETRY verdicts
 *
 * AGENT LOOP
 * ----------
 *   while (steps < 50):
 *       response = LLM(messages + tools)
 *       if no tool_calls → return text (done)
 *       for each tool_call: execute → append result to messages
 *       loop back
 *
 * QUALITY SCORING (SELF-CORRECTION)
 * ---------------------------------
 *  - ACCEPT (good): pose matched, style satisfied, minor notes OK → done
 *  - RETRY (bad):   wrong pose, broken composition, style violations
 *                   → refined prompt with blocking issue hints (max 2 retries)
 *
 * TYPICAL EXECUTION FLOW
 * ----------------------
 *  1. Agent lists workspace/reference/ to find available pose files
 *  2. Agent matches user request to a pose (explicit, inferred, or default walking)
 *  3. Agent copies workspace/template.json → workspace/prompts/{subject}_{ts}.json
 *  4. Agent edits ONLY subject.main and subject.details in the copied file
 *  5. Agent reads the complete JSON, calls create_image with JSON + pose reference
 *  6. Agent calls analyze_image → ACCEPT or RETRY with blocking issues
 *  7. If RETRY, refines prompt; if ACCEPT, returns summary with image path
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
You are an image generation agent creating cell-shaded 3D style characters in specific poses.

## STYLE
- Cell-shaded 3D illustration with rough, sketchy outlines
- Hand-drawn feel with bold dark outlines
- Hard-edged shadows (2-3 shade levels, no smooth gradients)
- Western illustration style (not anime)

## POSE REFERENCE (MANDATORY)
Every image generation REQUIRES a pose reference from workspace/reference/.

**Pose Selection:**
1. **Explicit**: User says "running knight" → use running-pose.png
2. **Inferred**: User says "warrior charging into battle" → infer running, use running-pose.png
3. **Default**: If pose is unclear/neutral, use walking-pose.png

**Before generating:**
1. List files in workspace/reference/ to see available poses
2. Match user's request (explicit or inferred) to available pose files
3. If no matching pose exists → STOP and ask user to add the pose reference first

**Example pose matching:**
- "running", "charging", "sprinting" → running-pose.png
- "walking", "strolling", "wandering" → walking-pose.png
- "sitting", "seated" → sitting-pose.png (if exists, else refuse)
- "fighting", "combat stance" → fighting-pose.png (if exists, else refuse)

## WORKFLOW

1. **COPY template**: Copy workspace/template.json → workspace/prompts/{subject_name}_{timestamp}.json
2. **EDIT subject only**: Modify ONLY the "subject" section (main, details) in the copied file
3. **READ prompt file**: Read the complete JSON from the prompt file
4. **GENERATE**: Call create_image with:
   - prompt: the JSON content
   - reference_images: [pose reference file] (default: "workspace/reference/walking-pose.png")
   - aspect_ratio: from template's technical.aspect_ratio (default "3:4")
   - image_size: from template's technical.resolution (default "2k")
5. **ANALYZE**: Run analyze_image on the result
6. **ITERATE**: If RETRY verdict, refine and retry (max 2 times)
7. **REPORT**: Return the generated image path

## EDITING THE SUBJECT

Only modify subject.main and subject.details:
{
  "subject": {
    "main": "medieval knight",
    "details": "silver armor with blue cape, sword at hip, weathered helmet under arm"
  }
}

Keep pose, orientation, position, scale from template - they're designed for the pose reference.

## RULES
- **POSE REQUIRED**: Every create_image call MUST include a pose reference from workspace/reference/
- **NO POSE = NO IMAGE**: If required pose doesn't exist, refuse and ask user to add it
- **INFER POSE**: Analyze user description to determine appropriate pose
- **COPY FIRST**: Never edit template.json directly
- **MINIMAL EDITS**: Only edit subject.main, subject.details, and subject.pose

## FILE NAMING
- Format: {subject_slug}_{timestamp}.json
- Example: medieval_knight_1769959315686.json

## COMMUNICATION
Keep the tone calm and practical. Run autonomously. Report a summary when complete.
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
                'description' => 'Generate or edit images. Reference images should use exact workspace-relative paths such as workspace/reference/walking-pose.png or workspace/reference/running-pose.png.',
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
                            'description' => "Paths to reference image(s). For pose-guided generation, include the pose reference (e.g. workspace/reference/walking-pose.png). Empty array = generate from scratch.",
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                            'description' => 'Optional aspect ratio. If omitted, follow the template or user request.',
                        ],
                        'image_size' => [
                            'type'        => 'string',
                            'enum'        => ['1k', '2k', '4k'],
                            'description' => 'Optional image size. If omitted, follow the template or user request.',
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
                            'description' => "Directory path relative to the script directory (e.g. 'workspace/reference' or 'workspace/prompts')",
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
                'description' => 'Write text content to a file relative to the script directory. Creates parent directories automatically.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "File path relative to the script directory (e.g. 'workspace/prompts/knight_123456.json')",
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
                            'description' => "Destination file path relative to the script directory",
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
        'write_file'     => toolWriteFile($args),
        'copy_file'      => toolCopyFile($args),
        default          => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Generate or edit an image using Gemini via OpenRouter.
 *
 * For pose-guided generation: the prompt is the JSON template content, and
 * reference_images includes the pose reference (e.g. walking-pose.png).
 * Gemini receives both the text prompt and the pose image, using the pose
 * as visual guidance for body positioning.
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

    logMsg('IMAGE', ($isEditing ? 'Generating with pose ref' : 'Generating') . " — " . substr($prompt, 0, 100) . '...', 'info');

    // Build content: text prompt + reference/pose images
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
 * Analyze a generated image for quality using vision model.
 *
 * Returns structured ACCEPT/RETRY verdict. Quality checks include pose
 * adherence as part of prompt_adherence — did the character match the
 * pose reference, or is it in a different stance?
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
- Use ACCEPT when the main subject, pose guidance, and style requirements are satisfied, even if minor polish notes remain.
- Use RETRY only when there are blocking issues such as wrong pose, broken composition, unreadable required text, severe artifacts, or clear style violations.
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

/**
 * Write content to a file relative to WORKSPACE.
 * Creates parent directories automatically.
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

logSection('S01E04 — Image Guidance Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('IMAGE', $imageModel, 'secondary');
logMsg('VISION', $visionModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

// Default query — can be overridden via CLI argument
$query = "Generate a medieval knight in a walking pose using the cell-shaded style template";

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
