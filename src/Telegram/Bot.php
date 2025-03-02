<?php

namespace CloudflareBot\Telegram;

use CloudflareBot\Handlers\CommandHandler;
use CloudflareBot\Handlers\CallbackHandler;
use CloudflareBot\Helpers\Logger;
use Exception;

class Bot
{
    private array $config;
    private array $update;
    private Logger $logger;
    private string $botToken;
    private ?string $chatId = null;
    private ?string $messageId = null;
    private ?string $callbackId = null;

    /**
     * Bot constructor
     *
     * @param array $config The application config
     * @param array $update The update from Telegram
     */
    public function __construct(array $config, array $update)
    {
        $this->config = $config;
        $this->update = $update;
        $this->logger = new Logger($config['log']);
        $this->botToken = $config['telegram']['bot_token'];
        
        // Extract chat ID and message ID from the update
        if (isset($update['message'])) {
            $this->chatId = $update['message']['chat']['id'];
            $this->messageId = $update['message']['message_id'];
        } elseif (isset($update['callback_query'])) {
            $this->chatId = $update['callback_query']['message']['chat']['id'];
            $this->messageId = $update['callback_query']['message']['message_id'];
            $this->callbackId = $update['callback_query']['id'];
        }
    }

    /**
     * Process the incoming update
     */
    public function processUpdate(): void
    {
        $this->logger->info('Processing update', ['update_id' => $this->update['update_id']]);

        if (isset($this->update['message'])) {
            $this->processMessage();
        } elseif (isset($this->update['callback_query'])) {
            $this->processCallback();
        } else {
            $this->logger->warning('Unsupported update type');
        }
    }

    /**
     * Process text messages
     */
    private function processMessage(): void
    {
        $message = $this->update['message'];
        
        // Check if it's a command (starts with /)
        if (isset($message['text']) && strpos($message['text'], '/') === 0) {
            $commandHandler = new CommandHandler($this, $message);
            $commandHandler->handleCommand();
        } 
        // Regular text message - assume domain search or listing
        elseif (isset($message['text'])) {
            $commandHandler = new CommandHandler($this, $message);
            $commandHandler->handleDomainSearch($message['text']);
        }
    }

    /**
     * Process callback queries (button clicks)
     */
    private function processCallback(): void
    {
        $callback = $this->update['callback_query'];
        $callbackHandler = new CallbackHandler($this, $callback);
        $callbackHandler->handleCallback();
        
        // Answer callback query to stop the loading indicator
        $this->answerCallbackQuery();
    }
    
    /**
     * Send a text message to the chat
     *
     * @param string $text The message text
     * @param array $keyboard Optional inline keyboard markup
     * @return array The response from Telegram
     */
    public function sendMessage(string $text, array $keyboard = []): array
    {
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        
        if (!empty($keyboard)) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }
        
        return $this->sendRequest('sendMessage', $data);
    }
    
    /**
     * Edit a message
     *
     * @param string $text The new message text
     * @param array $keyboard Optional new inline keyboard markup
     * @return array The response from Telegram
     */
    public function editMessage(string $text, array $keyboard = []): array
    {
        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        
        if (!empty($keyboard)) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }
        
        return $this->sendRequest('editMessageText', $data);
    }
    
    /**
     * Answer a callback query
     *
     * @param string $text Optional text to show as a notification
     * @return array The response from Telegram
     */
    public function answerCallbackQuery(string $text = ''): array
    {
        if (!$this->callbackId) {
            return [];
        }
        
        $data = [
            'callback_query_id' => $this->callbackId,
        ];
        
        if ($text) {
            $data['text'] = $text;
        }
        
        return $this->sendRequest('answerCallbackQuery', $data);
    }
    
    /**
     * Send a request to the Telegram Bot API
     *
     * @param string $method The API method
     * @param array $data The request data
     * @return array The response from Telegram
     * @throws Exception If the request fails
     */
    private function sendRequest(string $method, array $data): array
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";
        
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
            ],
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $this->logger->error('API request failed', [
                'method' => $method,
                'error' => error_get_last()
            ]);
            throw new Exception('Failed to send request to Telegram API');
        }
        
        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            $this->logger->error('API returned error', [
                'method' => $method,
                'error' => $result['description'] ?? 'Unknown error'
            ]);
            throw new Exception('Telegram API returned error: ' . ($result['description'] ?? 'Unknown error'));
        }
        
        return $result;
    }
    
    /**
     * Get the config
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Get the current chat ID
     *
     * @return string|null
     */
    public function getChatId(): ?string
    {
        return $this->chatId;
    }
} 