<?php

namespace CloudflareBot\Handlers;

use CloudflareBot\Cloudflare\API;
use CloudflareBot\Telegram\Bot;
use CloudflareBot\Helpers\Logger;
use Exception;

class CommandHandler
{
    private Bot $bot;
    private array $message;
    private Logger $logger;
    private API $cloudflareApi;
    
    /**
     * CommandHandler constructor
     *
     * @param Bot $bot The bot instance
     * @param array $message The message data
     */
    public function __construct(Bot $bot, array $message)
    {
        $this->bot = $bot;
        $this->message = $message;
        $this->logger = new Logger($bot->getConfig()['log']);
        $this->cloudflareApi = new API($bot->getConfig()['cloudflare']);
    }
    
    /**
     * Handle a command (message starting with /)
     */
    public function handleCommand(): void
    {
        $text = $this->message['text'];
        $command = strtolower(explode(' ', explode('@', $text)[0])[0]);
        
        $this->logger->info('Handling command', ['command' => $command]);
        
        switch ($command) {
            case '/start':
            case '/help':
                $this->sendHelpMessage();
                break;
            case '/domains':
                $this->listDomains();
                break;
            case '/add':
                $this->handleAddDomain($text);
                break;
            case '/search':
                $searchTerm = trim(substr($text, strlen('/search')));
                if ($searchTerm) {
                    $this->handleDomainSearch($searchTerm);
                } else {
                    $this->bot->sendMessage('Please provide a search term: /search <domain>');
                }
                break;
            default:
                $this->bot->sendMessage('Unknown command. Type /help to see available commands.');
                break;
        }
    }
    
    /**
     * Handle domain search
     *
     * @param string $query The search query
     */
    public function handleDomainSearch(string $query): void
    {
        $this->logger->info('Searching for domain', ['query' => $query]);
        
        // Check if it's an exact domain (contains a dot)
        if (strpos($query, '.') !== false) {
            $this->openDomainSettings($query);
            return;
        }
        
        // Otherwise, search by domain name without TLD
        try {
            $domains = $this->cloudflareApi->searchDomains($query);
            
            if (empty($domains)) {
                $this->bot->sendMessage("No domains found matching: <b>{$query}</b>");
                return;
            }
            
            // Show the domains with pagination
            $this->showDomainsList($domains, 0, "Search results for: <b>{$query}</b>");
        } catch (Exception $e) {
            $this->logger->error('Error searching domains', ['error' => $e->getMessage()]);
            $this->bot->sendMessage("Error searching domains: {$e->getMessage()}");
        }
    }
    
    /**
     * Send help message
     */
    private function sendHelpMessage(): void
    {
        $message = "🌐 <b>Cloudflare Manager Bot</b>\n\n";
        $message .= "This bot helps you manage your Cloudflare domains.\n\n";
        $message .= "<b>Available commands:</b>\n";
        $message .= "/domains - List all your domains\n";
        $message .= "/add <domain> - Add a new domain\n";
        $message .= "/search <term> - Search for domains\n";
        $message .= "/help - Show this help message\n\n";
        $message .= "You can also send a domain name to search for it, or send a full domain to open its settings.";
        
        $this->bot->sendMessage($message);
    }
    
    /**
     * List all domains
     */
    private function listDomains(): void
    {
        try {
            $domains = $this->cloudflareApi->listDomains();
            
            if (empty($domains)) {
                $this->bot->sendMessage('You don\'t have any domains yet. Use /add <domain> to add a new domain.');
                return;
            }
            
            $this->showDomainsList($domains, 0, 'Your domains:');
        } catch (Exception $e) {
            $this->logger->error('Error listing domains', ['error' => $e->getMessage()]);
            $this->bot->sendMessage("Error listing domains: {$e->getMessage()}");
        }
    }
    
    /**
     * Show domains list with pagination
     *
     * @param array $domains List of domains
     * @param int $page Current page (0-based)
     * @param string $title Message title
     */
    private function showDomainsList(array $domains, int $page, string $title): void
    {
        $domainsPerPage = $this->bot->getConfig()['pagination']['domains_per_page'];
        $totalDomains = count($domains);
        $totalPages = ceil($totalDomains / $domainsPerPage);
        
        if ($page < 0) {
            $page = 0;
        } elseif ($page >= $totalPages) {
            $page = $totalPages - 1;
        }
        
        $start = $page * $domainsPerPage;
        $end = min($start + $domainsPerPage, $totalDomains);
        
        $message = "{$title}\n\n";
        
        for ($i = $start; $i < $end; $i++) {
            $domain = $domains[$i];
            $status = $domain['status'] == 'active' ? '✅' : '⚠️';
            $message .= "{$i}. {$status} <b>{$domain['name']}</b>\n";
        }
        
        $message .= "\nPage " . ($page + 1) . " of {$totalPages}";
        
        // Create pagination keyboard
        $keyboard = [];
        
        // Add domain buttons
        $domainButtons = [];
        for ($i = $start; $i < $end; $i++) {
            $domainButtons[] = [
                'text' => $domains[$i]['name'],
                'callback_data' => "domain_{$domains[$i]['id']}"
            ];
            
            // Add row to keyboard after each button
            $keyboard[] = [$domainButtons[count($domainButtons) - 1]];
        }
        
        // Add pagination buttons
        $paginationButtons = [];
        
        if ($page > 0) {
            $paginationButtons[] = [
                'text' => '◀️ Previous',
                'callback_data' => "page_" . ($page - 1)
            ];
        }
        
        if ($page < $totalPages - 1) {
            $paginationButtons[] = [
                'text' => 'Next ▶️',
                'callback_data' => "page_" . ($page + 1)
            ];
        }
        
        if (!empty($paginationButtons)) {
            $keyboard[] = $paginationButtons;
        }
        
        $this->bot->sendMessage($message, $keyboard);
    }
    
    /**
     * Handle add domain command
     *
     * @param string $text The command text
     */
    private function handleAddDomain(string $text): void
    {
        $parts = explode(' ', $text, 2);
        
        if (count($parts) < 2 || empty(trim($parts[1]))) {
            $this->bot->sendMessage('Please provide a domain: /add <domain>');
            return;
        }
        
        $domain = trim($parts[1]);
        
        try {
            $result = $this->cloudflareApi->addDomain($domain);
            
            if ($result) {
                $message = "✅ Domain <b>{$domain}</b> has been added successfully!\n\n";
                $message .= "• Always Use HTTPS: Enabled\n";
                $message .= "• ECH: Disabled\n\n";
                $message .= "What would you like to do next?";
                
                $keyboard = [
                    [['text' => 'Domain Settings', 'callback_data' => "domain_{$result['id']}"]],
                    [['text' => 'DNS Records', 'callback_data' => "dns_{$result['id']}"]],
                    [['text' => 'WAF Rules', 'callback_data' => "waf_{$result['id']}"]],
                    [['text' => 'Redirects', 'callback_data' => "redirect_{$result['id']}"]],
                ];
                
                $this->bot->sendMessage($message, $keyboard);
            } else {
                $this->bot->sendMessage("Failed to add domain: {$domain}");
            }
        } catch (Exception $e) {
            $this->logger->error('Error adding domain', ['domain' => $domain, 'error' => $e->getMessage()]);
            $this->bot->sendMessage("Error adding domain: {$e->getMessage()}");
        }
    }
    
    /**
     * Open domain settings
     *
     * @param string $domain The domain name
     */
    private function openDomainSettings(string $domain): void
    {
        try {
            $result = $this->cloudflareApi->getDomainByName($domain);
            
            if (!$result) {
                $message = "Domain <b>{$domain}</b> not found. Do you want to add it?";
                $keyboard = [[['text' => "Add {$domain}", 'callback_data' => "add_{$domain}"]]];
                $this->bot->sendMessage($message, $keyboard);
                return;
            }
            
            $domainId = $result['id'];
            $status = $result['status'] == 'active' ? '✅ Active' : '⚠️ ' . ucfirst($result['status']);
            
            $message = "🌐 <b>Domain: {$domain}</b>\n\n";
            $message .= "Status: {$status}\n";
            $message .= "Always Use HTTPS: " . ($result['always_use_https'] ? '✅ Enabled' : '❌ Disabled') . "\n";
            $message .= "ECH: " . ($result['ech'] ? '✅ Enabled' : '❌ Disabled') . "\n";
            
            // Create keyboard with action buttons
            $keyboard = [
                [['text' => 'DNS Records', 'callback_data' => "dns_{$domainId}"]],
                [['text' => 'WAF Rules', 'callback_data' => "waf_{$domainId}"]],
                [['text' => 'Redirects', 'callback_data' => "redirect_{$domainId}"]],
                [
                    ['text' => 'Toggle HTTPS', 'callback_data' => "https_{$domainId}"],
                    ['text' => 'Toggle ECH', 'callback_data' => "ech_{$domainId}"]
                ],
                [['text' => '🗑️ Delete Domain', 'callback_data' => "delete_{$domainId}"]]
            ];
            
            $this->bot->sendMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error getting domain', ['domain' => $domain, 'error' => $e->getMessage()]);
            $this->bot->sendMessage("Error getting domain information: {$e->getMessage()}");
        }
    }
} 