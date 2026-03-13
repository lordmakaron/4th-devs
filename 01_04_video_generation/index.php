<?php
/**
 * S01E04 — Video Generation Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous video generation agent that creates short animations by:
 *  1. Generating a START frame (image) from a style template
 *  2. Generating an END frame (image) using the start as reference
 *  3. Animating between frames using Kling video generation (via Replicate)
 *
 * The agent manages the full workflow: reading the style template, generating
 * consistent frames, quality-checking them, and producing the final video.
 *
 *   User query → Agent loop → [Frame generation → QA → Video generation] → Done
 *
 * FIVE MODELS, FIVE ROLES
 * -----------------------
 *  - Orchestrator  (gpt-4.1 via OpenRouter)               — plans, reasons, calls tools
 *  - Image gen     (gemini-3.1-flash-image-preview via OR) — generates/edits frames
 *  - Vision QA     (gpt-4.1 via OpenRouter)                — evaluates frame quality
 *  - Video gen     (kling-v2.5-turbo-pro via Replicate)    — animates frames into video
 *  - Video QA      (gemini-2.5-flash via Gemini native)    — evaluates video quality
 *
 * TOOLS
 * -----
 *  - create_image     Generate or edit images (Gemini via OpenRouter)
 *  - analyze_image    Vision model QA on frames (prompt adherence, artifacts, style)
 *  - generate_video   Text-to-video via Kling/Replicate
 *  - image_to_video   Frame-to-video with start/end frames via Kling/Replicate
 *  - analyze_video    Video quality check via Gemini native API
 *  - list_directory   List files in workspace folders
 *  - read_file        Read text file contents
 *  - write_file       Write/create text files
 *
 * TEMPLATE SYSTEM
 * ---------------
 * workspace/template.json defines a consistent art style (pencil sketch with
 * watercolor) including subject placement, color palette, composition rules,
 * and negative prompts. The agent reads this template, fills in the subject
 * section, and passes the full JSON as the image generation prompt.
 *
 * VIDEO GENERATION FLOW
 * ---------------------
 *  1. Read workspace/template.json
 *  2. Create START frame: fill template subject → create_image
 *  3. (Optional) analyze_image → QA check → retry if needed
 *  4. Create END frame: use start as reference → create_image with edits
 *  5. (Optional) analyze_image → QA check
 *  6. Generate video: image_to_video with start+end frames
 *  7. (Optional) analyze_video → quality check
 *
 * REPLICATE API
 * -------------
 * Kling v2.5-turbo-pro is accessed via the Replicate API:
 *  - POST /predictions → create job
 *  - GET /predictions/{id} → poll until succeeded/failed
 *  - Download output URL → save as MP4
 *
 * Supports text-to-video and image-to-video (with optional end frame).
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
$videoModel  = 'gemini-2.5-flash';

$replicateApiToken = $env['REPLICATE_API_TOKEN'] ?? '';
$geminiApiKey      = $env['GEMINI_API_KEY'] ?? '';

if (!$replicateApiToken)
{
    logMsg('ERROR', 'REPLICATE_API_TOKEN is required for video generation. Add it to .env', 'danger');
    require __DIR__ . '/../../../lib/footer.php';
    exit;
}

define('KLING_MODEL', 'kwaivgi/kling-v2.5-turbo-pro');
define('REPLICATE_API_URL', 'https://api.replicate.com/v1');

$geminiVideoEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$videoModel}:generateContent";

$systemPrompt = <<<'PROMPT'
You are an autonomous video generation agent that creates short animations from text descriptions.

## WORKFLOW

### Step 1: START Frame
1. Read workspace/template.json to understand the art style
2. Copy the template and fill in the "subject" section (main, details) for the scene
3. Call create_image with the FULL JSON template as the prompt
4. Use 16:9 aspect ratio, 2k resolution
5. Name: {scene}_frame_start

### Step 2: END Frame
1. Use the start frame as a reference image for consistency
2. Describe the changes for the end state (different pose, position, action)
3. Call create_image with the start frame path in reference_images
4. Name: {scene}_frame_end

### Step 3: VIDEO
1. Call image_to_video with both frames
2. Describe the motion between start and end states
3. Duration: 10 seconds (or 5 for simple motions)
4. Name: {scene}_video

## STYLE RULES
- Always read template.json first
- Only modify the "subject" section of the template
- Keep all other style, color, composition rules unchanged
- 16:9 horizontal landscape format
- 2k resolution
- Maximum 4 colors from the defined palette

## QUALITY CHECKS
- Use analyze_image to verify frame quality before proceeding
- Use analyze_video to verify final video quality
- Retry if there are major issues (wrong subject, broken layout, artifacts)
- Accept if only minor polish notes remain

## NAMING CONVENTION
- Start frame: {scene}_frame_start_{timestamp}.png
- End frame: {scene}_frame_end_{timestamp}.png
- Video: {scene}_video_{timestamp}.mp4

Run autonomously. Report results with file paths when complete.
PROMPT;

// =============================================================================
// TOOL DEFINITIONS
// =============================================================================

function getTools(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'create_image',
                'description' => 'Generate or edit images. For generation, pass the full JSON template as prompt with empty reference_images. For editing, include reference image paths. Images are saved to workspace/output/.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => 'Image description or full JSON template. Be specific about style, composition, colors.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Base name for the output file (without extension). Saved to workspace/output/',
                        ],
                        'reference_images' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Workspace-relative paths to reference images for editing. Empty array = generate from scratch.',
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                            'description' => 'Aspect ratio for the output image.',
                        ],
                        'image_size' => [
                            'type'        => 'string',
                            'enum'        => ['1k', '2k', '4k'],
                            'description' => 'Image size. Default: 2k',
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
                'description' => 'Analyze a generated image for quality. Checks prompt adherence, visual artifacts, style consistency, and composition suitability for video frames.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'image_path' => [
                            'type'        => 'string',
                            'description' => 'Path to the image file relative to the project root',
                        ],
                        'original_prompt' => [
                            'type'        => 'string',
                            'description' => 'The original prompt or instructions used to generate the image',
                        ],
                        'check_aspects' => [
                            'type'        => 'array',
                            'items'       => [
                                'type' => 'string',
                                'enum' => ['prompt_adherence', 'visual_artifacts', 'style_consistency', 'composition'],
                            ],
                            'description' => 'Specific aspects to check. Default: all aspects.',
                        ],
                    ],
                    'required' => ['image_path', 'original_prompt'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'generate_video',
                'description' => 'Generate video from text prompt using Kling AI. For better results, prefer image_to_video with pre-generated frames.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => 'Scene description for the video',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Base name for output MP4 file (saved to workspace/output/)',
                        ],
                        'duration' => [
                            'type'        => 'number',
                            'enum'        => [5, 10],
                            'description' => 'Duration in seconds: 5 or 10. Default: 10',
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['16:9', '9:16', '1:1'],
                            'description' => 'Aspect ratio. Default: 16:9',
                        ],
                        'negative_prompt' => [
                            'type'        => 'string',
                            'description' => 'Things to avoid in the video',
                        ],
                    ],
                    'required' => ['prompt', 'output_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'image_to_video',
                'description' => 'Generate video from start frame (and optional end frame) using Kling AI. Best approach for controlled video generation with consistent character/style.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => 'Motion/animation description between start and end frames',
                        ],
                        'start_image' => [
                            'type'        => 'string',
                            'description' => 'Path to start frame image (workspace-relative)',
                        ],
                        'end_image' => [
                            'type'        => 'string',
                            'description' => 'Optional path to end frame image for motion control',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Base name for output MP4 file',
                        ],
                        'duration' => [
                            'type'        => 'number',
                            'enum'        => [5, 10],
                            'description' => 'Duration in seconds: 5 or 10. Default: 10',
                        ],
                        'negative_prompt' => [
                            'type'        => 'string',
                            'description' => 'Things to avoid',
                        ],
                    ],
                    'required' => ['prompt', 'start_image', 'output_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'analyze_video',
                'description' => 'Analyze a generated video for quality. Checks motion smoothness, visual consistency, and prompt adherence.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'video_path' => [
                            'type'        => 'string',
                            'description' => 'Path to video file relative to project root',
                        ],
                        'analysis_focus' => [
                            'type'        => 'string',
                            'enum'        => ['general', 'motion', 'quality', 'prompt_adherence'],
                            'description' => "Focus area: 'general', 'motion' (smoothness), 'quality' (artifacts), 'prompt_adherence'. Default: general",
                        ],
                        'original_prompt' => [
                            'type'        => 'string',
                            'description' => 'Original prompt for adherence check',
                        ],
                    ],
                    'required' => ['video_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_directory',
                'description' => 'List files and subdirectories at the given path.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "Directory path relative to script directory (e.g. 'workspace/output')",
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
                            'description' => 'File path relative to the script directory',
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
                'description' => 'Write text content to a file. Creates parent directories automatically.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'File path relative to the script directory',
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'The text content to write',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ],
    ];
}

// =============================================================================
// HELPERS — IMAGE GENERATION (OpenRouter + Gemini Image)
// =============================================================================

/**
 * Get MIME type from file extension.
 */
function getMimeType(string $filepath): string
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    return match ($ext) {
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
    return match ($mimeType) {
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/gif'  => '.gif',
        'image/webp' => '.webp',
        default      => '.png',
    };
}

/**
 * Normalize image size string (e.g. "2k" → "2K").
 */
function normalizeImageSize(string $size): string
{
    return str_ends_with($size, 'k') ? strtoupper($size) : $size;
}

// =============================================================================
// HELPERS — REPLICATE API (Kling Video Generation)
// =============================================================================

/**
 * Create a prediction on Replicate and poll until complete.
 *
 * @param array $input Model input parameters
 * @return string Output URL (video)
 */
function replicateRun(array $input): string
{
    global $replicateApiToken;

    logMsg('REPLICATE', 'Creating prediction...', 'info');

    // Create prediction
    $ch = curl_init(REPLICATE_API_URL . '/models/' . KLING_MODEL . '/predictions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $replicateApiToken,
            'Content-Type: application/json',
            'Prefer: wait',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['input' => $input]),
        CURLOPT_TIMEOUT        => 600,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || ($httpCode !== 200 && $httpCode !== 201))
    {
        throw new RuntimeException("Replicate create failed (HTTP {$httpCode}): " . ($error ?: substr($body, 0, 300)));
    }

    $prediction = json_decode($body, true);
    $predId     = $prediction['id'] ?? null;
    $status     = $prediction['status'] ?? 'unknown';

    if (!$predId)
    {
        throw new RuntimeException('No prediction ID from Replicate');
    }

    logMsg('REPLICATE', "Prediction created: {$predId} (status: {$status})", 'secondary');

    // If already completed (Prefer: wait header worked)
    if ($status === 'succeeded')
    {
        $output = $prediction['output'] ?? null;
        if ($output)
        {
            $videoUrl = is_string($output) ? $output : (is_array($output) ? ($output[0] ?? $output['url'] ?? null) : null);
            if ($videoUrl)
            {
                logMsg('REPLICATE', 'Prediction completed', 'success');
                return $videoUrl;
            }
        }
    }

    if ($status === 'failed')
    {
        throw new RuntimeException('Replicate prediction failed: ' . ($prediction['error'] ?? 'unknown'));
    }

    // Poll for completion
    $pollUrl  = REPLICATE_API_URL . '/predictions/' . $predId;
    $maxPolls = 120; // 10 minutes at 5s intervals

    for ($i = 0; $i < $maxPolls; $i++)
    {
        sleep(5);

        $ch = curl_init($pollUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $replicateApiToken,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        $prediction = json_decode($body, true);
        $status     = $prediction['status'] ?? 'unknown';

        if ($i % 6 === 0)
        {
            logMsg('REPLICATE', "Polling... status: {$status}", 'secondary');
        }

        if ($status === 'succeeded')
        {
            $output   = $prediction['output'] ?? null;
            $videoUrl = is_string($output) ? $output : (is_array($output) ? ($output[0] ?? $output['url'] ?? null) : null);

            if ($videoUrl)
            {
                logMsg('REPLICATE', 'Prediction completed', 'success');
                return $videoUrl;
            }

            throw new RuntimeException('Prediction succeeded but no output URL found');
        }

        if ($status === 'failed' || $status === 'canceled')
        {
            throw new RuntimeException("Replicate prediction {$status}: " . ($prediction['error'] ?? 'unknown'));
        }
    }

    throw new RuntimeException('Replicate prediction timed out after 10 minutes');
}

/**
 * Download a file from URL.
 */
function downloadFile(string $url, string $outputPath): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $data     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200)
    {
        throw new RuntimeException("Download failed (HTTP {$httpCode}): {$error}");
    }

    file_put_contents($outputPath, $data);
}

// =============================================================================
// TOOL HANDLERS
// =============================================================================

function handleTool(string $name, array $args): array
{
    return match ($name) {
        'create_image'   => toolCreateImage($args),
        'analyze_image'  => toolAnalyzeImage($args),
        'generate_video' => toolGenerateVideo($args),
        'image_to_video' => toolImageToVideo($args),
        'analyze_video'  => toolAnalyzeVideo($args),
        'list_directory' => toolListDirectory($args),
        'read_file'      => toolReadFile($args),
        'write_file'     => toolWriteFile($args),
        default          => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Generate or edit an image using Gemini via OpenRouter.
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

    logMsg('IMAGE', ($isEditing ? 'Editing' : 'Generating') . " — " . substr($prompt, 0, 80) . '...', 'info');

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

    // Extract generated image
    $images   = $decoded['choices'][0]['message']['images'] ?? [];
    $imageUrl = $images[0]['image_url']['url'] ?? ($images[0]['imageUrl']['url'] ?? null);

    if (!$imageUrl)
    {
        $textContent = $decoded['choices'][0]['message']['content'] ?? 'No response';

        if (is_array($textContent))
        {
            $texts       = array_map(fn($p) => is_string($p) ? $p : ($p['text'] ?? ''), $textContent);
            $textContent = implode("\n", $texts);
        }

        return ['error' => "No image output. Model response: " . substr($textContent, 0, 300)];
    }

    // Parse the data URL
    if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $imageUrl, $matches))
    {
        return ['error' => 'Expected image output as a base64 data URL'];
    }

    $outputMimeType = $matches[1];
    $outputData     = $matches[2];

    // Save to workspace/output/
    $outputDir = WORKSPACE . '/workspace/output';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    $timestamp = (int)(microtime(true) * 1000);
    $ext       = getExtension($outputMimeType);
    $filename  = "{$outputName}_{$timestamp}{$ext}";

    file_put_contents($outputDir . '/' . $filename, base64_decode($outputData));

    $relativePath = "workspace/output/{$filename}";
    logMsg('IMAGE', "Saved: {$relativePath} ({$outputMimeType})", 'success');

    return [
        'success'          => true,
        'mode'             => $mode,
        'output_path'      => $relativePath,
        'absolute_path'    => realpath($outputDir . '/' . $filename) ?: ($outputDir . '/' . $filename),
        'mime_type'        => $outputMimeType,
        'prompt_used'      => substr($prompt, 0, 200),
        'reference_images' => $referenceImages,
    ];
}

/**
 * Analyze a generated image for quality using vision model.
 */
function toolAnalyzeImage(array $args): array
{
    global $env, $scriptLabel, $visionModel;

    $imagePath      = $args['image_path'];
    $originalPrompt = $args['original_prompt'];
    $checkAspects   = $args['check_aspects'] ?? ['prompt_adherence', 'visual_artifacts', 'style_consistency', 'composition'];

    $fullPath = WORKSPACE . '/' . $imagePath;

    if (!file_exists($fullPath))
    {
        return ['error' => "File not found: {$imagePath}"];
    }

    $mimeType = getMimeType($imagePath);
    $base64   = base64_encode(file_get_contents($fullPath));
    $dataUrl  = "data:{$mimeType};base64,{$base64}";

    logMsg('VISION', "Analyzing: {$imagePath}", 'info');

    $aspectList = implode(', ', $checkAspects);

    $analysisPrompt = <<<PROMPT
Analyze this image that was generated with the following prompt:
"{$originalPrompt}"

Check the following aspects: {$aspectList}

For each aspect, rate it and note any issues.
Focus on suitability as a video frame (16:9, clean composition, no artifacts).

Provide your analysis as a structured report. End with an overall assessment
of whether this image is suitable for video generation or needs to be regenerated.
PROMPT;

    $payload = [
        'model'    => $visionModel,
        'messages' => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $analysisPrompt],
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
        return ['error' => "Vision analysis failed (HTTP {$result['httpCode']}): {$result['error']}"];
    }

    $decoded  = json_decode($result['body'], true);
    $analysis = $decoded['choices'][0]['message']['content'] ?? 'No analysis';

    logMsg('VISION', "Analysis complete (" . strlen($analysis) . " chars)", 'success');

    return [
        'success'         => true,
        'image_path'      => $imagePath,
        'aspects_checked' => $checkAspects,
        'analysis'        => $analysis,
    ];
}

/**
 * Generate video from text prompt using Kling via Replicate.
 */
function toolGenerateVideo(array $args): array
{
    $prompt         = $args['prompt'];
    $outputName     = $args['output_name'];
    $duration       = $args['duration'] ?? 10;
    $aspectRatio    = $args['aspect_ratio'] ?? '16:9';
    $negativePrompt = $args['negative_prompt'] ?? '';

    logMsg('TOOL', "generate_video — {$duration}s — " . substr($prompt, 0, 60) . '...', 'info');

    try
    {
        $input = [
            'prompt'          => $prompt,
            'duration'        => $duration,
            'aspect_ratio'    => $aspectRatio,
        ];

        if ($negativePrompt)
        {
            $input['negative_prompt'] = $negativePrompt;
        }

        $videoUrl = replicateRun($input);

        // Download and save
        $outputDir = WORKSPACE . '/workspace/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        $timestamp = (int)(microtime(true) * 1000);
        $filename  = "{$outputName}_{$timestamp}.mp4";
        $fullPath  = $outputDir . '/' . $filename;

        downloadFile($videoUrl, $fullPath);

        $relativePath = "workspace/output/{$filename}";
        logMsg('VIDEO', "Saved: {$relativePath}", 'success');

        return [
            'success'      => true,
            'output_path'  => $relativePath,
            'video_url'    => $videoUrl,
            'prompt'       => $prompt,
            'duration'     => $duration,
            'aspect_ratio' => $aspectRatio,
        ];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Generate video from start frame (and optional end frame) using Kling.
 */
function toolImageToVideo(array $args): array
{
    $prompt         = $args['prompt'];
    $startImage     = $args['start_image'];
    $endImage       = $args['end_image'] ?? null;
    $outputName     = $args['output_name'];
    $duration       = $args['duration'] ?? 10;
    $negativePrompt = $args['negative_prompt'] ?? '';

    logMsg('TOOL', "image_to_video — {$duration}s from {$startImage}", 'info');

    try
    {
        // Read start image
        $startPath = WORKSPACE . '/' . $startImage;
        if (!file_exists($startPath))
        {
            return ['error' => "Start image not found: {$startImage}"];
        }

        $startMime   = getMimeType($startImage);
        $startBase64 = base64_encode(file_get_contents($startPath));
        $startDataUri = "data:{$startMime};base64,{$startBase64}";

        $input = [
            'prompt'      => $prompt,
            'duration'    => $duration,
            'start_image' => $startDataUri,
        ];

        if ($negativePrompt)
        {
            $input['negative_prompt'] = $negativePrompt;
        }

        // Read end image if provided
        if ($endImage)
        {
            $endPath = WORKSPACE . '/' . $endImage;
            if (!file_exists($endPath))
            {
                return ['error' => "End image not found: {$endImage}"];
            }

            $endMime     = getMimeType($endImage);
            $endBase64   = base64_encode(file_get_contents($endPath));
            $input['end_image'] = "data:{$endMime};base64,{$endBase64}";
        }

        $videoUrl = replicateRun($input);

        // Download and save
        $outputDir = WORKSPACE . '/workspace/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        $timestamp = (int)(microtime(true) * 1000);
        $filename  = "{$outputName}_{$timestamp}.mp4";
        $fullPath  = $outputDir . '/' . $filename;

        downloadFile($videoUrl, $fullPath);

        $relativePath = "workspace/output/{$filename}";
        logMsg('VIDEO', "Saved: {$relativePath}", 'success');

        return [
            'success'     => true,
            'output_path' => $relativePath,
            'video_url'   => $videoUrl,
            'prompt'      => $prompt,
            'start_image' => $startImage,
            'end_image'   => $endImage,
            'duration'    => $duration,
        ];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Analyze a generated video for quality using Gemini native API.
 */
function toolAnalyzeVideo(array $args): array
{
    global $geminiApiKey, $geminiVideoEndpoint;

    $videoPath      = $args['video_path'];
    $analysisFocus  = $args['analysis_focus'] ?? 'general';
    $originalPrompt = $args['original_prompt'] ?? null;

    $fullPath = WORKSPACE . '/' . $videoPath;

    if (!file_exists($fullPath))
    {
        return ['error' => "Video file not found: {$videoPath}"];
    }

    if (!$geminiApiKey)
    {
        return ['error' => 'GEMINI_API_KEY is required for video analysis'];
    }

    logMsg('VISION', "Analyzing video: {$videoPath} ({$analysisFocus})", 'info');

    $prompts = [
        'general'          => "Analyze this video comprehensively. Describe content, quality, motion smoothness, visual consistency, and key moments.",
        'motion'           => "Analyze the motion in this video. Focus on: smoothness, naturalness, consistency between frames, any jitter or artifacts.",
        'quality'          => "Analyze the visual quality of this video. Focus on: resolution, color consistency, artifacts, frame consistency, overall production quality.",
        'prompt_adherence' => "Analyze how well this video matches the original prompt: \"{$originalPrompt}\"\n\nDescribe: how well the content matches, any deviations, and overall adherence.",
    ];

    $prompt      = $prompts[$analysisFocus] ?? $prompts['general'];
    $videoBase64 = base64_encode(file_get_contents($fullPath));

    $body = [
        'contents' => [[
            'parts' => [
                ['inline_data' => ['mime_type' => 'video/mp4', 'data' => $videoBase64]],
                ['text' => $prompt],
            ],
        ]],
    ];

    $ch = curl_init($geminiVideoEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-goog-api-key: ' . $geminiApiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 240,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200)
    {
        return ['error' => "Gemini video analysis failed (HTTP {$httpCode}): " . ($error ?: substr($responseBody, 0, 200))];
    }

    $data     = json_decode($responseBody, true);
    $analysis = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$analysis)
    {
        return ['error' => 'No analysis text from Gemini'];
    }

    logMsg('VISION', "Video analysis complete (" . strlen($analysis) . " chars)", 'success');

    return [
        'success'        => true,
        'video_path'     => $videoPath,
        'analysis_focus' => $analysisFocus,
        'analysis'       => $analysis,
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

    return ['path' => $path, 'entries' => array_values(array_diff(scandir($fullPath), ['.', '..']))];
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
 */
function toolWriteFile(array $args): array
{
    $path     = $args['path'];
    $content  = $args['content'];
    $fullPath = WORKSPACE . '/' . $path;

    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (file_put_contents($fullPath, $content) !== false)
    {
        return ['success' => true, 'path' => $path];
    }

    return ['error' => "Failed to write: {$path}"];
}

// =============================================================================
// AGENT LOOP
// =============================================================================

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

        if (empty($toolCalls))
        {
            return $message['content'] ?? 'No response';
        }

        $messages[] = $message;

        foreach ($toolCalls as $tc)
        {
            $name   = $tc['function']['name'];
            $args   = json_decode($tc['function']['arguments'], true) ?? [];
            $callId = $tc['id'];

            logMsg('TOOL', "{$name} — " . substr(json_encode($args), 0, 150), 'info');

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

logSection('S01E04 — Video Generation Agent');
logMsg('ORCHESTRATOR', $orModel, 'secondary');
logMsg('IMAGE', $imageModel, 'secondary');
logMsg('VISION', $visionModel, 'secondary');
logMsg('VIDEO GEN', 'Kling v2.5-turbo-pro via Replicate', 'secondary');
logMsg('VIDEO QA', $videoModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

$query = "Create a short animation of a fox jumping over a fence in the snow";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Video generation complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../../lib/footer.php';
