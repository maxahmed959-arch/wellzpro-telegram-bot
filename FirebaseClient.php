<?php

declare(strict_types=1);

namespace Wellz;

/**
 * عميل Firebase Realtime Database — قراءة/كتابة التراخيص.
 */
final class FirebaseClient
{
    private ?array $serviceAccount = null;

    private string $tokenCacheFile;

    public function __construct(
        private array $config,
        private string $dataDir,
    ) {
        $this->tokenCacheFile = $dataDir.'/firebase_token.json';
    }

    public function isConfigured(): bool
    {
        return $this->loadServiceAccount() !== null;
    }

    /** @return array<string, mixed>|null */
    public function loadServiceAccount(): ?array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }
        $json = trim((string) ($this->config['firebase_service_account_json'] ?? ''));
        if ($json === '') {
            $file = (string) ($this->config['firebase_service_account_file'] ?? '');
            if ($file !== '' && is_file($file)) {
                $json = (string) file_get_contents($file);
            }
        }
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        $this->serviceAccount = is_array($data) && isset($data['client_email'], $data['private_key'])
            ? $data
            : null;

        return $this->serviceAccount;
    }

    public function accessToken(): ?string
    {
        if (is_file($this->tokenCacheFile)) {
            $cached = json_decode((string) file_get_contents($this->tokenCacheFile), true);
            if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > time() + 60) {
                return (string) $cached['token'];
            }
        }
        $sa = $this->loadServiceAccount();
        if ($sa === null) {
            return null;
        }
        $now = time();
        $tokenUri = (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $signingInput = $this->base64UrlEncode((string) json_encode($header))
            .'.'.$this->base64UrlEncode((string) json_encode($claims));
        $signature = '';
        if (! openssl_sign($signingInput, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt = $signingInput.'.'.$this->base64UrlEncode($signature);
        $response = $this->httpPostForm($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        if (! is_array($response) || ! isset($response['access_token'])) {
            return null;
        }
        $token = (string) $response['access_token'];
        file_put_contents($this->tokenCacheFile, json_encode([
            'token' => $token,
            'expires_at' => $now + (int) ($response['expires_in'] ?? 3600),
        ]));

        return $token;
    }

    public function licenseUrl(string $code): string
    {
        $base = rtrim((string) ($this->config['firebase_database_url'] ?? ''), '/');
        $path = trim((string) ($this->config['firebase_licenses_path'] ?? 'licenses'), '/');

        return $base.'/'.$path.'/'.rawurlencode(strtoupper($code)).'.json';
    }

    public function licensesCollectionUrl(): string
    {
        $base = rtrim((string) ($this->config['firebase_database_url'] ?? ''), '/');
        $path = trim((string) ($this->config['firebase_licenses_path'] ?? 'licenses'), '/');

        return $base.'/'.$path.'.json';
    }

    public function putLicense(string $code, array $payload): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $url = $this->licenseUrl($code).'?access_token='.urlencode($token);

        return $this->request('PUT', $url, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function patchLicense(string $code, array $payload): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $url = $this->licenseUrl($code).'?access_token='.urlencode($token);

        return $this->request('PATCH', $url, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function deleteLicense(string $code): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $url = $this->licenseUrl($code).'?access_token='.urlencode($token);

        return $this->request('DELETE', $url);
    }

    public function licenseExists(string $code): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $url = $this->licenseUrl($code).'?shallow=true&access_token='.urlencode($token);
        $body = $this->requestBody('GET', $url);

        return $body !== null && trim($body) !== 'null' && trim($body) !== '';
    }

    /** @return array<string, mixed>|null */
    public function getLicense(string $code): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        $url = $this->licenseUrl($code).'?access_token='.urlencode($token);
        $body = $this->requestBody('GET', $url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    /**
     * جلب كل التراخيص من Firebase.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllLicenses(): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return [];
        }
        $url = $this->licensesCollectionUrl().'?access_token='.urlencode($token);
        $body = $this->requestBody('GET', $url);
        if ($body === null) {
            return [];
        }
        $value = json_decode($body, true);
        if (! is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $key => $data) {
            if (! is_array($data)) {
                continue;
            }
            $rows[] = LicenseManager::normalizeRow((string) $key, $data);
        }
        usort($rows, fn ($a, $b) => strcmp($b['created_at_raw'] ?? '', $a['created_at_raw'] ?? ''));

        return $rows;
    }

    private function request(string $method, string $url, ?string $body = null): bool
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    private function requestBody(string $method, string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        return (string) $body;
    }

    /** @param array<string, string> $fields */
    private function httpPostForm(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body === false) {
            return null;
        }
        $json = json_decode((string) $body, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
