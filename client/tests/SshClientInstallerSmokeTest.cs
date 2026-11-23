using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Threading;

internal static class SshClientInstallerSmokeTest
{
    private static int Main()
    {
        string testRoot = Path.Combine(Path.GetTempPath(), "WinboxGlpiInstallerTest-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(testRoot);
        try
        {
            byte[] puttyFixture = Encoding.UTF8.GetBytes("mock PuTTY executable fixture");
            byte[] plinkFixture = Encoding.UTF8.GetBytes("mock Plink executable fixture");
            byte[] winboxFixture = CreateWinboxArchive(testRoot);
            Dictionary<string, byte[]> files = new Dictionary<string, byte[]>(StringComparer.OrdinalIgnoreCase);
            files["/putty.exe"] = puttyFixture;
            files["/plink.exe"] = plinkFixture;
            files["/winbox.zip"] = winboxFixture;
            files["/bad-archive.zip"] = CreateZipSlipArchive();

            using (FixtureServer server = new FixtureServer(files))
            {
                string clientRoot = Path.Combine(testRoot, "clients");
                string puttyPath = SshClientInstaller.InstallFromUrl(
                    "putty", server.Url + "putty.exe", Sha256(puttyFixture), clientRoot);
                Assert(File.Exists(puttyPath), "PuTTY executable was installed.");
                Assert(BytesEqual(puttyFixture, File.ReadAllBytes(puttyPath)), "PuTTY fixture bytes were preserved.");
                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("putty", server.Url + "putty.exe", Sha256(puttyFixture), clientRoot);
                }, "An existing PuTTY file was not overwritten.");
                Assert(BytesEqual(puttyFixture, File.ReadAllBytes(puttyPath)), "The existing PuTTY bytes remain unchanged.");

                string plinkPath = SshClientInstaller.InstallFromUrl(
                    "plink", server.Url + "plink.exe", Sha256(plinkFixture), clientRoot);
                Assert(File.Exists(plinkPath), "Plink diagnostic executable was installed.");
                Assert(BytesEqual(plinkFixture, File.ReadAllBytes(plinkPath)), "Plink fixture bytes were preserved.");

                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("kitty", server.Url + "unused.zip", Sha256(puttyFixture), clientRoot);
                }, "KiTTY downloads and installs are no longer supported.");

                string winboxPath = SshClientInstaller.InstallFromUrl(
                    "winbox", server.Url + "winbox.zip", Sha256(winboxFixture), clientRoot);
                Assert(File.Exists(winboxPath), "WinBox executable was extracted into the managed client folder.");
                Assert(BytesEqual(Encoding.UTF8.GetBytes("mock WinBox executable"), File.ReadAllBytes(winboxPath)),
                    "The WinBox executable bytes were preserved.");
                Assert(File.Exists(Path.Combine(clientRoot, "WinBox", "platform", "QtCore.dll")),
                    "WinBox runtime files were preserved with the executable.");
                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("winbox", server.Url + "winbox.zip", Sha256(winboxFixture), clientRoot);
                }, "An existing WinBox managed installation was not replaced.");
                Assert(BytesEqual(Encoding.UTF8.GetBytes("mock WinBox executable"), File.ReadAllBytes(winboxPath)),
                    "The existing WinBox executable remains unchanged.");

                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("putty", server.Url + "putty.exe", new String('0', 64),
                        Path.Combine(testRoot, "bad-checksum"));
                }, "A checksum mismatch was rejected.");

                string slipRoot = Path.Combine(testRoot, "zip-slip");
                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("winbox", server.Url + "bad-archive.zip", Sha256(files["/bad-archive.zip"]), slipRoot);
                }, "A ZIP path traversal entry was rejected.");
                Assert(!File.Exists(Path.Combine(testRoot, "escape.txt")), "A ZIP traversal did not write outside its install directory.");

                ExpectFailure(delegate
                {
                    SshClientInstaller.InstallFromUrl("putty", "http://example.com/putty.exe", Sha256(puttyFixture),
                        Path.Combine(testRoot, "external-http"));
                }, "External HTTP downloads were rejected.");
            }

            Assert(SshDiagnosticClassifier.Classify("Access granted", 0, false) == "authentication_accepted",
                "An accepted SSH authentication is classified separately from launch success.");
            Assert(SshDiagnosticClassifier.Classify("Access denied", 1, false) == "authentication_rejected",
                "A RouterOS authentication rejection is classified clearly.");
            Assert(SshDiagnosticClassifier.Classify("Host key is not cached", 1, false) == "host_key_not_trusted",
                "An untrusted router host key is classified separately.");
            Assert(SshDiagnosticClassifier.Classify("Network error: Connection refused", 1, false) == "connection_failed",
                "An SSH connection failure is classified separately.");
            Assert(SshDiagnosticClassifier.Classify("Access granted", 1, false) == "authentication_accepted_command_failed",
                "An accepted login with a failed read-only command remains distinguishable.");
            Assert(SshDiagnosticClassifier.Classify(String.Empty, null, true) == "timeout",
                "An SSH probe timeout is classified clearly.");
            string handshake = SshDiagnosticClassifier.DescribeHandshake(
                "Connected to 192.0.2.10 (port 22)\nHost key fingerprint is:\n  ssh-rsa 1024 SHA256:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd\nAuthenticating with password.\nAccess denied\nUsing HMAC-SHA-1");
            Assert(handshake.Contains("server_fingerprint=SHA256:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd")
                && handshake.Contains("server_key_type=ssh-rsa") && handshake.Contains("password_auth_attempt=seen")
                && handshake.Contains("auth_rejection=seen") && handshake.Contains("hmac_sha1_evidence=seen")
                && handshake.Contains("tcp_connection=yes"),
                "The temporary log extracts safe SSH fingerprint and authentication facts.");
            Assert(!handshake.Contains("192.0.2.10") && !handshake.Contains("Authenticating with password")
                && !handshake.Contains("Access denied"),
                "The handshake summary omits endpoint and raw SSH transcript text.");

            string unconfirmedTrust = SshDiagnosticClassifier.DescribeHandshake(
                "Connected to 192.0.2.10 (port 22)\nHost key fingerprint is:\n  ssh-ed25519 255 SHA256:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd\nAuthenticating with password.\nAccess denied");
            Assert(unconfirmedTrust.Contains("hostkey_trust=unknown")
                && unconfirmedTrust.Contains("hmac_sha1_evidence=not_reported")
                && unconfirmedTrust.Contains("username_sent=not_reported"),
                "Missing verbose transcript evidence is logged as unknown, not as a negative result.");
            string rejectedHostKey = SshDiagnosticClassifier.DescribeHandshake(
                "The host key is not cached for this server. Cannot confirm a host key in batch mode.");
            Assert(rejectedHostKey.Contains("hostkey_trust=not_trusted")
                && rejectedHostKey.Contains("tcp_connection=unknown"),
                "Explicit host-key trust failures are distinguished from missing transcript details.");

            Console.WriteLine("PASS: WinBox, PuTTY, Plink installer checks plus SSH result classification.");
            return 0;
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine("FAIL: " + exception.ToString());
            return 1;
        }
        finally
        {
            try { Directory.Delete(testRoot, true); }
            catch { Console.Error.WriteLine("Note: could not remove the isolated installer smoke-test folder."); }
        }
    }

    private static byte[] CreateWinboxArchive(string root)
    {
        string path = Path.Combine(root, "winbox-fixture.zip");
        using (FileStream file = File.Create(path))
        using (ZipArchive archive = new ZipArchive(file, ZipArchiveMode.Create))
        {
            using (Stream entry = archive.CreateEntry("WinBox.exe").Open())
            {
                byte[] bytes = Encoding.UTF8.GetBytes("mock WinBox executable");
                entry.Write(bytes, 0, bytes.Length);
            }
            using (Stream entry = archive.CreateEntry("platform/QtCore.dll").Open())
            {
                byte[] bytes = Encoding.UTF8.GetBytes("mock dependency");
                entry.Write(bytes, 0, bytes.Length);
            }
        }
        return File.ReadAllBytes(path);
    }

    private static byte[] CreateZipSlipArchive()
    {
        using (MemoryStream stream = new MemoryStream())
        {
            using (ZipArchive archive = new ZipArchive(stream, ZipArchiveMode.Create, true))
            {
                using (Stream entry = archive.CreateEntry("../escape.txt").Open())
                {
                    byte[] bytes = Encoding.UTF8.GetBytes("must not be extracted");
                    entry.Write(bytes, 0, bytes.Length);
                }
                using (Stream entry = archive.CreateEntry("WinBox.exe").Open())
                {
                    byte[] bytes = Encoding.UTF8.GetBytes("fake exe");
                    entry.Write(bytes, 0, bytes.Length);
                }
            }
            return stream.ToArray();
        }
    }

    private static string Sha256(byte[] bytes)
    {
        using (SHA256 hash = SHA256.Create())
        {
            StringBuilder result = new StringBuilder();
            foreach (byte value in hash.ComputeHash(bytes)) result.Append(value.ToString("x2"));
            return result.ToString();
        }
    }

    private static bool BytesEqual(byte[] left, byte[] right)
    {
        if (left.Length != right.Length) return false;
        for (int i = 0; i < left.Length; i++) if (left[i] != right[i]) return false;
        return true;
    }

    private static void ExpectFailure(Action action, string message)
    {
        try { action(); }
        catch { Console.WriteLine("PASS: " + message); return; }
        throw new InvalidOperationException(message + " (no exception was raised)");
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
        Console.WriteLine("PASS: " + message);
    }

    private sealed class FixtureServer : IDisposable
    {
        private readonly TcpListener listener;
        private readonly Dictionary<string, byte[]> files;
        private readonly Thread thread;
        private volatile bool isRunning = true;
        internal readonly string Url;

        internal FixtureServer(Dictionary<string, byte[]> fixtureFiles)
        {
            files = fixtureFiles;
            TcpListener probe = new TcpListener(IPAddress.Loopback, 0);
            probe.Start();
            int port = ((IPEndPoint)probe.LocalEndpoint).Port;
            probe.Stop();
            Url = "http://127.0.0.1:" + port + "/";
            listener = new TcpListener(IPAddress.Loopback, port);
            listener.Start();
            thread = new Thread(Serve);
            thread.IsBackground = true;
            thread.Start();
        }

        private void Serve()
        {
            while (isRunning)
            {
                try
                {
                    TcpClient client = listener.AcceptTcpClient();
                    using (client)
                    using (NetworkStream network = client.GetStream())
                    using (StreamReader reader = new StreamReader(network, Encoding.ASCII, false, 1024, true))
                    {
                        string requestLine = reader.ReadLine() ?? String.Empty;
                        string header;
                        while (!String.IsNullOrEmpty(header = reader.ReadLine())) { }
                        string[] requestParts = requestLine.Split(' ');
                        string requestPath = requestParts.Length > 1 ? requestParts[1] : String.Empty;
                    byte[] payload;
                        if (!files.TryGetValue(requestPath, out payload))
                    {
                            byte[] notFound = Encoding.ASCII.GetBytes("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                            network.Write(notFound, 0, notFound.Length);
                        continue;
                    }
                        byte[] responseHeader = Encoding.ASCII.GetBytes("HTTP/1.1 200 OK\r\nContent-Length: " + payload.Length
                            + "\r\nConnection: close\r\n\r\n");
                        network.Write(responseHeader, 0, responseHeader.Length);
                        network.Write(payload, 0, payload.Length);
                        network.Flush();
                    }
                }
                catch (SocketException) { return; }
                catch (ObjectDisposedException) { return; }
            }
        }

        public void Dispose()
        {
            isRunning = false;
            listener.Stop();
            if (thread.IsAlive) thread.Join(1000);
        }
    }
}
