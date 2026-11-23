<?php

namespace GlpiPlugin\Winbox\Service;

use RuntimeException;

/**
 * Minimal RouterOS v7 REST client. TLS verification is intentionally required.
 */
final class RouterOsRestClient
{
    private string $base_url;
    private string $connect_host;
    private string $username;
    private string $password;
    private int $port;

    public function __construct(array $credentials)
    {
        $this->connect_host = (string)($credentials['ip_address'] ?? '');
        $tls_host = trim((string)($credentials['api_host'] ?? ''));
        if ($tls_host === '') {
            $tls_host = $this->connect_host;
        }
        $this->port = (int)($credentials['api_port'] ?? 443);
        $this->username = (string)($credentials['username'] ?? '');
        $this->password = Access::decryptPassword((string)($credentials['password'] ?? ''));

        if (
            $this->connect_host === ''
            || $tls_host === ''
            || $this->port < 1
            || $this->port > 65535
            || $this->username === ''
        ) {
            throw new RuntimeException('RouterOS REST API connection settings are incomplete.');
        }

        $url_host = filter_var($tls_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $tls_host . ']'
            : $tls_host;
        $this->base_url = 'https://' . $url_host . ':' . $this->port . '/rest';
    }

    public function exportConfiguration(): string
    {
        // Omitting show-sensitive keeps passwords and keys masked by RouterOS.
        $response = $this->request('POST', '/export', ['compact' => '']);
        $lines = [];
        $records = is_array($response) && array_is_list($response) ? $response : [$response];
        foreach ($records as $record) {
            if (is_string($record)) {
                $lines[] = $record;
            } elseif (is_array($record)) {
                foreach (['ret', 'message'] as $field) {
                    if (isset($record[$field]) && is_string($record[$field])) {
                        $lines[] = $record[$field];
                        break;
                    }
                }
            }
        }

        $export = implode("\n", $lines);
        if (trim($export) === '') {
            throw new RuntimeException('RouterOS returned an empty configuration export.');
        }

        return rtrim(str_replace("\r\n", "\n", $export)) . "\n";
    }

    public function importConfiguration(string $configuration): void
    {
        if (strlen($configuration) < 1 || strlen($configuration) > 60000) {
            throw new RuntimeException('The .rsc file must be between 1 byte and 60,000 bytes.');
        }
        if (str_contains($configuration, "\0") || preg_match('//u', $configuration) !== 1) {
            throw new RuntimeException('The .rsc file must be valid UTF-8 text.');
        }

        $file_name = 'winbox-glpi-' . bin2hex(random_bytes(8)) . '.rsc';
        $file_id = null;

        try {
            $created = $this->request('PUT', '/file', [
                'name' => $file_name,
                'type' => 'file',
            ]);
            $file_id = is_array($created) ? (string)($created['.id'] ?? '') : '';
            if (!preg_match('/^\*[A-Za-z0-9]+$/', $file_id)) {
                throw new RuntimeException('RouterOS did not create a temporary import file.');
            }

            $file_path = '/file/' . rawurlencode($file_id);
            $this->request('PATCH', $file_path, ['contents' => $configuration]);

            // RouterOS v7.16+ supports import dry-run in verbose mode.
            $this->request('POST', '/import', [
                'file-name' => $file_name,
                'verbose' => 'yes',
                'dry-run' => 'yes',
            ]);

            $this->request('POST', '/import', [
                'file-name' => $file_name,
                'verbose' => 'yes',
            ]);
        } finally {
            if (is_string($file_id) && preg_match('/^\*[A-Za-z0-9]+$/', $file_id)) {
                try {
                    $this->request('DELETE', '/file/' . rawurlencode($file_id));
                } catch (RuntimeException) {
                    // Do not hide the original import result if temporary-file cleanup fails.
                }
            }
        }
    }

    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for RouterOS configuration transfer.');
        }

        $handle = curl_init($this->base_url . $path);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the RouterOS REST API request.');
        }

        $headers = ['Accept: application/json'];
        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                curl_close($handle);
                throw new RuntimeException('Unable to encode the RouterOS REST API request.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        // Keep the TLS hostname from the certificate while connecting to the
        // device's configured management address when a separate API hostname is used.
        $tls_host = parse_url($this->base_url, PHP_URL_HOST);
        if (
            is_string($tls_host)
            && strcasecmp($tls_host, $this->connect_host) !== 0
            && filter_var($this->connect_host, FILTER_VALIDATE_IP) !== false
            && defined('CURLOPT_RESOLVE')
        ) {
            $resolve_ip = filter_var($this->connect_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $this->connect_host . ']'
                : $this->connect_host;
            $options[CURLOPT_RESOLVE] = [$tls_host . ':' . $this->port . ':' . $resolve_ip];
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curl_error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            $reason = $curl_error !== '' ? ' (' . $curl_error . ')' : '';
            throw new RuntimeException('RouterOS REST API connection failed' . $reason . '.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('RouterOS REST API returned HTTP ' . $status . '. Check its HTTPS service, certificate, and account permissions.');
        }
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('RouterOS REST API returned an unexpected response.');
        }

        return $decoded;
    }
}
