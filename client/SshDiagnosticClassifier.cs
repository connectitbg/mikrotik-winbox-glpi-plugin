using System;
using System.Text.RegularExpressions;

internal static class SshDiagnosticClassifier
{
    internal static string DescribeHandshake(string transcript)
    {
        string text = transcript ?? String.Empty;
        Match fingerprint = Regex.Match(text, @"SHA256:[A-Za-z0-9+/]{20,}={0,2}", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        Match fingerprintLine = Regex.Match(text,
            @"(?im)^\s*(?<type>ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp(?:256|384|521))\s+(?<bits>\d+)\s+(?<fingerprint>SHA256:[A-Za-z0-9+/]{20,}={0,2})\s*$",
            RegexOptions.CultureInvariant);

        string fingerprintValue = fingerprint.Success ? fingerprint.Value : "not_reported";
        string keyType = fingerprintLine.Success ? fingerprintLine.Groups["type"].Value : "unknown";
        string keyBits = fingerprintLine.Success ? fingerprintLine.Groups["bits"].Value : "unknown";
        bool passwordAttempt = Regex.IsMatch(text, @"(?i)authenticating with password|password authentication", RegexOptions.CultureInvariant);
        bool usernameSent = text.IndexOf("Sent username", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("Using username", StringComparison.OrdinalIgnoreCase) >= 0;
        bool connected = text.IndexOf("Connected to", StringComparison.OrdinalIgnoreCase) >= 0;
        bool hostKeyKnown = text.IndexOf("known and matches cached key", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("host key is known", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("host key is cached for this server", StringComparison.OrdinalIgnoreCase) >= 0;
        bool hostKeyRejected = text.IndexOf("host key is not cached", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("host key does not match", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("potential security breach", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("cannot confirm a host key", StringComparison.OrdinalIgnoreCase) >= 0;
        bool rejected = text.IndexOf("Access denied", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("Authentication failed", StringComparison.OrdinalIgnoreCase) >= 0;
        bool connectionFailed = Regex.IsMatch(text,
            @"(?i)network error|unable to open connection|connection refused|connection timed out",
            RegexOptions.CultureInvariant);
        bool sha1Mac = Regex.IsMatch(text, @"(?i)hmac[\s_-]*sha[\s_-]*1(?:[\s_-]*96)?\b", RegexOptions.CultureInvariant);

        string tcpConnection = connected ? "yes" : (connectionFailed ? "no" : "unknown");
        string hostKeyTrust = hostKeyKnown ? "matched" : (hostKeyRejected ? "not_trusted" : "unknown");
        return "tcp_connection=" + tcpConnection
            + " hostkey_trust=" + hostKeyTrust
            + " server_key_type=" + keyType
            + " server_key_bits=" + keyBits
            + " server_fingerprint=" + fingerprintValue
            + " password_auth_attempt=" + (passwordAttempt ? "seen" : "not_reported")
            + " username_sent=" + (usernameSent ? "seen" : "not_reported")
            + " auth_rejection=" + (rejected ? "seen" : "not_reported")
            + " hmac_sha1_evidence=" + (sha1Mac ? "seen" : "not_reported")
            + " mac_algorithms=" + FindSafeMacAlgorithms(text);
    }

    internal static string Classify(string transcript, int? exitCode, bool timedOut)
    {
        string text = transcript ?? String.Empty;
        if (timedOut) return "timeout";
        if (text.IndexOf("host key is not cached", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("host key does not match", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("potential security breach", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("host key verification failed", StringComparison.OrdinalIgnoreCase) >= 0)
        {
            return "host_key_not_trusted";
        }
        if (text.IndexOf("access granted", StringComparison.OrdinalIgnoreCase) >= 0)
        {
            return exitCode == 0 ? "authentication_accepted" : "authentication_accepted_command_failed";
        }
        if (text.IndexOf("access denied", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("authentication failed", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("no supported authentication methods", StringComparison.OrdinalIgnoreCase) >= 0)
        {
            return "authentication_rejected";
        }
        if (text.IndexOf("network error", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("unable to open connection", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("connection refused", StringComparison.OrdinalIgnoreCase) >= 0
            || text.IndexOf("connection timed out", StringComparison.OrdinalIgnoreCase) >= 0)
        {
            return "connection_failed";
        }
        if (exitCode == 0 && Regex.IsMatch(text, @"(?im)^\s*name\s*:", RegexOptions.CultureInvariant))
        {
            return "authentication_accepted";
        }
        return "inconclusive";
    }

    private static string FindSafeMacAlgorithms(string text)
    {
        string[] supported = new string[]
        {
            "hmac-sha1-96", "hmac-sha1", "hmac-sha2-256-etm@openssh.com",
            "hmac-sha2-512-etm@openssh.com", "hmac-sha2-256", "hmac-sha2-512",
            "umac-64-etm@openssh.com", "umac-128-etm@openssh.com", "umac-64@openssh.com", "umac-128@openssh.com"
        };
        System.Collections.Generic.List<string> found = new System.Collections.Generic.List<string>();
        foreach (string algorithm in supported)
        {
            string pattern = Regex.Escape(algorithm).Replace("\\-", "[-_ ]?");
            if (Regex.IsMatch(text, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant))
            {
                found.Add(algorithm);
            }
        }
        return found.Count == 0 ? "not_reported" : String.Join(",", found.ToArray());
    }
}
