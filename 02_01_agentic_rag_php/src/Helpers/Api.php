<?php

declare(strict_types=1);

namespace AgenticRag\Helpers;

use AgenticRag\Config;

class Api
{
    /**
     * Send a request to the Responses API endpoint.
     *
     * @param array $input      Conversation messages
     * @param array $tools      Available tools in OpenAI format
     * @param string|null $model Override model
     * @return array Parsed response body
     * @throws \RuntimeException on API error
     */
    public static function chat(
        array $input,
        array $tools = [],
        ?string $model = null
    ): array {
        $model = $model ?? Config::$model;

        $body = [
            'model' => $model,
            'input' => $input,
        ];

        if (!empty($tools)) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        if (Config::$instructions) {
            $body['instructions'] = Config::$instructions;
        }

        if (Config::$maxOutputTokens) {
            $body['max_output_tokens'] = Config::$maxOutputTokens;
        }

        if (!empty(Config::$reasoning)) {
            $body['reasoning'] = Config::$reasoning;
        }

        $headers = array_merge(
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . Config::$apiKey,
            ],
            array_map(
                static fn($k, $v) => "{$k}: {$v}",
                array_keys(Config::extraHeaders()),
                Config::extraHeaders()
            )
        );

        $ch = curl_init(Config::$endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("cURL error: {$err}");
        }

        $data = json_decode((string)$raw, true);

        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? json_encode($data['error']);
            throw new \RuntimeException("API error: {$msg}");
        }

        Stats::record($data['usage'] ?? null);

        return $data;
    }

    /**
     * Extract function_call items from response output.
     */
    public static function extractToolCalls(array $response): array
    {
        return array_values(array_filter(
            $response['output'] ?? [],
            static fn($item) => ($item['type'] ?? '') === 'function_call'
        ));
    }

    /**
     * Extract the final text from response.
     */
    public static function extractText(array $response): ?string
    {
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'message') {
                return $item['content'][0]['text'] ?? null;
            }
        }
        return null;
    }

    /**
     * Extract reasoning summaries from response.
     */
    public static function extractReasoning(array $response): array
    {
        $texts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'reasoning') {
                foreach ($item['summary'] ?? [] as $s) {
                    if (isset($s['text']) && $s['text'] !== '') {
                        $texts[] = $s['text'];
                    }
                }
            }
        }
        return $texts;
    }
}
