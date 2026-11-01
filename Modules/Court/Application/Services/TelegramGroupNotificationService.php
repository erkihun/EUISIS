<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramGroupNotificationService
{
    public function send(string $message): void
    {
        $settings = SystemSetting::current();

        if (! $this->configured($settings)) {
            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->retry(1, 250)
                ->post($this->endpoint((string) $settings->telegram_bot_token), [
                    'chat_id' => (string) $settings->telegram_default_chat_id,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->failed()) {
                Log::warning('Telegram group notification failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram group notification could not be sent.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** Sends one test message and says whether Telegram accepted it (the settings page's Test button). */
    public function sendTest(string $message): bool
    {
        $settings = SystemSetting::current();
        if (! filled($settings->telegram_bot_token) || ! filled($settings->telegram_default_chat_id)) {
            return false;
        }

        try {
            return Http::asForm()->timeout(5)
                ->post($this->endpoint((string) $settings->telegram_bot_token), [
                    'chat_id' => (string) $settings->telegram_default_chat_id,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function configured(SystemSetting $settings): bool
    {
        return (bool) $settings->telegram_enabled
            && filled($settings->telegram_bot_token)
            && filled($settings->telegram_default_chat_id);
    }

    private function endpoint(string $botToken): string
    {
        return sprintf('https://api.telegram.org/bot%s/sendMessage', trim($botToken));
    }
}
