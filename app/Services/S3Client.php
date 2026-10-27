<?php

namespace App\Services;

class S3Client
{
    private string $endpoint;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $host;
    private string $scheme;

    public function __construct(
        string $endpoint,
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $region = 'auto'
    ) {
        $endpoint = trim($endpoint);
        if (!preg_match('/^https?:\/\//i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        $this->endpoint  = rtrim($endpoint, '/');
        $this->bucket    = trim($bucket);
        $this->accessKey = trim($accessKey);
        $this->secretKey = trim($secretKey);
        $this->region    = empty($region) ? 'auto' : trim($region);

        $parsed = parse_url($this->endpoint);
        $this->host   = $parsed['host'] ?? '';
        if (!empty($parsed['port'])) {
            $this->host .= ':' . $parsed['port'];
        }
        $this->scheme = $parsed['scheme'] ?? 'https';
    }

    /**
     * Test connection to bucket with latency check
     */
    public function testConnection(): array
    {
        if (empty($this->endpoint) || empty($this->bucket) || empty($this->accessKey) || empty($this->secretKey)) {
            return [
                'success' => false,
                'error'   => 'Konfigurasi Endpoint, Bucket, Access Key, atau Secret Key belum lengkap.',
            ];
        }

        $start = microtime(true);
        $path = '/' . rawurlencode($this->bucket);
        $queryParams = ['max-keys' => '1'];

        $res = $this->request('GET', $path, $queryParams, '');
        $latencyMs = (int)round((microtime(true) - $start) * 1000);

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'success'    => true,
                'message'    => "Koneksi ke bucket '{$this->bucket}' berhasil!",
                'latency_ms' => $latencyMs,
                'status'     => $res['status'],
            ];
        }

        $errorMsg = "HTTP {$res['status']}";
        if (!empty($res['body'])) {
            if (preg_match('/<Message>(.*?)<\/Message>/is', $res['body'], $m)) {
                $errorMsg .= ': ' . trim($m[1]);
            } elseif (preg_match('/<Code>(.*?)<\/Code>/is', $res['body'], $m)) {
                $errorMsg .= ': ' . trim($m[1]);
            }
        }

        if ($res['status'] === 403) {
            $errorMsg .= ' (Access Denied / Kunci otentikasi tidak valid atau tidak memiliki izin pada bucket ini).';
        } elseif ($res['status'] === 404) {
            $errorMsg .= ' (Bucket tidak ditemukan. Pastikan nama bucket sudah benar).';
        }

        return [
            'success'    => false,
            'error'      => $errorMsg,
            'latency_ms' => $latencyMs,
            'status'     => $res['status'],
        ];
    }

    /**
     * Upload local file to remote S3 / Cloudflare R2 bucket
     */
    public function uploadFile(string $localFile, string $remoteKey, string $contentType = 'application/octet-stream'): array
    {
        if (!file_exists($localFile)) {
            return ['success' => false, 'error' => "File lokal tidak ditemukan: {$localFile}"];
        }

        $fileSize = filesize($localFile);
        $remoteKey = ltrim($remoteKey, '/');
        $path = '/' . rawurlencode($this->bucket) . '/' . self::encodePath($remoteKey);

        $payloadHash = hash_file('sha256', $localFile);

        $extraHeaders = [
            'content-type'   => $contentType,
            'content-length' => (string)$fileSize,
        ];

        $fp = fopen($localFile, 'rb');
        if (!$fp) {
            return ['success' => false, 'error' => "Gagal membuka file lokal untuk dibaca."];
        }

        $res = $this->requestStream('PUT', $path, [], $payloadHash, $extraHeaders, $fp, $fileSize);
        fclose($fp);

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'success'   => true,
                'cloud_key' => $remoteKey,
                'status'    => $res['status'],
                'url'       => "{$this->endpoint}/{$this->bucket}/{$remoteKey}",
            ];
        }

        $errorMsg = "Upload gagal (HTTP {$res['status']})";
        if (!empty($res['body']) && preg_match('/<Message>(.*?)<\/Message>/is', $res['body'], $m)) {
            $errorMsg .= ': ' . trim($m[1]);
        }

        return ['success' => false, 'error' => $errorMsg, 'status' => $res['status']];
    }

    /**
     * Delete remote file from bucket
     */
    public function deleteFile(string $remoteKey): array
    {
        $remoteKey = ltrim($remoteKey, '/');
        $path = '/' . rawurlencode($this->bucket) . '/' . self::encodePath($remoteKey);

        $res = $this->request('DELETE', $path, [], '');
        if ($res['status'] >= 200 && $res['status'] < 300 || $res['status'] === 404 || $res['status'] === 204) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => "Gagal menghapus remote key: HTTP {$res['status']}"];
    }

    /**
     * Download remote file from bucket to local destination
     */
    public function downloadFile(string $remoteKey, string $localDestination): array
    {
        $remoteKey = ltrim($remoteKey, '/');
        $path = '/' . rawurlencode($this->bucket) . '/' . self::encodePath($remoteKey);

        $dir = dirname($localDestination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = @fopen($localDestination, 'w+');
        if (!$fp) {
            return ['success' => false, 'error' => "Tidak dapat membuka destinasi file lokal: {$localDestination}"];
        }

        $payloadHash = hash('sha256', '');
        $headers = $this->buildAuthHeaders('GET', $path, [], $payloadHash, []);
        $url = $this->scheme . '://' . $this->host . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        $exec = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($exec === false || $status < 200 || $status >= 300) {
            @unlink($localDestination);
            return [
                'success' => false,
                'error'   => "Gagal mengunduh file dari cloud (HTTP {$status}): " . ($err ?: 'File tidak ditemukan atau akses ditolak.'),
                'status'  => $status,
            ];
        }

        return ['success' => true, 'path' => $localDestination];
    }

    /**
     * Helper to encode path segments preserving slashes
     */
    private static function encodePath(string $path): string
    {
        $parts = explode('/', $path);
        return implode('/', array_map('rawurlencode', $parts));
    }

    /**
     * Perform AWS SigV4 Signed HTTP Request
     */
    private function request(string $method, string $path, array $queryParams = [], string $payload = '', array $extraHeaders = []): array
    {
        $payloadHash = hash('sha256', $payload);
        $headers = $this->buildAuthHeaders($method, $path, $queryParams, $payloadHash, $extraHeaders);

        $queryString = '';
        if (!empty($queryParams)) {
            ksort($queryParams);
            $queryString = '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        }

        $url = $this->scheme . '://' . $this->host . $path . $queryString;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => $err];
        }

        return ['status' => $status, 'body' => (string)$body];
    }

    /**
     * Perform AWS SigV4 Signed Streaming PUT Request for large files
     */
    private function requestStream(
        string $method,
        string $path,
        array $queryParams,
        string $payloadHash,
        array $extraHeaders,
        $fileHandle,
        int $fileSize
    ): array {
        $headers = $this->buildAuthHeaders($method, $path, $queryParams, $payloadHash, $extraHeaders);

        $url = $this->scheme . '://' . $this->host . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Allow up to 5 min for large backups
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => $err];
        }

        return ['status' => $status, 'body' => (string)$body];
    }

    /**
     * Build AWS Signature Version 4 Headers
     */
    private function buildAuthHeaders(
        string $method,
        string $path,
        array $queryParams,
        string $payloadHash,
        array $extraHeaders = []
    ): array {
        $dateStamp = gmdate('Ymd');
        $amzDate   = gmdate('Ymd\THis\Z');

        // Headers to sign
        $headersToSign = [
            'host'                 => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ];

        foreach ($extraHeaders as $k => $v) {
            $headersToSign[strtolower($k)] = trim($v);
        }

        ksort($headersToSign);

        // Canonical headers & signed headers string
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headersToSign as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
            $signedHeadersList[] = $k;
        }
        $signedHeadersStr = implode(';', $signedHeadersList);

        // Canonical query string
        $canonicalQuery = '';
        if (!empty($queryParams)) {
            ksort($queryParams);
            $canonicalQuery = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        }

        // Canonical Request
        $canonicalRequest = "{$method}\n"
            . "{$path}\n"
            . "{$canonicalQuery}\n"
            . "{$canonicalHeaders}\n"
            . "{$signedHeadersStr}\n"
            . "{$payloadHash}";

        // String to Sign
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n"
            . "{$amzDate}\n"
            . "{$credentialScope}\n"
            . hash('sha256', $canonicalRequest);

        // Calculate Signature
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 "
            . "Credential={$this->accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeadersStr}, "
            . "Signature={$signature}";

        $resultHeaders = [
            'Authorization'        => $authorization,
            'x-amz-date'           => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Host'                 => $this->host,
        ];

        foreach ($extraHeaders as $k => $v) {
            $resultHeaders[$k] = $v;
        }

        return $resultHeaders;
    }
}
