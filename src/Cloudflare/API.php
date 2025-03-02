<?php

namespace CloudflareBot\Cloudflare;

use CloudflareBot\Helpers\Logger;
use Exception;

class API
{
    private array $config;
    private Logger $logger;
    private string $apiToken;
    private string $email;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';
    
    /**
     * API constructor
     *
     * @param array $config The Cloudflare API configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->apiToken = $config['api_token'];
        $this->email = $config['email'];
        $this->logger = new Logger(['enabled' => true, 'path' => __DIR__ . '/../../logs/cloudflare.log']);
    }
    
    /**
     * List all domains (zones)
     *
     * @return array List of domains
     * @throws Exception If the API request fails
     */
    public function listDomains(): array
    {
        $response = $this->request('GET', '/zones');
        return $this->formatDomains($response['result']);
    }
    
    /**
     * Search domains by name
     *
     * @param string $query The search query
     * @return array List of matching domains
     * @throws Exception If the API request fails
     */
    public function searchDomains(string $query): array
    {
        $response = $this->request('GET', '/zones', ['name' => $query]);
        
        if (empty($response['result'])) {
            // Try searching with a partial match
            $response = $this->request('GET', '/zones');
            $result = [];
            
            foreach ($response['result'] as $domain) {
                // Check if the domain name contains the query (case-insensitive)
                if (stripos($domain['name'], $query) !== false) {
                    $result[] = $domain;
                }
            }
            
            return $this->formatDomains($result);
        }
        
        return $this->formatDomains($response['result']);
    }
    
    /**
     * Get a domain by ID
     *
     * @param string $domainId The domain ID
     * @return array|null The domain data, or null if not found
     * @throws Exception If the API request fails
     */
    public function getDomainById(string $domainId): ?array
    {
        try {
            $response = $this->request('GET', "/zones/{$domainId}");
            return $this->formatDomain($response['result']);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Get a domain by name
     *
     * @param string $domainName The domain name
     * @return array|null The domain data, or null if not found
     * @throws Exception If the API request fails
     */
    public function getDomainByName(string $domainName): ?array
    {
        $response = $this->request('GET', '/zones', ['name' => $domainName]);
        
        if (empty($response['result'])) {
            return null;
        }
        
        return $this->formatDomain($response['result'][0]);
    }
    
    /**
     * Add a new domain
     *
     * @param string $domainName The domain name
     * @return array|null The new domain data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function addDomain(string $domainName): ?array
    {
        // Check if domain already exists
        $existingDomain = $this->getDomainByName($domainName);
        if ($existingDomain) {
            return $existingDomain;
        }
        
        // Add domain to Cloudflare
        $data = [
            'name' => $domainName,
            'account' => [
                'id' => $this->getAccountId()
            ],
            'jump_start' => true
        ];
        
        $response = $this->request('POST', '/zones', [], $data);
        $domain = $this->formatDomain($response['result']);
        
        // Enable Always Use HTTPS
        $this->updateDomainSetting($domain['id'], 'always_use_https', true);
        
        // Disable ECH
        $this->updateDomainSetting($domain['id'], 'ech', false);
        
        return $domain;
    }
    
    /**
     * Update a domain setting
     *
     * @param string $domainId The domain ID
     * @param string $setting The setting name
     * @param mixed $value The new value
     * @return bool True if the operation succeeded
     * @throws Exception If the API request fails
     */
    public function updateDomainSetting(string $domainId, string $setting, $value): bool
    {
        $response = $this->request('PATCH', "/zones/{$domainId}/settings/{$setting}", [], ['value' => $value]);
        return $response['success'];
    }
    
    /**
     * Delete a domain
     *
     * @param string $domainId The domain ID
     * @return bool True if the operation succeeded
     * @throws Exception If the API request fails
     */
    public function deleteDomain(string $domainId): bool
    {
        $response = $this->request('DELETE', "/zones/{$domainId}");
        return $response['success'];
    }
    
    /**
     * List DNS records for a domain
     *
     * @param string $domainId The domain ID
     * @return array List of DNS records
     * @throws Exception If the API request fails
     */
    public function listDnsRecords(string $domainId): array
    {
        $response = $this->request('GET', "/zones/{$domainId}/dns_records");
        return $response['result'];
    }
    
    /**
     * Get a DNS record
     *
     * @param string $domainId The domain ID
     * @param string $recordId The DNS record ID
     * @return array|null The DNS record data, or null if not found
     * @throws Exception If the API request fails
     */
    public function getDnsRecord(string $domainId, string $recordId): ?array
    {
        try {
            $response = $this->request('GET', "/zones/{$domainId}/dns_records/{$recordId}");
            return $response['result'];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Add a DNS record
     *
     * @param string $domainId The domain ID
     * @param array $data The DNS record data
     * @return array|null The new DNS record data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function addDnsRecord(string $domainId, array $data): ?array
    {
        $response = $this->request('POST', "/zones/{$domainId}/dns_records", [], $data);
        return $response['success'] ? $response['result'] : null;
    }
    
    /**
     * Update a DNS record
     *
     * @param string $domainId The domain ID
     * @param string $recordId The DNS record ID
     * @param array $data The new DNS record data
     * @return array|null The updated DNS record data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function updateDnsRecord(string $domainId, string $recordId, array $data): ?array
    {
        $response = $this->request('PATCH', "/zones/{$domainId}/dns_records/{$recordId}", [], $data);
        return $response['success'] ? $response['result'] : null;
    }
    
    /**
     * Delete a DNS record
     *
     * @param string $domainId The domain ID
     * @param string $recordId The DNS record ID
     * @return bool True if the operation succeeded
     * @throws Exception If the API request fails
     */
    public function deleteDnsRecord(string $domainId, string $recordId): bool
    {
        $response = $this->request('DELETE', "/zones/{$domainId}/dns_records/{$recordId}");
        return $response['success'];
    }
    
    /**
     * List WAF rules for a domain
     *
     * @param string $domainId The domain ID
     * @return array List of WAF rules
     * @throws Exception If the API request fails
     */
    public function listWafRules(string $domainId): array
    {
        $response = $this->request('GET', "/zones/{$domainId}/firewall/rules");
        return $response['result'];
    }
    
    /**
     * Get a WAF rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The WAF rule ID
     * @return array|null The WAF rule data, or null if not found
     * @throws Exception If the API request fails
     */
    public function getWafRule(string $domainId, string $ruleId): ?array
    {
        try {
            $response = $this->request('GET', "/zones/{$domainId}/firewall/rules/{$ruleId}");
            return $response['result'];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Add a WAF rule
     *
     * @param string $domainId The domain ID
     * @param array $data The WAF rule data
     * @return array|null The new WAF rule data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function addWafRule(string $domainId, array $data): ?array
    {
        $response = $this->request('POST', "/zones/{$domainId}/firewall/rules", [], $data);
        return $response['success'] ? $response['result'] : null;
    }
    
    /**
     * Update a WAF rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The WAF rule ID
     * @param array $data The new WAF rule data
     * @return array|null The updated WAF rule data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function updateWafRule(string $domainId, string $ruleId, array $data): ?array
    {
        $response = $this->request('PATCH', "/zones/{$domainId}/firewall/rules/{$ruleId}", [], $data);
        return $response['success'] ? $response['result'] : null;
    }
    
    /**
     * Delete a WAF rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The WAF rule ID
     * @return bool True if the operation succeeded
     * @throws Exception If the API request fails
     */
    public function deleteWafRule(string $domainId, string $ruleId): bool
    {
        $response = $this->request('DELETE', "/zones/{$domainId}/firewall/rules/{$ruleId}");
        return $response['success'];
    }
    
    /**
     * List redirect rules for a domain
     *
     * @param string $domainId The domain ID
     * @return array List of redirect rules
     * @throws Exception If the API request fails
     */
    public function listRedirectRules(string $domainId): array
    {
        $response = $this->request('GET', "/zones/{$domainId}/pagerules", ['status' => 'active,disabled']);
        
        // Filter to only include redirect rules
        $redirectRules = [];
        foreach ($response['result'] as $rule) {
            foreach ($rule['actions'] as $action) {
                if ($action['id'] === 'forwarding_url') {
                    $redirectRules[] = $this->formatRedirectRule($rule, $action);
                    break;
                }
            }
        }
        
        return $redirectRules;
    }
    
    /**
     * Get a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The redirect rule ID
     * @return array|null The redirect rule data, or null if not found
     * @throws Exception If the API request fails
     */
    public function getRedirectRule(string $domainId, string $ruleId): ?array
    {
        try {
            $response = $this->request('GET', "/zones/{$domainId}/pagerules/{$ruleId}");
            
            // Check if it's a redirect rule
            foreach ($response['result']['actions'] as $action) {
                if ($action['id'] === 'forwarding_url') {
                    return $this->formatRedirectRule($response['result'], $action);
                }
            }
            
            return null;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Add a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $sourceUrl The source URL
     * @param string $targetUrl The target URL
     * @param int $statusCode The HTTP status code (301, 302, etc.)
     * @param bool $preserveQueryString Whether to preserve the query string
     * @param string $status The rule status (active or disabled)
     * @return array|null The new redirect rule data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function addRedirectRule(
        string $domainId,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $preserveQueryString = true,
        string $status = 'active'
    ): ?array {
        $data = [
            'targets' => [
                [
                    'target' => 'url',
                    'constraint' => [
                        'operator' => 'matches',
                        'value' => $this->formatSourceUrl($domainId, $sourceUrl)
                    ]
                ]
            ],
            'actions' => [
                [
                    'id' => 'forwarding_url',
                    'value' => [
                        'url' => $targetUrl,
                        'status_code' => $statusCode,
                        'preserve_query_string' => $preserveQueryString
                    ]
                ]
            ],
            'priority' => 1,
            'status' => $status
        ];
        
        $response = $this->request('POST', "/zones/{$domainId}/pagerules", [], $data);
        
        if (!$response['success']) {
            return null;
        }
        
        // Format the response
        foreach ($response['result']['actions'] as $action) {
            if ($action['id'] === 'forwarding_url') {
                return $this->formatRedirectRule($response['result'], $action);
            }
        }
        
        return null;
    }
    
    /**
     * Update a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The redirect rule ID
     * @param string $sourceUrl The new source URL
     * @param string $targetUrl The new target URL
     * @param int $statusCode The new HTTP status code
     * @param bool $preserveQueryString Whether to preserve the query string
     * @param string $status The new rule status (active or disabled)
     * @return array|null The updated redirect rule data, or null if the operation failed
     * @throws Exception If the API request fails
     */
    public function updateRedirectRule(
        string $domainId,
        string $ruleId,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $preserveQueryString = true,
        string $status = 'active'
    ): ?array {
        $data = [
            'targets' => [
                [
                    'target' => 'url',
                    'constraint' => [
                        'operator' => 'matches',
                        'value' => $this->formatSourceUrl($domainId, $sourceUrl)
                    ]
                ]
            ],
            'actions' => [
                [
                    'id' => 'forwarding_url',
                    'value' => [
                        'url' => $targetUrl,
                        'status_code' => $statusCode,
                        'preserve_query_string' => $preserveQueryString
                    ]
                ]
            ],
            'status' => $status
        ];
        
        $response = $this->request('PUT', "/zones/{$domainId}/pagerules/{$ruleId}", [], $data);
        
        if (!$response['success']) {
            return null;
        }
        
        // Format the response
        foreach ($response['result']['actions'] as $action) {
            if ($action['id'] === 'forwarding_url') {
                return $this->formatRedirectRule($response['result'], $action);
            }
        }
        
        return null;
    }
    
    /**
     * Delete a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $ruleId The redirect rule ID
     * @return bool True if the operation succeeded
     * @throws Exception If the API request fails
     */
    public function deleteRedirectRule(string $domainId, string $ruleId): bool
    {
        $response = $this->request('DELETE', "/zones/{$domainId}/pagerules/{$ruleId}");
        return $response['success'];
    }
    
    /**
     * Get the user's account ID
     *
     * @return string The account ID
     * @throws Exception If the API request fails
     */
    private function getAccountId(): string
    {
        static $accountId = null;
        
        if ($accountId === null) {
            $response = $this->request('GET', '/accounts');
            
            if (empty($response['result'])) {
                throw new Exception('No accounts found for the user.');
            }
            
            $accountId = $response['result'][0]['id'];
        }
        
        return $accountId;
    }
    
    /**
     * Format domain data for the bot
     *
     * @param array $domain The domain data from Cloudflare API
     * @return array The formatted domain data
     */
    private function formatDomain(array $domain): array
    {
        return [
            'id' => $domain['id'],
            'name' => $domain['name'],
            'status' => $domain['status'],
            'always_use_https' => $domain['settings']['always_use_https'] ?? false,
            'ech' => $domain['settings']['ech'] ?? false
        ];
    }
    
    /**
     * Format multiple domains
     *
     * @param array $domains The domains data from Cloudflare API
     * @return array The formatted domains data
     */
    private function formatDomains(array $domains): array
    {
        $result = [];
        
        foreach ($domains as $domain) {
            $result[] = $this->formatDomain($domain);
        }
        
        return $result;
    }
    
    /**
     * Format a redirect rule
     *
     * @param array $rule The page rule data from Cloudflare API
     * @param array $action The forwarding_url action
     * @return array The formatted redirect rule
     */
    private function formatRedirectRule(array $rule, array $action): array
    {
        $sourceUrl = $rule['targets'][0]['constraint']['value'];
        
        // Extract domain name from source URL if it's a full URL
        if (strpos($sourceUrl, 'http') === 0) {
            $parsedUrl = parse_url($sourceUrl);
            $sourceUrl = $parsedUrl['path'] ?? '/';
            if (!empty($parsedUrl['query'])) {
                $sourceUrl .= '?' . $parsedUrl['query'];
            }
        }
        
        return [
            'id' => $rule['id'],
            'source_url' => $sourceUrl,
            'target_url' => $action['value']['url'],
            'status_code' => $action['value']['status_code'],
            'preserve_query_string' => $action['value']['preserve_query_string'] ?? false,
            'status' => $rule['status']
        ];
    }
    
    /**
     * Format a source URL for a redirect rule
     *
     * @param string $domainId The domain ID
     * @param string $sourceUrl The source URL
     * @return string The formatted source URL
     */
    private function formatSourceUrl(string $domainId, string $sourceUrl): string
    {
        // If the source URL already includes the domain or is a pattern, return as is
        if (strpos($sourceUrl, 'http') === 0 || strpos($sourceUrl, '*') !== false) {
            return $sourceUrl;
        }
        
        // Otherwise, get the domain name and prepend it
        $domain = $this->getDomainById($domainId);
        return $domain['name'] . $sourceUrl;
    }
    
    /**
     * Send a request to the Cloudflare API
     *
     * @param string $method The HTTP method
     * @param string $endpoint The API endpoint
     * @param array $params The URL parameters
     * @param array $data The request body
     * @return array The API response
     * @throws Exception If the request fails
     */
    private function request(string $method, string $endpoint, array $params = [], array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiToken,
            'X-Auth-Email: ' . $this->email
        ];
        
        $curl = curl_init();
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method
        ];
        
        if (!empty($data)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($curl, $options);
        
        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        
        curl_close($curl);
        
        if ($response === false) {
            $this->logger->error('API request failed', [
                'endpoint' => $endpoint,
                'error' => $curlError
            ]);
            throw new Exception('API request failed: ' . $curlError);
        }
        
        $responseData = json_decode($response, true);
        
        if ($statusCode >= 400) {
            $errorMessage = isset($responseData['errors'][0]['message'])
                ? $responseData['errors'][0]['message']
                : "API error (HTTP {$statusCode})";
            
            $this->logger->error('API returned error', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'error' => $errorMessage
            ]);
            
            throw new Exception('API error: ' . $errorMessage, $statusCode);
        }
        
        return $responseData;
    }
} 