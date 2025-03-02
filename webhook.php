<?php
/**
 * Telegram Bot Webhook Handler for Cloudflare Manager
 */

// Autoload dependencies
require_once __DIR__ . '/vendor/autoload.php';

// Load config
$config = require_once __DIR__ . '/config.php';

use CloudflareBot\Telegram\Bot;
use CloudflareBot\Helpers\Logger;

// Initialize logger
$logger = new Logger($config['log']);
$logger->info('Webhook request received');

// Get the input data from the webhook
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    $logger->error('Invalid update received: ' . $input);
    http_response_code(400);
    exit;
}

try {
    // Initialize the bot with the update
    $bot = new Bot($config, $update);
    
    // Process the update
    $bot->processUpdate();
    
    // Send 200 OK response
    http_response_code(200);
    exit;
} catch (Exception $e) {
    $logger->error('Error processing update: ' . $e->getMessage());
    http_response_code(500);
    exit;
} 