<?php

namespace App\Plugins\Cloudflared;

use App\Core\Database;

class CloudflareApi
{
    private string $apiToken;
    private string $accountId;
    private string $tunnelId;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct()
    {
        $this->apiToken  = Database::getSetting('cf_api_token', '') ?? '';
        $this->accountId = Database::getSetting('cf_account_id', '') ?? '';
        $this->tunnelId  = Database::getSetting('cf_tunnel_id', '') ?? '';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiToken) && !empty($this->accountId) && !empty($this->tunnelId);
    }

    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        if (empty($this->apiToken)) {
            throw new \Exception("Cloudflare API Token belum dikonfigurasi di Pengaturan Panel.");
        }

        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("Cloudflare API Network Error: " . $error);
        }

        $json = json_decode($response, true);
        if ($httpCode >= 400 || empty($json['success'])) {
            $msg = $json['errors'][0]['message'] ?? "HTTP Status {$httpCode}";
            throw new \Exception("Cloudflare API Error: " . $msg);
        }

        return $json['result'] ?? [];
    }

    public function getZoneId(string $domain): ?string
    {
        // Extract root domain (e.g. sub.domain.com -> domain.com)
        $parts = explode('.', $domain);
        if (count($parts) > 2) {
            $rootDomain = implode('.', array_slice($parts, -2));
        } else {
            $rootDomain = $domain;
        }

        $result = $this->request('GET', "/zones?name=" . urlencode($rootDomain));
        return $result[0]['id'] ?? null;
    }

    public function addPublicHostname(string $hostname, string $service = 'http://127.0.0.1:8088', ?string $httpHostHeader = null): bool
    {
        $endpoint = "/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations";
        $current = $this->request('GET', $endpoint);

        $ingress = $current['config']['ingress'] ?? [];

        // Remove catch-all 404 rule if present to append at the very end
        $filtered = [];
        foreach ($ingress as $rule) {
            if (($rule['service'] ?? '') === 'http_status:404' && empty($rule['hostname'])) {
                continue;
            }
            if (($rule['hostname'] ?? '') === $hostname) {
                continue; // will be replaced
            }
            // Fix: Go backend fails if originRequest is an empty array []
            if (isset($rule['originRequest']) && empty($rule['originRequest'])) {
                unset($rule['originRequest']);
            }
            $filtered[] = $rule;
        }

        // Add new ingress rule
        $newRule = [
            'hostname' => $hostname,
            'service'  => $service,
        ];

        $originRequest = [];
        if (!empty($httpHostHeader)) {
            $originRequest['httpHostHeader'] = $httpHostHeader;
        }

        if (!empty($originRequest)) {
            $newRule['originRequest'] = $originRequest;
        }

        array_unshift($filtered, $newRule);

        // Append final catch-all rule
        $filtered[] = ['service' => 'http_status:404'];

        $payload = [
            'config' => [
                'ingress' => $filtered,
            ],
        ];

        $this->request('PUT', $endpoint, $payload);
        return true;
    }

    public function removePublicHostname(string $hostname): bool
    {
        $endpoint = "/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations";
        $current = $this->request('GET', $endpoint);

        $ingress = $current['config']['ingress'] ?? [];
        $filtered = [];

        foreach ($ingress as $rule) {
            if (($rule['hostname'] ?? '') === $hostname) {
                continue;
            }
            if (($rule['service'] ?? '') === 'http_status:404' && empty($rule['hostname'])) {
                continue;
            }
            // Fix: Go backend fails if originRequest is an empty array []
            if (isset($rule['originRequest']) && empty($rule['originRequest'])) {
                unset($rule['originRequest']);
            }
            $filtered[] = $rule;
        }

        $filtered[] = ['service' => 'http_status:404'];

        $payload = [
            'config' => [
                'ingress' => $filtered,
            ],
        ];

        $this->request('PUT', $endpoint, $payload);
        return true;
    }

    public function upsertDnsCname(string $zoneId, string $hostname, string $cnameTarget): bool
    {
        $endpoint = "/zones/{$zoneId}/dns_records?name=" . urlencode($hostname) . "&type=CNAME";
        $records = $this->request('GET', $endpoint);

        $recordData = [
            'type'    => 'CNAME',
            'name'    => $hostname,
            'content' => $cnameTarget,
            'proxied' => true,
            'ttl'     => 1, // Auto
        ];

        if (!empty($records[0]['id'])) {
            $recordId = $records[0]['id'];
            $this->request('PUT', "/zones/{$zoneId}/dns_records/{$recordId}", $recordData);
        } else {
            $this->request('POST', "/zones/{$zoneId}/dns_records", $recordData);
        }

        return true;
    }
}
