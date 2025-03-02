<?php

namespace CloudflareBot\Handlers;

use CloudflareBot\Cloudflare\API;
use CloudflareBot\Telegram\Bot;
use CloudflareBot\Helpers\Logger;
use Exception;

class CallbackHandler
{
    private Bot $bot;
    private array $callback;
    private Logger $logger;
    private API $cloudflareApi;
    
    /**
     * CallbackHandler constructor
     *
     * @param Bot $bot The bot instance
     * @param array $callback The callback query data
     */
    public function __construct(Bot $bot, array $callback)
    {
        $this->bot = $bot;
        $this->callback = $callback;
        $this->logger = new Logger($bot->getConfig()['log']);
        $this->cloudflareApi = new API($bot->getConfig()['cloudflare']);
    }
    
    /**
     * Handle a callback query
     */
    public function handleCallback(): void
    {
        $data = $this->callback['data'];
        $this->logger->info('Handling callback', ['data' => $data]);
        
        if (strpos($data, 'domain_') === 0) {
            $domainId = substr($data, 7);
            $this->showDomainSettings($domainId);
        } elseif (strpos($data, 'page_') === 0) {
            $page = (int) substr($data, 5);
            $this->handlePagination($page);
        } elseif (strpos($data, 'add_') === 0) {
            $domain = substr($data, 4);
            $this->addDomain($domain);
        } elseif (strpos($data, 'dns_') === 0) {
            $domainId = substr($data, 4);
            $this->showDnsRecords($domainId);
        } elseif (strpos($data, 'waf_') === 0) {
            $domainId = substr($data, 4);
            $this->showWafRules($domainId);
        } elseif (strpos($data, 'redirect_') === 0) {
            $domainId = substr($data, 9);
            $this->showRedirectRules($domainId);
        } elseif (strpos($data, 'https_') === 0) {
            $domainId = substr($data, 6);
            $this->toggleHttps($domainId);
        } elseif (strpos($data, 'ech_') === 0) {
            $domainId = substr($data, 4);
            $this->toggleEch($domainId);
        } elseif (strpos($data, 'delete_') === 0) {
            $domainId = substr($data, 7);
            $this->confirmDeleteDomain($domainId);
        } elseif (strpos($data, 'confirm_delete_') === 0) {
            $domainId = substr($data, 15);
            $this->deleteDomain($domainId);
        } elseif (strpos($data, 'edit_dns_') === 0) {
            $parts = explode('_', $data);
            $domainId = $parts[2];
            $recordId = $parts[3];
            $this->showEditDnsRecord($domainId, $recordId);
        } elseif (strpos($data, 'add_dns_') === 0) {
            $domainId = substr($data, 8);
            $this->showAddDnsForm($domainId);
        } elseif (strpos($data, 'edit_waf_') === 0) {
            $parts = explode('_', $data);
            $domainId = $parts[2];
            $ruleId = $parts[3];
            $this->showEditWafRule($domainId, $ruleId);
        } elseif (strpos($data, 'add_waf_') === 0) {
            $domainId = substr($data, 8);
            $this->showAddWafForm($domainId);
        } elseif (strpos($data, 'edit_redirect_') === 0) {
            $parts = explode('_', $data);
            $domainId = $parts[2];
            $ruleId = $parts[3];
            $this->showEditRedirectRule($domainId, $ruleId);
        } elseif (strpos($data, 'add_redirect_') === 0) {
            $domainId = substr($data, 13);
            $this->showAddRedirectForm($domainId);
        } else {
            $this->bot->sendMessage('Unknown callback data');
        }
    }
    
    /**
     * Show domain settings
     *
     * @param string $domainId The domain ID
     */
    private function showDomainSettings(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            
            if (!$domain) {
                $this->bot->editMessage('Domain not found.');
                return;
            }
            
            $status = $domain['status'] == 'active' ? '✅ Active' : '⚠️ ' . ucfirst($domain['status']);
            
            $message = "🌐 <b>Domain: {$domain['name']}</b>\n\n";
            $message .= "Status: {$status}\n";
            $message .= "Always Use HTTPS: " . ($domain['always_use_https'] ? '✅ Enabled' : '❌ Disabled') . "\n";
            $message .= "ECH: " . ($domain['ech'] ? '✅ Enabled' : '❌ Disabled') . "\n";
            
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
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error getting domain', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error getting domain information: {$e->getMessage()}");
        }
    }
    
    /**
     * Handle pagination for domain list
     *
     * @param int $page The page number
     */
    private function handlePagination(int $page): void
    {
        try {
            $domains = $this->cloudflareApi->listDomains();
            
            if (empty($domains)) {
                $this->bot->editMessage('You don\'t have any domains yet.');
                return;
            }
            
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
            
            $message = "Your domains:\n\n";
            
            for ($i = $start; $i < $end; $i++) {
                $domain = $domains[$i];
                $status = $domain['status'] == 'active' ? '✅' : '⚠️';
                $message .= ($i + 1) . ". {$status} <b>{$domain['name']}</b>\n";
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
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error paginating domains', ['page' => $page, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error listing domains: {$e->getMessage()}");
        }
    }
    
    /**
     * Add a domain
     *
     * @param string $domain The domain name
     */
    private function addDomain(string $domain): void
    {
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
                
                $this->bot->editMessage($message, $keyboard);
            } else {
                $this->bot->editMessage("Failed to add domain: {$domain}");
            }
        } catch (Exception $e) {
            $this->logger->error('Error adding domain', ['domain' => $domain, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error adding domain: {$e->getMessage()}");
        }
    }
    
    /**
     * Show DNS records for a domain
     *
     * @param string $domainId The domain ID
     */
    private function showDnsRecords(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $dnsRecords = $this->cloudflareApi->listDnsRecords($domainId);
            
            $message = "DNS Records for <b>{$domain['name']}</b>:\n\n";
            
            if (empty($dnsRecords)) {
                $message .= "No DNS records found.";
            } else {
                foreach ($dnsRecords as $i => $record) {
                    $message .= ($i + 1) . ". <b>{$record['type']}</b> {$record['name']} → {$record['content']}\n";
                    if (!empty($record['ttl']) && $record['ttl'] != 1) {
                        $message .= "   TTL: {$record['ttl']} seconds\n";
                    }
                    if (!empty($record['proxied'])) {
                        $message .= "   Proxied: " . ($record['proxied'] ? '✅' : '❌') . "\n";
                    }
                    $message .= "\n";
                }
            }
            
            // Create keyboard
            $keyboard = [];
            
            // Add record buttons
            foreach ($dnsRecords as $record) {
                $keyboard[] = [[
                    'text' => "{$record['type']} {$record['name']}",
                    'callback_data' => "edit_dns_{$domainId}_{$record['id']}"
                ]];
            }
            
            // Add new record button
            $keyboard[] = [['text' => '➕ Add DNS Record', 'callback_data' => "add_dns_{$domainId}"]];
            
            // Back button
            $keyboard[] = [['text' => '🔙 Back to Domain', 'callback_data' => "domain_{$domainId}"]];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error getting DNS records', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error getting DNS records: {$e->getMessage()}");
        }
    }
    
    /**
     * Show WAF rules for a domain
     *
     * @param string $domainId The domain ID
     */
    private function showWafRules(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $wafRules = $this->cloudflareApi->listWafRules($domainId);
            
            $message = "WAF Rules for <b>{$domain['name']}</b>:\n\n";
            
            if (empty($wafRules)) {
                $message .= "No WAF rules found.";
            } else {
                foreach ($wafRules as $i => $rule) {
                    $status = $rule['status'] == 'active' ? '✅' : '❌';
                    $message .= ($i + 1) . ". {$status} <b>{$rule['name']}</b>\n";
                    $message .= "   Mode: {$rule['mode']}\n";
                    if (!empty($rule['priority'])) {
                        $message .= "   Priority: {$rule['priority']}\n";
                    }
                    $message .= "\n";
                }
            }
            
            // Create keyboard
            $keyboard = [];
            
            // Add rule buttons
            foreach ($wafRules as $rule) {
                $keyboard[] = [[
                    'text' => $rule['name'],
                    'callback_data' => "edit_waf_{$domainId}_{$rule['id']}"
                ]];
            }
            
            // Add new rule button
            $keyboard[] = [['text' => '➕ Add WAF Rule', 'callback_data' => "add_waf_{$domainId}"]];
            
            // Back button
            $keyboard[] = [['text' => '🔙 Back to Domain', 'callback_data' => "domain_{$domainId}"]];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error getting WAF rules', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error getting WAF rules: {$e->getMessage()}");
        }
    }
    
    /**
     * Show redirect rules for a domain
     *
     * @param string $domainId The domain ID
     */
    private function showRedirectRules(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $redirectRules = $this->cloudflareApi->listRedirectRules($domainId);
            
            $message = "Redirect Rules for <b>{$domain['name']}</b>:\n\n";
            
            if (empty($redirectRules)) {
                $message .= "No redirect rules found.";
            } else {
                foreach ($redirectRules as $i => $rule) {
                    $status = $rule['status'] == 'active' ? '✅' : '❌';
                    $message .= ($i + 1) . ". {$status} <b>{$rule['source_url']}</b> → {$rule['target_url']}\n";
                    $message .= "   Code: {$rule['status_code']}\n";
                    if (!empty($rule['preserve_query_string'])) {
                        $message .= "   Preserve Query: " . ($rule['preserve_query_string'] ? '✅' : '❌') . "\n";
                    }
                    $message .= "\n";
                }
            }
            
            // Create keyboard
            $keyboard = [];
            
            // Add rule buttons
            foreach ($redirectRules as $rule) {
                $keyboard[] = [[
                    'text' => "{$rule['source_url']} → {$rule['target_url']}",
                    'callback_data' => "edit_redirect_{$domainId}_{$rule['id']}"
                ]];
            }
            
            // Add new rule button
            $keyboard[] = [['text' => '➕ Add Redirect Rule', 'callback_data' => "add_redirect_{$domainId}"]];
            
            // Back button
            $keyboard[] = [['text' => '🔙 Back to Domain', 'callback_data' => "domain_{$domainId}"]];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error getting redirect rules', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error getting redirect rules: {$e->getMessage()}");
        }
    }
    
    /**
     * Toggle Always Use HTTPS for a domain
     *
     * @param string $domainId The domain ID
     */
    private function toggleHttps(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $currentSetting = $domain['always_use_https'];
            
            $result = $this->cloudflareApi->updateDomainSetting($domainId, 'always_use_https', !$currentSetting);
            
            if ($result) {
                // Show updated domain settings
                $this->showDomainSettings($domainId);
                $statusText = !$currentSetting ? 'enabled' : 'disabled';
                $this->bot->answerCallbackQuery("Always Use HTTPS {$statusText}");
            } else {
                $this->bot->answerCallbackQuery('Failed to update setting');
            }
        } catch (Exception $e) {
            $this->logger->error('Error toggling HTTPS', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error updating setting: {$e->getMessage()}");
        }
    }
    
    /**
     * Toggle ECH for a domain
     *
     * @param string $domainId The domain ID
     */
    private function toggleEch(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $currentSetting = $domain['ech'];
            
            $result = $this->cloudflareApi->updateDomainSetting($domainId, 'ech', !$currentSetting);
            
            if ($result) {
                // Show updated domain settings
                $this->showDomainSettings($domainId);
                $statusText = !$currentSetting ? 'enabled' : 'disabled';
                $this->bot->answerCallbackQuery("ECH {$statusText}");
            } else {
                $this->bot->answerCallbackQuery('Failed to update setting');
            }
        } catch (Exception $e) {
            $this->logger->error('Error toggling ECH', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error updating setting: {$e->getMessage()}");
        }
    }
    
    /**
     * Show confirmation dialog for domain deletion
     *
     * @param string $domainId The domain ID
     */
    private function confirmDeleteDomain(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            
            $message = "⚠️ <b>Confirm Deletion</b> ⚠️\n\n";
            $message .= "Are you sure you want to delete the domain <b>{$domain['name']}</b>?\n\n";
            $message .= "This action cannot be undone!";
            
            $keyboard = [
                [
                    ['text' => '✅ Yes, delete it', 'callback_data' => "confirm_delete_{$domainId}"],
                    ['text' => '❌ No, cancel', 'callback_data' => "domain_{$domainId}"]
                ]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error confirming domain deletion', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error: {$e->getMessage()}");
        }
    }
    
    /**
     * Delete a domain
     *
     * @param string $domainId The domain ID
     */
    private function deleteDomain(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $domainName = $domain['name'];
            
            $result = $this->cloudflareApi->deleteDomain($domainId);
            
            if ($result) {
                $message = "✅ Domain <b>{$domainName}</b> has been deleted successfully.";
                $keyboard = [
                    [['text' => 'Show All Domains', 'callback_data' => "page_0"]]
                ];
                $this->bot->editMessage($message, $keyboard);
                $this->bot->answerCallbackQuery("Domain deleted");
            } else {
                $this->bot->editMessage("Failed to delete domain: {$domainName}");
            }
        } catch (Exception $e) {
            $this->logger->error('Error deleting domain', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error deleting domain: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to edit a DNS record
     *
     * @param string $domainId The domain ID
     * @param string $recordId The DNS record ID
     */
    private function showEditDnsRecord(string $domainId, string $recordId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $record = $this->cloudflareApi->getDnsRecord($domainId, $recordId);
            
            $message = "Edit DNS Record for <b>{$domain['name']}</b>:\n\n";
            $message .= "Type: <b>{$record['type']}</b>\n";
            $message .= "Name: <b>{$record['name']}</b>\n";
            $message .= "Content: <b>{$record['content']}</b>\n";
            $message .= "TTL: <b>" . ($record['ttl'] == 1 ? 'Auto' : $record['ttl'] . ' seconds') . "</b>\n";
            $message .= "Proxied: <b>" . ($record['proxied'] ? 'Yes' : 'No') . "</b>\n\n";
            $message .= "To edit this record, send a new message with updated information in the format:\n";
            $message .= "<code>/edit_dns {$domainId} {$recordId} name content ttl proxied</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/edit_dns {$domainId} {$recordId} www 192.168.1.1 auto yes</code>";
            
            $keyboard = [
                [['text' => '🗑️ Delete Record', 'callback_data' => "delete_dns_{$domainId}_{$recordId}"]],
                [['text' => '🔙 Back to DNS Records', 'callback_data' => "dns_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing DNS record edit form', [
                'domain_id' => $domainId,
                'record_id' => $recordId,
                'error' => $e->getMessage()
            ]);
            $this->bot->editMessage("Error getting DNS record: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to add a DNS record
     *
     * @param string $domainId The domain ID
     */
    private function showAddDnsForm(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            
            $message = "Add DNS Record for <b>{$domain['name']}</b>:\n\n";
            $message .= "To add a new record, send a message in the format:\n";
            $message .= "<code>/add_dns {$domainId} type name content ttl proxied</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/add_dns {$domainId} A www 192.168.1.1 auto yes</code>\n\n";
            $message .= "Supported record types: A, AAAA, CNAME, TXT, MX, NS, SRV";
            
            $keyboard = [
                [['text' => '🔙 Back to DNS Records', 'callback_data' => "dns_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing add DNS form', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to edit a WAF rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The WAF rule ID
     */
    private function showEditWafRule(string $domainId, string $ruleId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $rule = $this->cloudflareApi->getWafRule($domainId, $ruleId);
            
            $message = "Edit WAF Rule for <b>{$domain['name']}</b>:\n\n";
            $message .= "Name: <b>{$rule['name']}</b>\n";
            $message .= "Mode: <b>{$rule['mode']}</b>\n";
            if (!empty($rule['expression'])) {
                $message .= "Expression: <b>{$rule['expression']}</b>\n";
            }
            if (!empty($rule['priority'])) {
                $message .= "Priority: <b>{$rule['priority']}</b>\n";
            }
            $message .= "Status: <b>" . ($rule['status'] == 'active' ? 'Active' : 'Disabled') . "</b>\n\n";
            $message .= "To edit this rule, send a new message with updated information in the format:\n";
            $message .= "<code>/edit_waf {$domainId} {$ruleId} name mode expression priority status</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/edit_waf {$domainId} {$ruleId} Block SQL Injection block \"(http.request.uri.path contains \"sql\")\" 1 active</code>";
            
            $keyboard = [
                [['text' => '🗑️ Delete Rule', 'callback_data' => "delete_waf_{$domainId}_{$ruleId}"]],
                [['text' => '🔙 Back to WAF Rules', 'callback_data' => "waf_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing WAF rule edit form', [
                'domain_id' => $domainId,
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            $this->bot->editMessage("Error getting WAF rule: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to add a WAF rule
     *
     * @param string $domainId The domain ID
     */
    private function showAddWafForm(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            
            $message = "Add WAF Rule for <b>{$domain['name']}</b>:\n\n";
            $message .= "To add a new rule, send a message in the format:\n";
            $message .= "<code>/add_waf {$domainId} name mode expression priority status</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/add_waf {$domainId} Block SQL Injection block \"(http.request.uri.path contains \"sql\")\" 1 active</code>\n\n";
            $message .= "Available modes: block, challenge, js_challenge, managed_challenge";
            
            $keyboard = [
                [['text' => '🔙 Back to WAF Rules', 'callback_data' => "waf_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing add WAF form', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to edit a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The redirect rule ID
     */
    private function showEditRedirectRule(string $domainId, string $ruleId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            $rule = $this->cloudflareApi->getRedirectRule($domainId, $ruleId);
            
            $message = "Edit Redirect Rule for <b>{$domain['name']}</b>:\n\n";
            $message .= "Source URL: <b>{$rule['source_url']}</b>\n";
            $message .= "Target URL: <b>{$rule['target_url']}</b>\n";
            $message .= "Status Code: <b>{$rule['status_code']}</b>\n";
            $message .= "Preserve Query String: <b>" . ($rule['preserve_query_string'] ? 'Yes' : 'No') . "</b>\n";
            $message .= "Status: <b>" . ($rule['status'] == 'active' ? 'Active' : 'Disabled') . "</b>\n\n";
            $message .= "To edit this rule, send a new message with updated information in the format:\n";
            $message .= "<code>/edit_redirect {$domainId} {$ruleId} source_url target_url status_code preserve_query status</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/edit_redirect {$domainId} {$ruleId} /old-page/ /new-page/ 301 yes active</code>";
            
            $keyboard = [
                [['text' => '🗑️ Delete Rule', 'callback_data' => "delete_redirect_{$domainId}_{$ruleId}"]],
                [['text' => '🔙 Back to Redirect Rules', 'callback_data' => "redirect_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing redirect rule edit form', [
                'domain_id' => $domainId,
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            $this->bot->editMessage("Error getting redirect rule: {$e->getMessage()}");
        }
    }
    
    /**
     * Show form to add a redirect rule
     *
     * @param string $domainId The domain ID
     */
    private function showAddRedirectForm(string $domainId): void
    {
        try {
            $domain = $this->cloudflareApi->getDomainById($domainId);
            
            $message = "Add Redirect Rule for <b>{$domain['name']}</b>:\n\n";
            $message .= "To add a new rule, send a message in the format:\n";
            $message .= "<code>/add_redirect {$domainId} source_url target_url status_code preserve_query status</code>\n\n";
            $message .= "For example:\n";
            $message .= "<code>/add_redirect {$domainId} /old-page/ /new-page/ 301 yes active</code>\n\n";
            $message .= "Available status codes: 301, 302, 307, 308";
            
            $keyboard = [
                [['text' => '🔙 Back to Redirect Rules', 'callback_data' => "redirect_{$domainId}"]]
            ];
            
            $this->bot->editMessage($message, $keyboard);
        } catch (Exception $e) {
            $this->logger->error('Error showing add redirect form', ['domain_id' => $domainId, 'error' => $e->getMessage()]);
            $this->bot->editMessage("Error: {$e->getMessage()}");
        }
    }
} 