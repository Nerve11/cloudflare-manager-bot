<?php
/**
 * Configuration file for Cloudflare Manager Bot
 */

return [
    'telegram' => [
        'bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN', // Replace with your actual Telegram bot token
        'webhook_url' => 'https://your-webhook-url.com/webhook.php', // Replace with your webhook URL
    ],
    
    'cloudflare' => [
        'api_token' => 'YOUR_CLOUDFLARE_API_TOKEN', // Replace with your Cloudflare API token
        'email' => 'YOUR_CLOUDFLARE_EMAIL', // Your Cloudflare account email
    ],
    
    'database' => [
        'enabled' => false, // Set to true if you want to use a database
        'type' => 'sqlite', // sqlite, mysql, etc.
        'path' => __DIR__ . '/data/bot.sqlite', // SQLite database path
        // MySQL configuration (if used)
        'host' => 'localhost',
        'name' => 'cloudflare_bot',
        'user' => 'root',
        'password' => '',
    ],
    
    'log' => [
        'enabled' => true,
        'path' => __DIR__ . '/logs/bot.log',
        'level' => 'debug', // debug, info, warning, error
    ],
    
    'pagination' => [
        'domains_per_page' => 10, // Number of domains to display per page
    ],
]; 