using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Reflection;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("Winbox - GLPI")]
[assembly: AssemblyDescription("Winbox - GLPI client installer and protocol handler")]
[assembly: AssemblyCompany("Winbox - GLPI")]
[assembly: AssemblyProduct("Winbox - GLPI")]
[assembly: AssemblyVersion("1.0.0.0")]
[assembly: AssemblyFileVersion("1.0.0.0")]

internal static class Program
{
    // GLPI replaces this fixed-width UTF-16 marker with the current server URL when serving the EXE.
    private static readonly string ServerBaseUrl = "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~".TrimEnd('\0');
    private const string ProductName = "Winbox - GLPI";
    private const string InstallFolderName = "Winbox-GLPI";

    [STAThread]
    private static int Main(string[] args)
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);

        if (args.Length == 1 && args[0] == "--self-test")
        {
            return IsConfiguredServerUrl() ? 0 : 2;
        }

        try
        {
            if (args.Length == 0)
            {
                InstallOrRepair();
            }
            else
            {
                LaunchFromProtocol(args[0]);
            }
            return 0;
        }
        catch (Exception exception)
        {
            string message = Sanitize(exception.Message);
            WriteLog("Operation failed: " + message);
            MessageBox.Show(
                message,
                ProductName,
                MessageBoxButtons.OK,
                MessageBoxIcon.Error
            );
            return 1;
        }
    }

    private static void InstallOrRepair()
    {
        Uri baseUri;
        if (!Uri.TryCreate(ServerBaseUrl, UriKind.Absolute, out baseUri)
            || (baseUri.Scheme != Uri.UriSchemeHttp && baseUri.Scheme != Uri.UriSchemeHttps))
        {
            throw new InvalidOperationException(
                "The installer does not have a valid GLPI server address. Download the current file from the Winbox - GLPI setup page."
            );
        }

        string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (String.IsNullOrEmpty(localAppData))
        {
            throw new InvalidOperationException("Windows did not provide a local application data folder for the current user.");
        }

        string installDirectory = Path.Combine(localAppData, InstallFolderName);
        string installedExe = Path.Combine(installDirectory, "Winbox-GLPI.exe");
        Directory.CreateDirectory(installDirectory);
        WriteLog("Installer started.");

        string runningExe = Path.GetFullPath(Assembly.GetExecutingAssembly().Location);
        if (!String.Equals(runningExe, Path.GetFullPath(installedExe), StringComparison.OrdinalIgnoreCase))
        {
            File.Copy(runningExe, installedExe, true);
        }

        RegisterProtocol("mikrotik", "URL:MikroTik Winbox Protocol", installedExe);
        RegisterProtocol("mikrotik-putty", "URL:MikroTik SSH via PuTTY Protocol", installedExe);
        RegisterProtocol("mikrotik-kitty", "URL:MikroTik SSH via KiTTY Protocol", installedExe);
        WriteLog("Installer completed; per-user Winbox, PuTTY SSH, and KiTTY SSH protocol handlers are registered.");

        string httpWarning = baseUri.Scheme == Uri.UriSchemeHttp
            ? "\n\nThe test GLPI server uses HTTP. Use test credentials only."
            : String.Empty;
        MessageBox.Show(
            "Setup is complete for the current Windows user.\n\n"
            + "Open a network equipment record in GLPI and choose Open in Winbox, Open SSH in PuTTY, or Open SSH in KiTTY.\n"
            + "Install PuTTY or KiTTY separately if you want to use those SSH buttons.\n"
            + "The log is saved to:\n" + Path.Combine(installDirectory, "winbox-glpi-launch.log")
            + httpWarning,
            ProductName,
            MessageBoxButtons.OK,
            MessageBoxIcon.Information
        );
    }

    private static void RegisterProtocol(string protocol, string description, string installedExe)
    {
        string command = "\"" + installedExe + "\" \"%1\"";
        using (RegistryKey protocolKey = Registry.CurrentUser.CreateSubKey(@"Software\Classes\" + protocol))
        {
            if (protocolKey == null)
            {
                throw new InvalidOperationException("Windows could not register the " + protocol + " protocol for this user.");
            }
            protocolKey.SetValue("", description, RegistryValueKind.String);
            protocolKey.SetValue("URL Protocol", "", RegistryValueKind.String);
        }

        using (RegistryKey commandKey = Registry.CurrentUser.CreateSubKey(@"Software\Classes\" + protocol + @"\shell\open\command"))
        {
            if (commandKey == null)
            {
                throw new InvalidOperationException("Windows could not save the " + protocol + " launch command.");
            }
            commandKey.SetValue("", command, RegistryValueKind.String);
        }

        string effectiveCommand;
        using (RegistryKey effectiveKey = Registry.ClassesRoot.OpenSubKey(protocol + @"\shell\open\command"))
        {
            effectiveCommand = effectiveKey == null ? null : effectiveKey.GetValue("") as string;
        }
        if (String.IsNullOrEmpty(effectiveCommand)
            || effectiveCommand.IndexOf(installedExe, StringComparison.OrdinalIgnoreCase) < 0)
        {
            throw new InvalidOperationException(
                "Windows is not using the current " + protocol + " handler. Contact the GLPI administrator."
            );
        }
    }

    private static void LaunchFromProtocol(string protocolUri)
    {
        Match match = Regex.Match(
            (protocolUri ?? String.Empty).Trim().Trim('"'),
            @"^mikrotik(?:-(putty|kitty))?://([a-f0-9]{64})/?$",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant
        );
        if (!match.Success)
        {
            throw new InvalidOperationException("The launch code is invalid. Return to GLPI and try again.");
        }

        string client = match.Groups[1].Value.ToLowerInvariant();
        if (String.IsNullOrEmpty(client)) client = "winbox";
        string token = match.Groups[2].Value.ToLowerInvariant();
        Dictionary<string, object> connection = null;
        try
        {
            WriteLog("Protocol handler started for " + client + "; launch code format is valid.");
            connection = RedeemLaunchCode(token, client);
            WriteLog("GLPI connection exchange succeeded for " + client + ".");

            if (client == "winbox")
            {
                StartWinbox(connection);
            }
            else
            {
                StartSshClient(connection, client);
            }

            WriteLog(client + " process start returned successfully.");
            ReportLaunchResult(token, "started", client);
        }
        catch
        {
            ReportLaunchResult(token, "failed", client);
            throw;
        }
        finally
        {
            if (connection != null) connection.Clear();
            connection = null;
        }
    }

    private static void StartWinbox(Dictionary<string, object> connection)
    {
        string address = GetString(connection, "address");
        string username = GetString(connection, "username");
        string password = GetString(connection, "password");
        if (String.IsNullOrWhiteSpace(address) || String.IsNullOrWhiteSpace(username))
        {
            throw new InvalidOperationException("GLPI returned incomplete Winbox connection details.");
        }

        string winboxPath = FindWinbox();
        if (String.IsNullOrEmpty(winboxPath))
        {
            throw new FileNotFoundException(
                "Winbox.exe was not found. Install Winbox or check C:\\Tools and Windows App Paths."
            );
        }
        WriteLog("Winbox executable found.");

        ProcessStartInfo startInfo = new ProcessStartInfo();
        startInfo.FileName = winboxPath;
        startInfo.Arguments = QuoteArgument(address) + " "
            + QuoteArgument(username) + " " + QuoteArgument(password);
        startInfo.WorkingDirectory = Path.GetDirectoryName(winboxPath);
        startInfo.UseShellExecute = false;
        startInfo.CreateNoWindow = true;
        using (Process process = Process.Start(startInfo))
        {
            if (process == null)
            {
                throw new InvalidOperationException("Windows could not start Winbox.exe.");
            }
        }

        password = null;
    }

    private static void StartSshClient(Dictionary<string, object> connection, string client)
    {
        string host = GetString(connection, "host");
        string port = GetString(connection, "port");
        string username = GetString(connection, "username");
        string password = GetString(connection, "password");
        int parsedPort;
        if (String.IsNullOrWhiteSpace(host) || String.IsNullOrWhiteSpace(username)
            || !Int32.TryParse(port, out parsedPort) || parsedPort < 1 || parsedPort > 65535)
        {
            throw new InvalidOperationException("GLPI returned incomplete SSH connection details.");
        }

        string executable = FindSshClient(client);
        if (String.IsNullOrEmpty(executable))
        {
            string clientName = client == "putty" ? "PuTTY" : "KiTTY";
            throw new FileNotFoundException(clientName + ".exe was not found. Install it or place it in C:\\Tools.");
        }

        ProcessStartInfo startInfo = new ProcessStartInfo();
        startInfo.FileName = executable;
        string passwordFile = null;
        startInfo.Arguments = "-ssh -P " + QuoteArgument(port) + " -l " + QuoteArgument(username) + " ";
        if (client == "putty" && !String.IsNullOrEmpty(password) && SupportsPuttyPasswordFile(executable))
        {
            passwordFile = WriteTemporaryPasswordFile(password);
            startInfo.Arguments += "-pwfile " + QuoteArgument(passwordFile) + " ";
        }
        else if (client == "kitty" && !String.IsNullOrEmpty(password))
        {
            startInfo.Arguments += "-pass " + QuoteArgument(password) + " ";
        }
        startInfo.Arguments += QuoteArgument(host);
        startInfo.WorkingDirectory = Path.GetDirectoryName(executable);
        startInfo.UseShellExecute = false;
        startInfo.CreateNoWindow = false;
        try
        {
            using (Process process = Process.Start(startInfo))
            {
                if (process == null)
                {
                    throw new InvalidOperationException("Windows could not start the SSH client.");
                }
                if (passwordFile != null)
                {
                    try
                    {
                        bool inputIdle = process.WaitForInputIdle(5000);
                        if (!inputIdle) Thread.Sleep(1000);
                    }
                    catch
                    {
                        Thread.Sleep(1000);
                    }
                    Thread.Sleep(500);
                }
            }
        }
        finally
        {
            if (passwordFile != null)
            {
                try
                {
                    File.Delete(passwordFile);
                }
                catch
                {
                    WriteLog("Could not remove the temporary PuTTY password file.");
                }
            }
            password = null;
        }

        if (String.IsNullOrEmpty(GetString(connection, "password")))
        {
            WriteLog(client + " started without a saved password; the client may prompt for it.");
        }
        else if (client == "putty" && passwordFile == null)
        {
            WriteLog("PuTTY started without automatic password; PuTTY 0.83 or newer is required for -pwfile.");
        }
        else
        {
            WriteLog(client == "putty"
                ? "PuTTY process started; the saved password was supplied through a temporary password file."
                : "KiTTY process started with saved credentials; the password was not written to the launch log.");
        }
    }

    private static bool SupportsPuttyPasswordFile(string executable)
    {
        try
        {
            string version = FileVersionInfo.GetVersionInfo(executable).ProductVersion ?? String.Empty;
            Match match = Regex.Match(version, @"(?:^|\D)(\d+)\.(\d+)(?:\D|$)");
            int major;
            int minor;
            return match.Success
                && Int32.TryParse(match.Groups[1].Value, out major)
                && Int32.TryParse(match.Groups[2].Value, out minor)
                && (major > 0 || minor >= 83);
        }
        catch
        {
            return false;
        }
    }

    private static string WriteTemporaryPasswordFile(string password)
    {
        string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (String.IsNullOrEmpty(localAppData))
        {
            throw new InvalidOperationException("Windows did not provide a private folder for the temporary PuTTY password file.");
        }

        string directory = Path.Combine(localAppData, InstallFolderName);
        Directory.CreateDirectory(directory);
        string path = Path.Combine(directory, "putty-password-" + Guid.NewGuid().ToString("N") + ".tmp");
        File.WriteAllText(path, password + Environment.NewLine, new UTF8Encoding(false));
        File.SetAttributes(path, FileAttributes.Hidden);
        return path;
    }

    private static void ReportLaunchResult(string token, string status, string client)
    {
        try
        {
            string endpoint = ServerBaseUrl.TrimEnd('/') + "/plugins/winbox/"
                + (client == "winbox" ? "ReportLaunch" : "ReportSshLaunch");
            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(endpoint);
            request.Method = "POST";
            request.ContentType = "application/json; charset=utf-8";
            request.Accept = "application/json";
            request.UserAgent = "Winbox-GLPI/1.0";
            request.Timeout = 10000;
            request.ReadWriteTimeout = 10000;
            request.Proxy = WebRequest.DefaultWebProxy;

            JavaScriptSerializer serializer = new JavaScriptSerializer();
            Dictionary<string, string> report = new Dictionary<string, string>
            {
                { "token", token },
                { "status", status },
            };
            if (client != "winbox") report.Add("client", client);
            string body = serializer.Serialize(report);
            byte[] bodyBytes = Encoding.UTF8.GetBytes(body);
            request.ContentLength = bodyBytes.Length;
            using (Stream stream = request.GetRequestStream())
            {
                stream.Write(bodyBytes, 0, bodyBytes.Length);
            }
            using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
            {
                if (response.StatusCode == HttpStatusCode.OK)
                {
                    WriteLog(client + " process result was recorded by GLPI: " + status + ".");
                }
            }
        }
        catch
        {
            // The application launch must not be interrupted if the audit callback is unavailable.
            WriteLog("Unable to send the " + client + " process result to GLPI.");
        }
    }

    private static Dictionary<string, object> RedeemLaunchCode(string token, string client)
    {
        string endpoint = ServerBaseUrl.TrimEnd('/') + "/plugins/winbox/"
            + (client == "winbox" ? "Redeem" : "RedeemSsh");
        HttpWebRequest request = (HttpWebRequest)WebRequest.Create(endpoint);
        request.Method = "POST";
        request.ContentType = "application/json; charset=utf-8";
        request.Accept = "application/json";
        request.UserAgent = "Winbox-GLPI/1.0";
        request.Timeout = 15000;
        request.ReadWriteTimeout = 15000;
        request.Proxy = WebRequest.DefaultWebProxy;

        JavaScriptSerializer serializer = new JavaScriptSerializer();
        serializer.MaxJsonLength = 32768;
        Dictionary<string, string> requestBody = new Dictionary<string, string> { { "token", token } };
        if (client != "winbox") requestBody.Add("client", client);
        string body = serializer.Serialize(requestBody);
        byte[] bodyBytes = Encoding.UTF8.GetBytes(body);
        request.ContentLength = bodyBytes.Length;
        using (Stream stream = request.GetRequestStream())
        {
            stream.Write(bodyBytes, 0, bodyBytes.Length);
        }

        try
        {
            using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
            using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
            {
                Dictionary<string, object> result =
                    serializer.Deserialize<Dictionary<string, object>>(reader.ReadToEnd());
                if (result == null)
                {
                    throw new InvalidOperationException("GLPI returned an empty response.");
                }
                return result;
            }
        }
        catch (WebException exception)
        {
            HttpWebResponse response = exception.Response as HttpWebResponse;
            if (response != null)
            {
                HttpStatusCode status = response.StatusCode;
                response.Close();
                WriteLog("GLPI credential exchange failed with HTTP " + (int)status + ".");
                if (status == HttpStatusCode.Gone)
                {
                    throw new InvalidOperationException(
                        "The launch code has expired or was already used. Return to GLPI and create a new launch request."
                    );
                }
                if (status == HttpStatusCode.NotFound)
                {
                    throw new InvalidOperationException("Connection details are not available for this device in GLPI.");
                }
                throw new InvalidOperationException(
                    "GLPI could not provide the connection details (HTTP " + (int)status + "). Try again."
                );
            }
            throw new InvalidOperationException(
                "Could not connect to GLPI. Check the network connection and try again.",
                exception
            );
        }
    }

    private static string FindWinbox()
    {
        List<string> candidates = new List<string>();
        candidates.Add(@"C:\Tools\winbox.exe");
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "WinBox", "winbox.exe"));
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "WinBox", "winbox.exe"));

        foreach (string candidate in candidates)
        {
            if (!String.IsNullOrEmpty(candidate) && File.Exists(candidate))
            {
                return candidate;
            }
        }

        string[] appPathKeys = new string[]
        {
            @"Software\Microsoft\Windows\CurrentVersion\App Paths\winbox.exe",
            @"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths\winbox.exe"
        };
        foreach (RegistryKey root in new RegistryKey[] { Registry.CurrentUser, Registry.LocalMachine })
        {
            foreach (string keyPath in appPathKeys)
            {
                try
                {
                    using (RegistryKey key = root.OpenSubKey(keyPath))
                    {
                        string candidate = key == null ? null : key.GetValue("") as string;
                        if (!String.IsNullOrEmpty(candidate) && File.Exists(Environment.ExpandEnvironmentVariables(candidate)))
                        {
                            return Environment.ExpandEnvironmentVariables(candidate);
                        }
                    }
                }
                catch
                {
                    // Continue checking other Windows App Paths entries.
                }
            }
        }

        return null;
    }

    private static string FindSshClient(string client)
    {
        bool isKitty = client == "kitty";
        string executableName = isKitty ? "kitty.exe" : "putty.exe";
        string folderName = isKitty ? "KiTTY" : "PuTTY";
        List<string> candidates = new List<string>();
        candidates.Add(Path.Combine(@"C:\Tools", executableName));
        candidates.Add(Path.Combine(@"C:\Tools", folderName, executableName));
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), folderName, executableName));
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), folderName, executableName));

        foreach (string candidate in candidates)
        {
            if (!String.IsNullOrEmpty(candidate) && File.Exists(candidate))
            {
                return candidate;
            }
        }

        string[] appPathKeys = new string[]
        {
            @"Software\Microsoft\Windows\CurrentVersion\App Paths\" + executableName,
            @"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths\" + executableName
        };
        foreach (RegistryKey root in new RegistryKey[] { Registry.CurrentUser, Registry.LocalMachine })
        {
            foreach (string keyPath in appPathKeys)
            {
                try
                {
                    using (RegistryKey key = root.OpenSubKey(keyPath))
                    {
                        string candidate = key == null ? null : key.GetValue("") as string;
                        if (!String.IsNullOrEmpty(candidate))
                        {
                            candidate = Environment.ExpandEnvironmentVariables(candidate);
                            if (File.Exists(candidate)) return candidate;
                        }
                    }
                }
                catch
                {
                    // Continue checking other Windows App Paths entries.
                }
            }
        }

        return null;
    }

    private static string QuoteArgument(string value)
    {
        value = value ?? String.Empty;
        StringBuilder result = new StringBuilder();
        result.Append('"');
        int backslashes = 0;
        foreach (char character in value)
        {
            if (character == '\\')
            {
                backslashes++;
                continue;
            }

            if (character == '"')
            {
                result.Append('\\', (backslashes * 2) + 1);
                result.Append('"');
                backslashes = 0;
                continue;
            }

            if (backslashes > 0)
            {
                result.Append('\\', backslashes);
                backslashes = 0;
            }
            result.Append(character);
        }

        if (backslashes > 0)
        {
            result.Append('\\', backslashes * 2);
        }
        result.Append('"');
        return result.ToString();
    }

    private static string GetString(Dictionary<string, object> values, string name)
    {
        object value;
        return values != null && values.TryGetValue(name, out value) && value != null
            ? Convert.ToString(value)
            : String.Empty;
    }

    private static bool IsConfiguredServerUrl()
    {
        Uri uri;
        return !ServerBaseUrl.Contains("~")
            && Uri.TryCreate(ServerBaseUrl, UriKind.Absolute, out uri)
            && (uri.Scheme == Uri.UriSchemeHttp || uri.Scheme == Uri.UriSchemeHttps);
    }

    private static void WriteLog(string message)
    {
        try
        {
            string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
            if (String.IsNullOrEmpty(localAppData))
            {
                return;
            }
            string directory = Path.Combine(localAppData, InstallFolderName);
            Directory.CreateDirectory(directory);
            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + message + Environment.NewLine;
            File.AppendAllText(
                Path.Combine(directory, "winbox-glpi-launch.log"),
                line,
                new UTF8Encoding(false)
            );
        }
        catch
        {
            // Diagnostics must not interrupt installing or launching Winbox.
        }
    }

    private static string Sanitize(string message)
    {
        if (String.IsNullOrEmpty(message))
        {
            return "An unexpected error occurred.";
        }
        return Regex.Replace(message, "[a-fA-F0-9]{64}", "[redacted code]");
    }
}
