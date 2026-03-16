<?php

declare(strict_types=1);

namespace AgenticRag;

use AgenticRag\Helpers\Api;
use AgenticRag\Helpers\Logger;
use AgenticRag\Mcp\Client;

/**
 * Agentic loop.
 *
 * Executes: chat → tool call extraction → tool execution → repeat.
 * Maintains conversation state for follow-up questions.
 */
class Agent
{
    private const MAX_STEPS = 50;

    private Client $mcp;
    private array $tools;

    public function __construct(Client $mcp, array $mcpTools)
    {
        $this->mcp   = $mcp;
        $this->tools = Client::toOpenAiTools($mcpTools);
    }

    /**
     * Run the agent for a single user query.
     *
     * @param string $query             User input
     * @param array  $conversationHistory Prior messages for follow-ups
     * @return array{ response: string, conversationHistory: array }
     * @throws \RuntimeException when max steps reached
     */
    public function run(string $query, array $conversationHistory = []): array
    {
        $messages = array_merge($conversationHistory, [
            ['role' => 'user', 'content' => $query],
        ]);

        Logger::query($query);

        for ($step = 1; $step <= self::MAX_STEPS; $step++) {
            Logger::api("Step {$step}", count($messages));

            $response = Api::chat($messages, $this->tools);

            Logger::apiDone($response['usage'] ?? null);
            Logger::reasoning(Api::extractReasoning($response));

            $toolCalls = Api::extractToolCalls($response);

            // Append assistant output to history
            foreach ($response['output'] ?? [] as $item) {
                $messages[] = $item;
            }

            if (empty($toolCalls)) {
                // Final answer
                $text = Api::extractText($response) ?? 'No response';
                Logger::response($text);

                return [
                    'response'            => $text,
                    'conversationHistory' => $messages,
                ];
            }

            // Execute tool calls
            $results = $this->runTools($toolCalls);
            foreach ($results as $result) {
                $messages[] = $result;
            }
        }

        throw new \RuntimeException("Max steps (" . self::MAX_STEPS . ") reached");
    }

    // ─────────────────────────────────────────────────────────────
    // Tool execution
    // ─────────────────────────────────────────────────────────────

    private function runTools(array $toolCalls): array
    {
        // PHP is synchronous — run sequentially
        return array_map(fn($tc) => $this->runTool($tc), $toolCalls);
    }

    private function runTool(array $toolCall): array
    {
        $name = $toolCall['name'];
        $args = json_decode($toolCall['arguments'] ?? '{}', true) ?? [];

        Logger::tool($name, $args);

        try {
            $result = $this->mcp->callTool($name, $args);
            $output = json_encode($result, JSON_UNESCAPED_UNICODE);
            Logger::toolResult($name, true, $output);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
            $output = json_encode($result, JSON_UNESCAPED_UNICODE);
            Logger::toolResult($name, false, $e->getMessage());
        }

        return [
            'type'    => 'function_call_output',
            'call_id' => $toolCall['call_id'],
            'output'  => $output,
        ];
    }
}
