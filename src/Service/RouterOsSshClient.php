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
        $this->host = Access::sshHost($credentials);
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

    /** Read the logged-in user's effective RouterOS group policies without changing router state. */
    public function inspectUserPolicies(): array
    {
        $unknown = array_fill_keys(['ssh', 'read', 'sensitive', 'ftp', 'write'], 'unknown');
        $runtime = null;
        try {
            $runtime = $this->createRuntimeContext();
            $active = self::runProcess(
                $this->sshCommand($runtime['known_hosts'], '/user active print detail'),
                $runtime['environment'],
                '',
                20
            );
            if ($active['exit_code'] !== 0) {
                return [
                    'statuses' => $unknown,
                    'ssh_login' => 'unknown',
                    'message' => 'The SSH session could not read active-user details; policy state cannot be confirmed safely.',
                ];
            }

            $statuses = $unknown;
            $active_group = '';
            foreach (preg_split('/\r?\n/', $active['stdout']) ?: [] as $line) {
                $properties = self::routerOsProperties($line);
                if (($properties['name'] ?? '') === $this->username) {
                    // This is an authenticated read-only SSH command and the
                    // active-user row confirms RouterOS can read the session.
                    $statuses['ssh'] = 'active';
                    $statuses['read'] = 'active';
                    $active_group = (string)($properties['group'] ?? '');
                    break;
                }
            }
            if ($statuses['ssh'] !== 'active') {
                return [
                    'statuses' => $unknown,
                    'ssh_login' => 'unknown',
                    'message' => 'SSH connected, but RouterOS did not return the current active-user row; account policies cannot be confirmed safely.',
                ];
            }
            if ($active_group === '') {
                return [
                    'statuses' => $statuses,
                    'ssh_login' => 'success',
                    'message' => 'SSH and read checks passed, but RouterOS did not identify this session group; remaining policy flags are unknown.',
                ];
            }

            $groups = self::runProcess(
                $this->sshCommand($runtime['known_hosts'], '/user group print detail'),
                $runtime['environment'],
                '',
                20
            );
            if ($groups['exit_code'] !== 0) {
                return [
                    'statuses' => $statuses,
                    'ssh_login' => 'success',
                    'message' => 'SSH and read checks passed, but RouterOS did not allow a read-only policy listing; remaining policy flags are unknown.',
                ];
            }

            foreach (preg_split('/\r?\n/', $groups['stdout']) ?: [] as $line) {
                $properties = self::routerOsProperties($line);
                if (($properties['name'] ?? '') !== $active_group || !isset($properties['policy'])) {
                    continue;
                }
                $policies = array_map('trim', explode(',', (string)$properties['policy']));
                foreach (['ssh', 'read', 'sensitive', 'ftp', 'write'] as $policy) {
                    if (in_array($policy, $policies, true)) {
                        $statuses[$policy] = 'active';
                    } elseif (in_array('!' . $policy, $policies, true)) {
                        $statuses[$policy] = 'inactive';
                    }
                }
                return [
                    'statuses' => $statuses,
                    'ssh_login' => 'success',
                    'message' => 'Policy flags were read from the active RouterOS user group. No policy was changed.',
                ];
            }

            return [
                'statuses' => $statuses,
                'ssh_login' => 'success',
                'message' => 'SSH and read checks passed, but the active group policy was not returned; remaining policy flags are unknown.',
            ];
        } catch (RuntimeException $exception) {
            return [
                'statuses' => $unknown,
                'ssh_login' => 'unknown',
                'message' => self::safePolicyFailureMessage($exception->getMessage()),
            ];
        } finally {
            if (is_array($runtime)) {
                self::removeRuntimeContext($runtime);
            }
        }
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

    /**
     * Check the trusted SSH endpoint and SFTP write path using a random,
     * non-executable temporary file. This never imports RouterOS commands.
     */
    public function diagnoseSensitiveRestoreUpload(): array
    {
        foreach (['/usr/bin/ssh', '/usr/bin/sftp', '/usr/bin/ssh-keyscan'] as $binary) {
            if (!is_executable($binary)) {
                throw new RuntimeException('The GLPI server is missing a required OpenSSH program: ' . basename($binary) . '.');
            }
        }

        $runtime = $this->createRuntimeContext();
        $file_name = 'winbox-glpi-sftp-check-' . bin2hex(random_bytes(8)) . '.tmp';
        $local_file = $runtime['directory'] . DIRECTORY_SEPARATOR . $file_name;
        $probe = "MikroTik Tools for GLPI SFTP restore diagnostic.\n";
        $upload_status = 'failed';
        $upload_message = 'The temporary SFTP upload could not be started.';
        $cleanup_status = 'not_needed';
        $cleanup_message = '';

        try {
            if (file_put_contents($local_file, $probe, LOCK_EX) !== strlen($probe)) {
                throw new RuntimeException('Could not prepare the temporary SFTP diagnostic file.');
            }
            @chmod($local_file, 0600);

            try {
                $upload = self::runProcess(
                    $this->sftpCommand($runtime['known_hosts']),
                    $runtime['environment'],
                    'put ' . $local_file . ' ' . $file_name . "\n",
                    45
                );
                if ($upload['exit_code'] === 0) {
                    $upload_status = 'success';
                    $upload_message = 'RouterOS accepted a temporary SFTP file upload.';
                } else {
                    $upload_message = self::sftpFailureMessage($upload['stdout'] . "\n" . $upload['stderr'], $upload['exit_code']);
                }
            } catch (RuntimeException $exception) {
                $upload_message = self::sftpFailureMessage($exception->getMessage(), null);
            }

            // A failed SFTP put may leave a partial file, so always try to remove
            // the uniquely named probe after an upload attempt.
            try {
                $cleanup = self::runProcess(
                    $this->sftpCommand($runtime['known_hosts']),
                    $runtime['environment'],
                    'rm ' . $file_name . "\n",
                    30
                );
                if ($cleanup['exit_code'] === 0) {
                    $cleanup_status = 'success';
                    $cleanup_message = 'The temporary diagnostic file was removed.';
                } else {
                    $cleanup_status = 'warning';
                    $cleanup_message = 'RouterOS did not confirm removal of the diagnostic file. Check the Files list for ' . $file_name . '.';
                }
            } catch (RuntimeException) {
                $cleanup_status = 'warning';
                $cleanup_message = 'Cleanup could not be confirmed. Check the Files list for ' . $file_name . '.';
            }

            return [
                'fingerprint_status' => 'success',
                'fingerprint_message' => 'The current SSH fingerprint matches the value pinned in GLPI.',
                'upload_status' => $upload_status,
                'upload_message' => $upload_message,
                'cleanup_status' => $cleanup_status,
                'cleanup_message' => $cleanup_message,
            ];
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
            self::requireSftpSuccess($sftp);
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

    private static function sftpFailureMessage(string $output, ?int $exit_code): string
    {
        $text = strtolower($output);
        if (str_contains($text, 'host key verification failed') || str_contains($text, 'remote host identification has changed')) {
            return 'SSH host-key verification failed. Compare the current router fingerprint with the trusted value in GLPI.';
        }
        if (str_contains($text, 'permission denied (') || str_contains($text, 'authentication failed') || str_contains($text, 'no supported authentication methods')) {
            return 'SSH/SFTP authentication was rejected. Check the saved username, password, and SSH authentication policy.';
        }
        if (str_contains($text, 'permission denied') || str_contains($text, 'failure') || str_contains($text, 'couldn') && (str_contains($text, 'open') || str_contains($text, 'write'))) {
            return 'RouterOS refused the file upload. The most likely cause is a missing ftp policy on the GLPI account; also check RouterOS file storage. Restore later requires write policy too.';
        }
        if (str_contains($text, 'subsystem request failed') || str_contains($text, 'sftp server') || str_contains($text, 'subsystem')) {
            return 'SSH was reached, but the SFTP subsystem was unavailable or rejected. Check RouterOS SFTP support and SSH service policy.';
        }
        if (str_contains($text, 'connection timed out') || str_contains($text, 'connection refused') || str_contains($text, 'network is unreachable') || str_contains($text, 'connection closed')) {
            return 'The GLPI server could not complete the SSH/SFTP connection. Check the router SSH host, port, and reachability from the GLPI server.';
        }

        $suffix = $exit_code === null ? '' : ' (SFTP exit code ' . $exit_code . ')';
        return 'SFTP returned an unclassified failure' . $suffix . '. Check the RouterOS ftp policy, available file storage, and SSH/SFTP service.';
    }

    private static function routerOsProperties(string $line): array
    {
        preg_match_all('/(?:^|\s)([A-Za-z0-9_-]+)=(?:"((?:\\\\.|[^"])*)"|([^\s]+))/', $line, $matches, PREG_SET_ORDER);
        $properties = [];
        foreach ($matches as $match) {
            $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $properties[$match[1]] = str_replace(['\\\\', '\\"'], ['\\', '"'], $value);
        }
        return $properties;
    }

    private static function safePolicyFailureMessage(string $message): string
    {
        $text = strtolower($message);
        if (str_contains($text, 'host key') || str_contains($text, 'fingerprint')) {
            return 'SSH fingerprint verification failed. Verify the pinned router fingerprint before diagnosing policies.';
        }
        if (str_contains($text, 'authentication') || str_contains($text, 'permission denied')) {
            return 'SSH authentication or read-only policy inspection failed. Verify the saved credentials and the router account rights.';
        }
        return 'The SSH policy check could not complete. Verify that the SSH endpoint is reachable and its fingerprint is pinned.';
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

    private static function requireSftpSuccess(array $result): void
    {
        if ($result['exit_code'] === 0) {
            return;
        }
        // Classify only known safe error patterns; never display raw SSH/SFTP output.
        throw new RuntimeException(self::sftpFailureMessage($result['stdout'] . "\n" . $result['stderr'], $result['exit_code']));
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
