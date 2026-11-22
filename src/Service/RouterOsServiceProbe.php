<?php

namespace GlpiPlugin\Winbox\Service;

/** TCP/TLS reachability checks for RouterOS IP services. No credentials are sent. */
final class RouterOsServiceProbe
{
    public static function tcp(string $host, int $port, float $timeout = 1.5): bool
    {
        if (!self::validTarget($host, $port)) {
            return false;
        }

        $address = self::socketAddress($host, $port);
        $socket = @stream_socket_client('tcp://' . $address, $error_code, $error_message, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /** Return true only when the endpoint completes a TLS handshake. Certificate trust is left to the browser. */
    public static function tls(string $host, int $port, float $timeout = 2.0): bool
    {
        if (!self::validTarget($host, $port)) {
            return false;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $socket = @stream_socket_client(
            'tls://' . self::socketAddress($host, $port),
            $error_code,
            $error_message,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /** Connect without authenticating and confirm an FTP service greeting if one is returned. */
    public static function ftpGreeting(string $host, int $port, float $timeout = 1.5): string
    {
        if (!self::validTarget($host, $port)) {
            return 'unreachable';
        }

        $socket = @stream_socket_client(
            'tcp://' . self::socketAddress($host, $port),
            $error_code,
            $error_message,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            return 'unreachable';
        }

        stream_set_timeout($socket, (int)$timeout, (int)(($timeout - (int)$timeout) * 1_000_000));
        $greeting = fgets($socket, 512) ?: '';
        fclose($socket);
        return preg_match('/^220(?:[ -])/', $greeting) === 1 ? 'ftp' : ($greeting === '' ? 'open_no_greeting' : 'open_other_service');
    }

    public static function validTarget(string $host, int $port): bool
    {
        return $port >= 1 && $port <= 65535 && (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        );
    }

    private static function socketAddress(string $host, int $port): string
    {
        $host_part = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;
        return $host_part . ':' . $port;
    }
}
