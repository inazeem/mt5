<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiService
{
    public function ask(string $prompt): array
    {
        $settings = AppSetting::singleton();
        $provider = $settings->ai_provider === 'perplexity' ? 'perplexity' : 'claude';

        if ($provider === 'claude') {
            return $this->askClaude($settings, $prompt);
        }

        return $this->askPerplexity($settings, $prompt);
    }

    private function askClaude(AppSetting $settings, string $prompt): array
    {
        if (empty($settings->claude_api_key)) {
            throw new RuntimeException('Claude API key is missing in Settings.');
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key' => $settings->claude_api_key,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $settings->claude_model,
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Claude request failed: '.$response->body());
        }

        $data = $response->json();
        $answer = data_get($data, 'content.0.text', 'No answer returned.');

        return [
            'provider' => 'claude',
            'answer' => $answer,
            'raw' => $data,
        ];
    }

    private function askPerplexity(AppSetting $settings, string $prompt): array
    {
        if (empty($settings->perplexity_api_key)) {
            throw new RuntimeException('Perplexity API key is missing in Settings.');
        }

        $response = Http::timeout(30)
            ->withToken($settings->perplexity_api_key)
            ->post('https://api.perplexity.ai/chat/completions', [
                'model' => $settings->perplexity_model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a concise trading research assistant.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Perplexity request failed: '.$response->body());
        }

        $data = $response->json();
        $answer = data_get($data, 'choices.0.message.content', 'No answer returned.');

        return [
            'provider' => 'perplexity',
            'answer' => $answer,
            'raw' => $data,
        ];
    }
}
