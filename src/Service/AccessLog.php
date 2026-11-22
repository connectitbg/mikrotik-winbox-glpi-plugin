<?php

namespace GlpiPlugin\Winbox\Service;

final class AccessLog
{
    /**
     * Read recent entries for one network equipment record without exposing
     * other devices' entries to the caller.
     */
    public static function readForDevice(int $item_id, int $limit = 200): array
    {
        if ($item_id < 1 || !defined('GLPI_LOG_DIR')) {
            return [];
        }

        $path = GLPI_LOG_DIR . '/mikrotik_access.log';
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $limit = max(1, min($limit, 500));
        $position = fseek($handle, 0, SEEK_END) === 0 ? ftell($handle) : 0;
        $buffer = '';
        $entries = [];
        $chunk_size = 8192;

        while ($position > 0 && count($entries) < $limit) {
            $read_size = min($chunk_size, $position);
            $position -= $read_size;
            if (fseek($handle, $position) !== 0) {
                break;
            }

            $chunk = fread($handle, $read_size);
            if ($chunk === false) {
                break;
            }

            $lines = explode("\n", $chunk . $buffer);
            $buffer = array_shift($lines) ?? '';
            foreach (array_reverse($lines) as $line) {
                $entry = self::decodeLine($line);
                if ($entry !== null && (int)($entry['data']['device_id'] ?? 0) === $item_id) {
                    $entries[] = $entry;
                    if (count($entries) >= $limit) {
                        break;
                    }
                }
            }
        }

        if ($position === 0 && count($entries) < $limit) {
            $entry = self::decodeLine($buffer);
            if ($entry !== null && (int)($entry['data']['device_id'] ?? 0) === $item_id) {
                $entries[] = $entry;
            }
        }

        fclose($handle);
        return $entries;
    }

    private static function decodeLine(string $line): ?array
    {
        if (!preg_match('/^\[([^\]]+)\]\s+(\{.*\})\s*$/', trim($line), $matches)) {
            return null;
        }

        $data = json_decode($matches[2], true);
        if (!is_array($data)) {
            return null;
        }

        return ['timestamp' => $matches[1], 'data' => $data];
    }

    public static function record(
        int $user_id,
        int $item_id,
        string $event,
        string $result,
        ?string $note = null,
        ?string $target_override = null
    ): void {
        global $DB;

        $user_name = '#' . $user_id;
        if ($user_id > 0) {
            $user = new \User();
            if ($user->getFromDB($user_id)) {
                $user_name = (string)$user->fields['name'];
            }
        }

        $device_name = '#' . $item_id;
        $device = new \NetworkEquipment();
        if ($item_id > 0 && $device->getFromDB($item_id)) {
            $device_name = (string)$device->fields['name'];
        }

        $credential = $item_id > 0 ? Access::credentials($DB, $item_id) : null;
        $entry = [
            'event' => $event,
            'glpi_user' => $user_name,
            'user_id' => $user_id,
            'device' => $device_name,
            'device_id' => $item_id,
            'target' => $target_override ?? ($credential
                ? $credential['ip_address'] . ':' . $credential['port']
                : null),
            'result' => $result,
        ];
        if ($note !== null && $note !== '') {
            $entry['note'] = $note;
        }

        $json = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false || !defined('GLPI_LOG_DIR')) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $json . PHP_EOL;
        @file_put_contents(GLPI_LOG_DIR . '/mikrotik_access.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Temporary, secret-safe PuTTY/KiTTY diagnostics. Remove after the SSH test pass. */
    public static function recordSshTest(int $item_id, string $client, string $stage, array $details = []): void
    {
        if (!defined('GLPI_LOG_DIR') || $item_id < 1 || !in_array($client, ['putty', 'kitty', 'sshdiag'], true)) {
            return;
        }

        $entry = [
            'device_id' => $item_id,
            'client' => $client,
            'stage' => preg_replace('/[^a-z0-9_]/i', '', $stage),
        ];
        foreach (['target', 'username_present', 'password_present', 'password_has_line_break', 'result'] as $key) {
            if (array_key_exists($key, $details) && (is_scalar($details[$key]) || $details[$key] === null)) {
                $entry[$key] = $details[$key];
            }
        }

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        $path = GLPI_LOG_DIR . '/mikrotik_ssh_test.log';
        if (is_file($path) && (int)@filesize($path) > 65536) {
            @file_put_contents($path, '', LOCK_EX);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $json . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
