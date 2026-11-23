using System;
using System.IO;
using System.IO.Compression;
using System.Net;
using System.Security.Cryptography;
using System.Text;

internal static class SshClientInstaller
{
    internal const string WinboxVersion = "4.5";
    internal const string WinboxUrl = "https://download.mikrotik.com/routeros/winbox/4.5/WinBox_Windows.zip";
    internal const string WinboxSha256 = "c98c23f4d7e9e3c68264a79967e59ede8defc86e4c7af3c7eae05d071ea867fd";

    internal const string PuttyVersion = "0.85";
    internal const string PuttyUrl = "https://the.earth.li/~sgtatham/putty/0.85/w64/putty.exe";
    internal const string PuttySha256 = "d01fdb5aae8f112526040a39b0bfb9e27d813003178645e65f8d1cfdb2a26c87";

    internal const string PlinkVersion = "0.85";
    internal const string PlinkUrl = "https://the.earth.li/~sgtatham/putty/0.85/w64/plink.exe";
    internal const string PlinkSha256 = "969f36879d5716aa1a9811f43a6a6510e8f08372dbeb9695b810b9c776f39c75";

    internal static string InstallIfMissing(string client, string clientRoot)
    {
        string url;
        string hash;
        if (client == "winbox")
        {
            url = WinboxUrl;
            hash = WinboxSha256;
        }
        else if (client == "putty")
        {
            url = PuttyUrl;
            hash = PuttySha256;
        }
        else if (client == "plink")
        {
            url = PlinkUrl;
            hash = PlinkSha256;
        }
        else
        {
            throw new ArgumentException("Unknown SSH client.", "client");
        }

        return InstallFromUrl(client, url, hash, clientRoot);
    }

    // Kept separate so the build smoke test can exercise downloads against a local fixture server.
    internal static string InstallFromUrl(string client, string url, string expectedSha256, string clientRoot)
    {
        if (client != "winbox" && client != "putty" && client != "plink")
        {
            throw new ArgumentException("Unknown SSH client.", "client");
        }
        Uri source;
        if (!Uri.TryCreate(url, UriKind.Absolute, out source)
            || (source.Scheme != Uri.UriSchemeHttps
                && !(source.Scheme == Uri.UriSchemeHttp
                    && (String.Equals(source.Host, "localhost", StringComparison.OrdinalIgnoreCase)
                        || String.Equals(source.Host, "127.0.0.1", StringComparison.OrdinalIgnoreCase)))))
        {
            throw new InvalidOperationException("SSH client downloads must use HTTPS.");
        }

        string expected = (expectedSha256 ?? String.Empty).Trim().ToLowerInvariant();
        if (expected.Length != 64 || !IsHex(expected))
        {
            throw new InvalidOperationException("The SSH client package checksum is invalid.");
        }

        string temporaryFile = Path.Combine(Path.GetTempPath(), "winbox-glpi-client-" + Guid.NewGuid().ToString("N")
            + (client == "winbox" ? ".zip" : ".exe"));
        try
        {
            // Older .NET Framework versions do not enable TLS 1.2 by default.
            ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072;
            using (TimeoutWebClient downloader = new TimeoutWebClient())
            {
                downloader.Headers[HttpRequestHeader.UserAgent] = "Winbox-GLPI-Setup/1.0";
                downloader.DownloadFile(source, temporaryFile);
            }

            FileInfo package = new FileInfo(temporaryFile);
            if (!package.Exists || package.Length == 0 || package.Length > 25 * 1024 * 1024)
            {
                throw new InvalidOperationException("The downloaded " + ClientName(client) + " package has an unexpected size.");
            }
            VerifySha256(temporaryFile, expected);

            if (client == "putty" || client == "plink")
            {
                string directory = Path.Combine(clientRoot, "PuTTY");
                Directory.CreateDirectory(directory);
                string destination = Path.Combine(directory, client == "plink" ? "plink.exe" : "putty.exe");
                if (File.Exists(destination))
                {
                    throw new IOException("An existing managed " + ClientName(client) + " file was left unchanged.");
                }
                File.Copy(temporaryFile, destination, false);
                return destination;
            }

            return InstallWinboxArchive(temporaryFile, clientRoot);
        }
        finally
        {
            try
            {
                if (File.Exists(temporaryFile)) File.Delete(temporaryFile);
            }
            catch
            {
                // The operating system will clean up the uniquely named temporary package later.
            }
        }
    }

    private static string InstallWinboxArchive(string archivePath, string clientRoot)
    {
        string destination = Path.Combine(clientRoot, "WinBox");
        string staging = Path.Combine(clientRoot, ".winbox-install-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(staging);
        try
        {
            ExtractZipSafely(archivePath, staging);
            string selected = FindFirstExecutable(staging, new string[] { "WinBox.exe", "winbox64.exe", "winbox.exe" });
            if (selected == null)
            {
                throw new InvalidOperationException("The verified MikroTik archive does not contain a Windows WinBox executable.");
            }
            if (Directory.Exists(destination))
            {
                throw new IOException("An existing WinBox install directory was left unchanged.");
            }

            string relativePath = selected.Substring(Path.GetFullPath(staging).TrimEnd(Path.DirectorySeparatorChar).Length)
                .TrimStart(Path.DirectorySeparatorChar);
            Directory.Move(staging, destination);
            return Path.Combine(destination, relativePath);
        }
        catch
        {
            try { if (Directory.Exists(staging)) Directory.Delete(staging, true); }
            catch { /* Preserve the original extraction failure. */ }
            throw;
        }
    }

    private static string FindFirstExecutable(string root, string[] names)
    {
        foreach (string name in names)
        {
            string[] matches = Directory.GetFiles(root, name, SearchOption.AllDirectories);
            if (matches.Length > 0) return matches[0];
        }
        return null;
    }

    private static void ExtractZipSafely(string archivePath, string destinationRoot)
    {
        string root = Path.GetFullPath(destinationRoot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        using (FileStream file = File.OpenRead(archivePath))
        using (ZipArchive archive = new ZipArchive(file, ZipArchiveMode.Read))
        {
            long totalUncompressed = 0;
            foreach (ZipArchiveEntry entry in archive.Entries)
            {
                totalUncompressed += entry.Length;
                if (totalUncompressed > 80 * 1024 * 1024)
                {
                    throw new InvalidOperationException("The client archive expands to an unexpected size.");
                }

                string relative = entry.FullName.Replace('/', Path.DirectorySeparatorChar)
                    .Replace('\\', Path.DirectorySeparatorChar);
                string output = Path.GetFullPath(Path.Combine(root, relative));
                if (!output.StartsWith(root, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException("The client archive contains an unsafe path.");
                }

                if (relative.EndsWith(Path.DirectorySeparatorChar.ToString(), StringComparison.Ordinal))
                {
                    Directory.CreateDirectory(output);
                    continue;
                }

                string directory = Path.GetDirectoryName(output);
                if (!String.IsNullOrEmpty(directory)) Directory.CreateDirectory(directory);
                using (Stream input = entry.Open())
                using (FileStream target = File.Create(output))
                {
                    input.CopyTo(target);
                }
            }
        }
    }

    private static void VerifySha256(string path, string expected)
    {
        using (SHA256 sha256 = SHA256.Create())
        using (FileStream stream = File.OpenRead(path))
        {
            byte[] digest = sha256.ComputeHash(stream);
            StringBuilder actual = new StringBuilder(digest.Length * 2);
            foreach (byte value in digest) actual.Append(value.ToString("x2"));
            if (!String.Equals(actual.ToString(), expected, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("The downloaded client package failed its SHA-256 check. No files were installed.");
            }
        }
    }

    private static bool IsHex(string value)
    {
        foreach (char character in value)
        {
            if (!((character >= '0' && character <= '9') || (character >= 'a' && character <= 'f'))) return false;
        }
        return true;
    }

    private static string ClientName(string client)
    {
        if (client == "winbox") return "WinBox";
        return client == "plink" ? "Plink" : "PuTTY";
    }

    private sealed class TimeoutWebClient : WebClient
    {
        protected override WebRequest GetWebRequest(Uri address)
        {
            WebRequest request = base.GetWebRequest(address);
            request.Timeout = 120000;
            HttpWebRequest httpRequest = request as HttpWebRequest;
            if (httpRequest != null) httpRequest.ReadWriteTimeout = 120000;
            return request;
        }
    }
}
