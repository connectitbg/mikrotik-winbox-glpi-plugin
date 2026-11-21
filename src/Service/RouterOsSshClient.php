<?php

namespace GlpiPlugin\Winbox\Service;

use RuntimeException;

/**
 * SSH/SFTP client for sensitive RouterOS text exports and imports.
 * OpenSSH is used so host keys can be verified against an explicitly trusted pin.
 */
final class RouterOsSshClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $auth_method;
    private array $credentials;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->host = trim((string)($credentials['ip_address'] ?? ''));
        $this->port = (int)($credentials['ssh_port'] ?? 22);
        $this->username = (string)($credentials['username'] ?? '');
        $this->password = (string)($credentials['password'] ?? '');
        $this->auth_method = (string)($credentials['ssh_auth_method'] ?? 'password');

        if (
            $this->host === ''
            || $this->port < 1
            || $this->port > 65535
            || $this->username === ''
            || !in_array($this->auth_method, ['password', 'key_then_password'], true)
        ) {
            throw new RuntimeException('SSH connection settings are incomplete.');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('PHP proc_open must be enabled to run the OpenSSH client.');
        }
    }

    /** Return key types and SHA256 fingerprints offered by the configured SSH endpoint. */
    public function scanHostKeys(): array
    {
        self::validateHost($this->host);
        $output = self::runProcess([
            '/usr/bin/ssh-keyscan',
            '-T', '5',
            '-p', (string)$this->port,
            '-t', 'ed25519,ecdsa,rsa',
            $this->host,
        ], null, '', 8);

        if ($output['exit_code'] !== 0 && trim($output['stdout']) === '') {
            throw new RuntimeException('Could not read the SSH host key. Check that the SSH port is reachable from GLPI.');
        }

        $keys = [];
        foreach (preg_split('/\r?\n/', $output['stdout']) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 3);
            if (count($parts) !== 3 || str_starts_with($parts[0], '#')) {
                continue;
            }
            $raw_key = base64_decode($parts[2], true);
            if ($raw_key === false) {
                continue;
            }
            $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw_key, true)), '=');
            $keys[$fingerprint] = [
                'type' => $parts[1],
                'key' => $parts[2],
                'fingerprint' => $fingerprint,
            ];
        }

        if ($keys === []) {
            throw new RuntimeException('No SSH host key was returned. Check that SSH is enabled on this router and reachable from GLPI.');
        }

        ksort($keys);
        return array_values($keys);
    }

    /** Export all RouterOS text configuration including sensitive values. */
    public function exportSensitiveConfiguration(): string
    {
        $runtime = $this->createRuntimeContext();
        try {
            $result = self::runProcess(
                $this->sshCommand($runtime['known_hosts'], '/export show-sensitive terse'),
                $runtime['environment'],
                '',
                90
            );
            self::requireSuccess($result, 'SSH export failed');

            $configuration = str_replace("\r\n", "\n", $result['stdout']);
            if (trim($configuration) === '' || !preg_match('/^\s*(?:#|\/)/', $configuration)) {
                throw new RuntimeException('RouterOS did not return a text configuration export. Check the account sensitive policy.');
            }

            return rtrim($configuration) . "\n";
        } finally {
            self::removeRuntimeContext($runtime);
        }
    }

    /** Upload a text export through SFTP, dry-run it, then import it over SSH. */
    public function importSensitiveConfiguration(string $configuration): void
    {
        if ($configuration === '' || strlen($configuration) > 5 * 1024 * 1024) {
            throw new RuntimeException('The text backup must be between 1 byte and 5 MiB.');
        }
        if (str_contains($configuration, "\0") || preg_match('//u', $configuration) !== 1) {
            throw new RuntimeException('The text backup must be valid UTF-8 without binary data.');
        }

        $runtime = $this->createRuntimeContext();
        $file_name = 'winbox-glpi-' . bin2hex(random_bytes(8)) . '.rsc';
        $local_file = $runtime['directory'] . DIRECTORY_SEPARATOR . $file_name;
        $remote_created = false;

        try {
            if (file_put_contents($local_file, $configuration, LOCK_EX) !== strlen($configuration)) {
                throw new RuntimeException('Could not prepare the temporary RouterOS text backup.');
            }
            @chmod($local_file, 0600);

            $batch = 'put ' . $local_file . ' ' . $file_name . "\n";
            $sftp = self::runProcess(
                $this->sftpCommand($runtime['known_hosts']),
                $runtime['environment'],
                $batch,
                90
            );
            self::requireSuccess($sftp, 'SFTP upload failed');
            $remote_created = true;

            $this->runRouterCommand($runtime, '/import file-name=' . $file_name . ' verbose=yes dry-run=yes', 'RouterOS dry-run failed');
            $this->runRouterCommand($runtime, '/import file-name=' . $file_name . ' verbose=yes', 'RouterOS import failed');
        } finally {
            if ($remote_created) {
                try {
                    $this->runRouterCommand($runtime, '/file remove [find where name="' . $file_name . '"]', 'Temporary file cleanup failed');
                } catch (RuntimeException) {
                    // Keep the import result; the random, non-auto-importing file can be removed manually.
                }
            }
            self::removeRuntimeContext($runtime);
        }
    }

    private function runRouterCommand(array $runtime, string $command, string $failure_message): string
    {
        $result = self::runProcess(
            $this->sshCommand($runtime['known_hosts'], $command),
            $runtime['environment'],
            '',
            90
        );
        self::requireSuccess($result, $failure_message);
        return $result['stdout'];
    }

    private function createRuntimeContext(): array
    {
        $trusted = json_decode((string)($this->credentials['ssh_host_keys'] ?? ''), true);
        if (!is_array($trusted) || $trusted === []) {
            throw new RuntimeException('Pin the router SSH server fingerprint in GLPI before using text backup or restore.');
        }

        $current = $this->scanHostKeys();
        $trusted_fingerprints = [];
        foreach ($trusted as $key) {
            if (is_array($key) && isset($key['fingerprint'])) {
                $trusted_fingerprints[] = (string)$key['fingerprint'];
            }
        }
        $current_fingerprints = array_column($current, 'fingerprint');
        sort($trusted_fingerprints);
        sort($current_fingerprints);
        if ($trusted_fingerprints !== $current_fingerprints) {
            throw new RuntimeException('The router SSH server fingerprint changed. Verify and pin the current fingerprint in GLPI before continuing.');
        }

        $directory = rtrim(self::tempBase(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'winbox-glpi-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create a secure temporary directory for SSH.');
        }

        $known_hosts = $directory . DIRECTORY_SEPARATOR . 'known_hosts';
        $known_host = $this->port === 22 ? $this->host : '[' . $this->host . ']:' . $this->port;
        $known_hosts_content = '';
        foreach ($current as $key) {
            $known_hosts_content .= $known_host . ' ' . $key['type'] . ' ' . $key['key'] . "\n";
        }
        if (file_put_contents($known_hosts, $known_hosts_content, LOCK_EX) === false) {
            self::removeDirectory($directory);
            throw new RuntimeException('Could not prepare the trusted SSH host key.');
        }
        @chmod($known_hosts, 0600);

        $secret_file = $directory . DIRECTORY_SEPARATOR . 'password';
        if (file_put_contents($secret_file, $this->password, LOCK_EX) === false) {
            self::removeDirectory($directory);
            throw new RuntimeException('Could not prepare the SSH authentication secret.');
        }
        @chmod($secret_file, 0600);

        $askpass = $directory . DIRECTORY_SEPARATOR . 'askpass.sh';
        $askpass_content = "#!/bin/sh\n[ -r \"\$WINBOX_GLPI_ASKPASS_FILE\" ] || exit 1\ncase \"\$1\" in *password:*|*Password:*|*PASSWORD:*) cat \"\$WINBOX_GLPI_ASKPASS_FILE\"; printf '\\n' ;; *) exit 1 ;; esac\n";
        if (file_put_contents($askpass, $askpass_content, LOCK_EX) === false) {
            self::removeDirectory($directory);
            throw new RuntimeException('Could not prepare the SSH authentication helper.');
        }
        @chmod($askpass, 0700);

        $environment = [
            'PATH' => '/usr/bin:/bin',
            'LC_ALL' => 'C',
            'DISPLAY' => 'winbox-glpi:0',
            'SSH_ASKPASS' => $askpass,
            'SSH_ASKPASS_REQUIRE' => 'force',
            'WINBOX_GLPI_ASKPASS_FILE' => $secret_file,
        ];
        $home = '';
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $account = posix_getpwuid(posix_geteuid());
            $home = is_array($account) ? (string)($account['dir'] ?? '') : '';
        }
        if ($home === '') {
            $home = getenv('HOME');
        }
        if (is_string($home) && $home !== '') {
            $environment['HOME'] = $home;
        }
        $agent_socket = getenv('SSH_AUTH_SOCK');
        if (is_string($agent_socket) && $agent_socket !== '') {
            $environment['SSH_AUTH_SOCK'] = $agent_socket;
        }

        return [
            'directory' => $directory,
            'known_hosts' => $known_hosts,
            'environment' => $environment,
        ];
    }

    private static function removeRuntimeContext(array $runtime): void
    {
        if (isset($runtime['directory']) && is_dir($runtime['directory'])) {
            self::removeDirectory($runtime['directory']);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private static function tempBase(): string
    {
        return is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
    }

    private function sshCommand(string $known_hosts, string $remote_command): array
    {
        return array_merge($this->sshCommonOptions($known_hosts), [$remote_command]);
    }

    private function sftpCommand(string $known_hosts): array
    {
        $auth_options = $this->authOptions();
        return array_merge([
            '/usr/bin/sftp',
            '-b', '-',
            '-P', (string)$this->port,
        ], $this->hostKeyOptions($known_hosts), [
            '-o', 'BatchMode=no',
            '-o', 'PubkeyAuthentication=' . $auth_options['pubkey'],
            '-o', 'PreferredAuthentications=' . $auth_options['preferred'],
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'ConnectTimeout=5',
            '-o', 'LogLevel=ERROR',
            '-l', $this->username,
            $this->host,
        ]);
    }

    private function sshCommonOptions(string $known_hosts): array
    {
        $auth_options = $this->authOptions();
        return array_merge([
            '/usr/bin/ssh',
            '-T',
            '-p', (string)$this->port,
        ], $this->hostKeyOptions($known_hosts), [
            '-o', 'BatchMode=no',
            '-o', 'PubkeyAuthentication=' . $auth_options['pubkey'],
            '-o', 'PreferredAuthentications=' . $auth_options['preferred'],
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=10',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'LogLevel=ERROR',
            '-l', $this->username,
            $this->host,
        ]);
    }

    private function authOptions(): array
    {
        if ($this->auth_method === 'key_then_password') {
            return [
                'pubkey' => 'yes',
                'preferred' => 'publickey,password,keyboard-interactive',
            ];
        }

        return [
            'pubkey' => 'no',
            'preferred' => 'password,keyboard-interactive',
        ];
    }

    private static function hostKeyOptions(string $known_hosts): array
    {
        return [
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $known_hosts,
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'UpdateHostKeys=no',
        ];
    }

    private static function requireSuccess(array $result, string $context): void
    {
        if ($result['exit_code'] === 0) {
            return;
        }
        // RouterOS can echo the failing script line, which may contain passwords.
        // Keep command output out of GLPI messages and logs.
        throw new RuntimeException($context . '. Check RouterOS SSH/SFTP access policies and connectivity.');
    }

    private static function validateHost(string $host): void
    {
        if (
            filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new RuntimeException('The SSH host must be a valid IP address or hostname.');
        }
    }

    private static function runProcess(array $command, ?array $environment, string $stdin, int $timeout): array
    {
        $stdout_file = tempnam(self::tempBase(), 'wbssh_out_');
        $stderr_file = tempnam(self::tempBase(), 'wbssh_err_');
        if ($stdout_file === false || $stderr_file === false) {
            if (is_string($stdout_file)) @unlink($stdout_file);
            if (is_string($stderr_file)) @unlink($stderr_file);
            throw new RuntimeException('Could not create temporary SSH output files.');
        }
        @chmod($stdout_file, 0600);
        @chmod($stderr_file, 0600);

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdout_file, 'w'],
                2 => ['file', $stderr_file, 'w'],
            ],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            @unlink($stdout_file);
            @unlink($stderr_file);
            throw new RuntimeException('Could not start the OpenSSH client.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            if ($stdin !== '') {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);
        }

        $started = microtime(true);
        $observed_exit = null;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $observed_exit = (int)$status['exitcode'];
                break;
            }
            if (microtime(true) - $started > $timeout) {
                proc_terminate($process, 15);
                usleep(250000);
                proc_terminate($process, 9);
                break;
            }
            usleep(100000);
        } while (true);

        $close_exit = proc_close($process);
        $exit_code = $observed_exit !== null && $observed_exit >= 0 ? $observed_exit : $close_exit;
        $stdout = (string)@file_get_contents($stdout_file);
        $stderr = (string)@file_get_contents($stderr_file);
        @unlink($stdout_file);
        @unlink($stderr_file);

        if (strlen($stdout) > 5 * 1024 * 1024 || strlen($stderr) > 512 * 1024) {
            throw new RuntimeException('The OpenSSH client returned an unexpectedly large response.');
        }
        if ($observed_exit === null) {
            throw new RuntimeException('The OpenSSH operation timed out.');
        }

        return ['exit_code' => $exit_code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
