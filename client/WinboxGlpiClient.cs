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

[assembly: AssemblyTitle("MikroTik Tools for GLPI")]
[assembly: AssemblyDescription("MikroTik Tools for GLPI client installer and protocol handler")]
[assembly: AssemblyCompany("Connect IT")]
[assembly: AssemblyProduct("MikroTik Tools for GLPI")]
[assembly: AssemblyVersion("1.0.0.0")]
[assembly: AssemblyFileVersion("1.0.0.0")]

internal static class Program
{
    // GLPI replaces this fixed-width UTF-16 marker with the current server URL when serving the EXE.
    private static readonly string ServerBaseUrl = "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~".TrimEnd('\0');
    private const string ProductName = "MikroTik Tools for GLPI";
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
                "The installer does not have a valid GLPI server address. Download the current file from the MikroTik Tools for GLPI setup page."
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
        RegisterProtocol("mikrotik-sshdiag", "URL:MikroTik SSH diagnostic Protocol", installedExe);
        UnregisterProtocol("mikrotik-kitty");
        WriteLog("Installer completed; per-user Winbox and PuTTY SSH handlers are registered; the obsolete KiTTY handler was removed.");

        string clientsDirectory = Path.Combine(installDirectory, "clients");
        string winboxStatus;
        string puttyStatus;
        string plinkStatus;
        using (Form progress = CreateSetupProgress())
        {
            progress.Show();
            progress.Refresh();
            Application.DoEvents();
            winboxStatus = EnsureClient("winbox", clientsDirectory, progress);
            puttyStatus = EnsureClient("putty", clientsDirectory, progress);
            plinkStatus = EnsureClient("plink", clientsDirectory, progress);
            progress.Close();
        }

        string winboxPath = FindWinbox();
        string puttyPath = FindSshClient("putty");
        string plinkPath = FindSshClient("plink");
        WriteLog("Client setup complete: WinBox=" + (winboxPath == null ? "not found" : winboxStatus)
            + "; PuTTY=" + (puttyPath == null ? "not found" : puttyStatus)
            + "; Plink diagnostic=" + (plinkPath == null ? "not found" : plinkStatus) + ".");

        string httpWarning = baseUri.Scheme == Uri.UriSchemeHttp
            ? "\n\nThe test GLPI server uses HTTP. Use test credentials only."
            : String.Empty;
        MessageBox.Show(
            "Setup is complete for the current Windows user.\n\n"
            + "Open a network equipment record in GLPI and choose Open in Winbox or Open SSH in PuTTY.\n"
            + "WinBox: " + (winboxPath == null ? "not installed. " + winboxStatus : winboxStatus) + "\n"
            + "PuTTY: " + (puttyPath == null ? "not installed. " + puttyStatus : puttyStatus) + "\n"
            + "SSH diagnostic: " + (plinkPath == null ? "not installed. " + plinkStatus : plinkStatus) + "\n\n"
            + "If a download failed, check the Internet connection and run this setup file again.\n"
            + "Setup and launch log:\n" + Path.Combine(installDirectory, "winbox-glpi-launch.log") + "\n"
            + "Temporary SSH diagnostic log:\n" + Path.Combine(installDirectory, "winbox-glpi-ssh-test.log")
            + httpWarning,
            ProductName,
            MessageBoxButtons.OK,
            MessageBoxIcon.Information
        );
    }

    private static Form CreateSetupProgress()
    {
        Form form = new Form();
        form.Text = ProductName + " setup";
        form.FormBorderStyle = FormBorderStyle.FixedDialog;
        form.StartPosition = FormStartPosition.CenterScreen;
        form.ClientSize = new System.Drawing.Size(470, 110);
        form.ControlBox = false;
        form.ShowInTaskbar = false;

        Label label = new Label();
        label.Name = "setupStatus";
        label.Text = "Checking WinBox and PuTTY...";
        label.AutoSize = false;
        label.Dock = DockStyle.Top;
        label.Height = 62;
        label.Padding = new Padding(14, 14, 14, 8);
        form.Controls.Add(label);

        ProgressBar progress = new ProgressBar();
        progress.Style = ProgressBarStyle.Marquee;
        progress.MarqueeAnimationSpeed = 24;
        progress.Dock = DockStyle.Bottom;
        form.Controls.Add(progress);
        return form;
    }

    private static string EnsureClient(string client, string clientsDirectory, Form progress)
    {
        string clientName = client == "winbox" ? "WinBox" : (client == "putty" ? "PuTTY" : "Plink diagnostic");
        string existing = client == "winbox" ? FindWinbox() : FindSshClient(client);
        if (!String.IsNullOrEmpty(existing))
        {
            bool requiresPasswordFile = client == "putty" || client == "plink";
            bool compatible = !requiresPasswordFile || SupportsPuttyPasswordFile(existing);
            if (compatible)
            {
                WriteLog(clientName + " was found at " + existing + "; the existing installation was left unchanged.");
                return "found; existing installation left unchanged";
            }

            string managedPath = GetManagedClientPath(client, clientsDirectory);
            if (String.Equals(Path.GetFullPath(existing), Path.GetFullPath(managedPath), StringComparison.OrdinalIgnoreCase))
            {
                WriteLog(clientName + " is installed, but this version does not support the required password-file option; it was left unchanged.");
                return "found, but too old for automatic authentication; left unchanged";
            }

            if (File.Exists(managedPath))
            {
                if (SupportsPuttyPasswordFile(managedPath))
                {
                    WriteLog(clientName + " compatible managed copy is available at " + managedPath
                        + "; the older external installation was left unchanged.");
                    return "compatible managed copy ready; external installation left unchanged";
                }
                WriteLog(clientName + " has an incompatible managed copy at " + managedPath
                    + "; neither managed nor external installation was changed.");
                return "installed version is too old; existing files left unchanged";
            }

            WriteLog(clientName + " existing version does not support the required password-file option; installing a separate managed copy without changing it.");
        }

        SetSetupProgress(progress, "Checking or installing " + clientName + "...");
        try
        {
            SshClientInstaller.InstallIfMissing(client, clientsDirectory);
            WriteLog(clientName + " package was downloaded over HTTPS, checksum-verified, and installed in the per-user managed folder.");
            return "installed for this Windows user; existing applications were not changed";
        }
        catch (Exception exception)
        {
            string message = Sanitize(exception.Message);
            existing = client == "winbox" ? FindWinbox() : FindSshClient(client);
            if (!String.IsNullOrEmpty(existing))
            {
                WriteLog(clientName + " installation failed; existing file at " + existing + " remains unchanged: " + message);
                return "download failed; existing installation left unchanged";
            }
            WriteLog(clientName + " installation failed: " + message);
            return "installation failed: " + message;
        }
    }

    private static string GetManagedClientPath(string client, string clientsDirectory)
    {
        string folder = "PuTTY";
        string executable = client == "plink" ? "plink.exe" : "putty.exe";
        return Path.Combine(clientsDirectory, folder, executable);
    }

    private static void SetSetupProgress(Form progress, string message)
    {
        if (progress == null || progress.IsDisposed) return;
        foreach (Control control in progress.Controls)
        {
            Label label = control as Label;
            if (label != null)
            {
                label.Text = message;
                label.Refresh();
                break;
            }
        }
        Application.DoEvents();
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

    private static void UnregisterProtocol(string protocol)
    {
        Registry.CurrentUser.DeleteSubKeyTree(@"Software\Classes\" + protocol, false);
    }

    private static void LaunchFromProtocol(string protocolUri)
    {
        Match match = Regex.Match(
            (protocolUri ?? String.Empty).Trim().Trim('"'),
            @"^mikrotik(?:-(putty|sshdiag))?://([a-f0-9]{64})/?$",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant
        );
        if (!match.Success)
        {
            throw new InvalidOperationException("The launch code is invalid. Return to GLPI and try again.");
        }

        string client = match.Groups[1].Value.ToLowerInvariant();
        if (String.IsNullOrEmpty(client)) client = "winbox";
        string token = match.Groups[2].Value.ToLowerInvariant();
        string sshExecutable = null;
        if (client != "winbox")
        {
            WriteSshTestLog("event=protocol_received client=" + client);
            string executableKind = client == "sshdiag" ? "plink" : client;
            sshExecutable = FindSshClient(executableKind);
            if (String.IsNullOrEmpty(sshExecutable))
            {
                string clientName = client == "putty" ? "PuTTY" : "Plink";
                WriteLog(clientName + ".exe was not found; the launch code was not redeemed.");
                WriteSshTestLog("event=client_not_found client=" + client);
                throw new FileNotFoundException(
                    clientName + ".exe was not found. Run Winbox-GLPI-Setup.exe from the setup page again, then retry."
                );
            }
            WriteLog(client + " executable found.");
            string productVersion = FileVersionInfo.GetVersionInfo(sshExecutable).ProductVersion ?? "unknown";
            WriteSshTestLog("event=client_selected client=" + client + " version=" + SanitizeLogValue(productVersion));
        }

        Dictionary<string, object> connection = null;
        bool launchCodeRedeemed = false;
        try
        {
            WriteLog("Protocol handler started for " + client + "; launch code format is valid.");
            connection = RedeemLaunchCode(token, client);
            launchCodeRedeemed = true;
            WriteLog("GLPI connection exchange succeeded for " + client + ".");
            if (client != "winbox")
            {
                WriteSshTestLog("event=glpi_exchange_succeeded client=" + client);
            }

            if (client == "winbox")
            {
                StartWinbox(connection);
            }
            else if (client == "sshdiag")
            {
                RunSshDiagnostic(connection, sshExecutable);
            }
            else
            {
                StartSshClient(connection, client, sshExecutable);
            }

            if (client != "sshdiag")
            {
                WriteLog(client + " process start returned successfully.");
                ReportLaunchResult(token, "started", client);
            }
        }
        catch (Exception exception)
        {
            if (client != "winbox")
            {
                // Do not put exception text or connection details in the temporary diagnostic log.
                WriteSshTestLog("event=launcher_error client=" + client + " type=" + exception.GetType().Name);
            }
            if (launchCodeRedeemed && client != "sshdiag") ReportLaunchResult(token, "failed", client);
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

    private static void StartSshClient(Dictionary<string, object> connection, string client, string executable)
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
        if (String.IsNullOrEmpty(password))
        {
            WriteSshTestLog("event=credential_fields client=" + client + " host_present=" + (!String.IsNullOrWhiteSpace(host))
                + " port_valid=" + (Int32.TryParse(port, out parsedPort) && parsedPort >= 1 && parsedPort <= 65535)
                + " username_present=" + (!String.IsNullOrWhiteSpace(username)) + " password_present=false");
            throw new InvalidOperationException("No SSH password is saved for this device in GLPI. Ask a GLPI administrator to configure the device credentials before launching SSH.");
        }
        bool passwordHasLineBreak = password.IndexOf('\r') >= 0 || password.IndexOf('\n') >= 0;
        bool passwordHasNonAscii = false;
        foreach (char character in password)
        {
            if (character > 127)
            {
                passwordHasNonAscii = true;
                break;
            }
        }
        WriteSshTestLog("event=credential_fields client=" + client + " target=" + SanitizeLogValue(host) + ":" + parsedPort
            + " host_present=" + (!String.IsNullOrWhiteSpace(host))
            + " port_valid=" + (Int32.TryParse(port, out parsedPort) && parsedPort >= 1 && parsedPort <= 65535)
            + " username_present=" + (!String.IsNullOrWhiteSpace(username)) + " password_present=true"
            + " password_has_linebreak=" + passwordHasLineBreak + " password_has_nonascii=" + passwordHasNonAscii
            + " password_has_quote=" + password.Contains("\"")
            + " password_has_outer_space=" + (password.Length > 0 && (password[0] == ' ' || password[password.Length - 1] == ' ')));
        if (client == "putty" && !SupportsPuttyPasswordFile(executable))
        {
            WriteSshTestLog("event=automatic_password_unavailable client=putty");
            throw new InvalidOperationException("This PuTTY version cannot receive the saved password without an interactive prompt. Run the current MikroTik Tools for GLPI setup installer to install its managed PuTTY version.");
        }
        if (passwordHasLineBreak)
        {
            WriteSshTestLog("event=automatic_password_unavailable client=" + client + " reason=password_linebreak");
            throw new InvalidOperationException("The saved SSH password contains a line break and cannot be supplied automatically. Re-save it as a single-line password in GLPI.");
        }

        ProcessStartInfo startInfo = new ProcessStartInfo();
        startInfo.FileName = executable;
        string passwordFile = null;
        startInfo.Arguments = "-ssh -P " + QuoteArgument(port) + " -l " + QuoteArgument(username) + " ";
        if (client == "putty")
        {
            passwordFile = WriteTemporaryPasswordFile(password, "putty-password");
            startInfo.Arguments += "-pwfile " + QuoteArgument(passwordFile) + " ";
            bool fileMatchesSavedValue = File.Exists(passwordFile)
                && String.Equals(File.ReadAllText(passwordFile, new UTF8Encoding(false)), password + Environment.NewLine, StringComparison.Ordinal);
            WriteSshTestLog("event=auth_material_ready client=putty method=pwfile file_created=" + File.Exists(passwordFile)
                + " file_matches_saved_value=" + fileMatchesSavedValue);
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
                WriteSshTestLog("event=process_started client=" + client + " pid=" + process.Id + " auth_result=unknown");
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
                    WriteSshTestLog("event=pwfile_cleanup_started client=putty exists=" + File.Exists(passwordFile));
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
                WriteSshTestLog("event=pwfile_cleanup_finished client=putty exists=" + File.Exists(passwordFile));
            }
            password = null;
        }

        WriteLog("PuTTY process started; the saved password was supplied through a temporary password file.");
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

    private static void RunSshDiagnostic(Dictionary<string, object> connection, string plinkExecutable)
    {
        string host = GetString(connection, "host");
        string port = GetString(connection, "port");
        string username = GetString(connection, "username");
        string password = GetString(connection, "password");
        int parsedPort;
        bool portValid = Int32.TryParse(port, out parsedPort) && parsedPort >= 1 && parsedPort <= 65535;
        WriteSshTestLog("event=diagnostic_started client=plink host_present=" + (!String.IsNullOrWhiteSpace(host))
            + " port_valid=" + portValid + " username_present=" + (!String.IsNullOrWhiteSpace(username))
            + " password_present=" + (!String.IsNullOrEmpty(password)));

        if (String.IsNullOrWhiteSpace(host) || String.IsNullOrWhiteSpace(username) || !portValid || String.IsNullOrEmpty(password))
        {
            WriteSshTestLog("event=diagnostic_result result=missing_connection_field");
            throw new InvalidOperationException("GLPI returned incomplete SSH diagnostic details. Ask a GLPI administrator to check the device settings.");
        }
        if (password.IndexOf('\r') >= 0 || password.IndexOf('\n') >= 0)
        {
            WriteSshTestLog("event=diagnostic_result result=password_contains_linebreak");
            throw new InvalidOperationException("The saved SSH password contains a line break and cannot be tested automatically. Re-save it as a single-line password in GLPI.");
        }

        string passwordFile = null;
        StringBuilder transcript = new StringBuilder();
        object transcriptLock = new object();
        DataReceivedEventHandler capture = delegate(object sender, DataReceivedEventArgs eventArgs)
        {
            if (eventArgs.Data == null) return;
            lock (transcriptLock)
            {
                if (transcript.Length < 131072) transcript.AppendLine(eventArgs.Data);
            }
        };

        string result = "unknown";
        int? exitCode = null;
        bool timedOut = false;
        try
        {
            passwordFile = WriteTemporaryPasswordFile(password, "ssh-diagnostic-password");
            ProcessStartInfo startInfo = new ProcessStartInfo();
            startInfo.FileName = plinkExecutable;
            startInfo.Arguments = "-batch -noagent -v -ssh -P " + QuoteArgument(port)
                + " -l " + QuoteArgument(username) + " -pwfile " + QuoteArgument(passwordFile)
                + " " + QuoteArgument(host) + " " + QuoteArgument("/system identity print");
            startInfo.WorkingDirectory = Path.GetDirectoryName(plinkExecutable);
            startInfo.UseShellExecute = false;
            startInfo.CreateNoWindow = true;
            startInfo.RedirectStandardOutput = true;
            startInfo.RedirectStandardError = true;

            using (Process process = Process.Start(startInfo))
            {
                if (process == null) throw new InvalidOperationException("Windows could not start the Plink diagnostic client.");
                process.OutputDataReceived += capture;
                process.ErrorDataReceived += capture;
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();
                WriteSshTestLog("event=diagnostic_process_started pid=" + process.Id + " target=" + host + ":" + port);
                if (!process.WaitForExit(20000))
                {
                    timedOut = true;
                    try { process.Kill(); } catch { }
                    process.WaitForExit();
                }
                else
                {
                    process.WaitForExit(); // Drain asynchronous stdout/stderr callbacks.
                    exitCode = process.ExitCode;
                }
            }

            string output;
            lock (transcriptLock) output = transcript.ToString();
            result = SshDiagnosticClassifier.Classify(output, exitCode, timedOut);
            WriteSshTestLog("event=diagnostic_handshake " + SshDiagnosticClassifier.DescribeHandshake(output));
            WriteSshTestLog("event=diagnostic_result result=" + result
                + " exit_code=" + (exitCode.HasValue ? exitCode.Value.ToString() : "none")
                + " timed_out=" + timedOut + " target=" + host + ":" + port);
            ShowSshDiagnosticResult(result, host, port);
        }
        catch (Exception exception)
        {
            WriteSshTestLog("event=diagnostic_error type=" + exception.GetType().Name);
            throw;
        }
        finally
        {
            if (passwordFile != null)
            {
                try { if (File.Exists(passwordFile)) File.Delete(passwordFile); }
                catch { WriteSshTestLog("event=diagnostic_password_file_cleanup_failed"); }
            }
            password = null;
        }
    }

    private static void ShowSshDiagnosticResult(string result, string host, string port)
    {
        string message;
        MessageBoxIcon icon;
        switch (result)
        {
            case "authentication_accepted":
                message = "SSH authentication succeeded with the saved GLPI credentials. RouterOS answered the read-only identity check. If PuTTY still fails, the remaining issue is in the GUI client's launch settings.";
                icon = MessageBoxIcon.Information;
                break;
            case "authentication_accepted_command_failed":
                message = "RouterOS accepted the saved GLPI credentials, but the read-only identity check failed. The SSH account may not have read permission.";
                icon = MessageBoxIcon.Warning;
                break;
            case "authentication_rejected":
                message = "The SSH server at the configured host and port rejected authentication. First verify that this endpoint is the intended router. Then check the saved username/password and RouterOS SSH password-authentication policy. Compare the server fingerprint in the diagnostic log with a trusted router fingerprint.";
                icon = MessageBoxIcon.Error;
                break;
            case "host_key_not_trusted":
                message = "The SSH connection stopped because this Windows user's PuTTY host-key cache does not trust the router key. Verify and trust the router fingerprint in PuTTY, then run the test again.";
                icon = MessageBoxIcon.Warning;
                break;
            case "connection_failed":
                message = "The SSH client could not establish a connection to the configured host and port.";
                icon = MessageBoxIcon.Error;
                break;
            case "timeout":
                message = "The SSH diagnostic timed out before the server returned a result.";
                icon = MessageBoxIcon.Warning;
                break;
            default:
                message = "The diagnostic could not classify the SSH response. Send the temporary SSH diagnostic log to the GLPI administrator.";
                icon = MessageBoxIcon.Warning;
                break;
        }
        string logPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            InstallFolderName, "winbox-glpi-ssh-test.log");
        MessageBox.Show(message + "\n\nTarget: " + host + ":" + port + "\nDiagnostic log:\n" + logPath,
            ProductName + " — SSH diagnostic", MessageBoxButtons.OK, icon);
    }

    private static string WriteTemporaryPasswordFile(string password, string filePrefix)
    {
        string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (String.IsNullOrEmpty(localAppData))
        {
            throw new InvalidOperationException("Windows did not provide a private folder for the temporary PuTTY password file.");
        }

        string directory = Path.Combine(localAppData, InstallFolderName);
        Directory.CreateDirectory(directory);
        string path = Path.Combine(directory, filePrefix + "-" + Guid.NewGuid().ToString("N") + ".tmp");
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
        string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (!String.IsNullOrEmpty(localAppData))
        {
            candidates.Add(Path.Combine(localAppData, "Programs", "WinBox", "winbox.exe"));
            candidates.Add(Path.Combine(localAppData, "WinBox", "winbox.exe"));
        }

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

        string pathValue = Environment.GetEnvironmentVariable("PATH") ?? String.Empty;
        foreach (string directory in pathValue.Split(Path.PathSeparator))
        {
            if (String.IsNullOrWhiteSpace(directory)) continue;
            try
            {
                string candidate = Path.Combine(directory.Trim().Trim('"'), "winbox.exe");
                if (File.Exists(candidate)) return candidate;
            }
            catch
            {
                // Ignore malformed PATH entries and continue checking known locations.
            }
        }

        if (!String.IsNullOrEmpty(localAppData))
        {
            string managedDirectory = Path.Combine(localAppData, InstallFolderName, "clients", "WinBox");
            string managed = FindFirstExistingFile(managedDirectory,
                new string[] { "WinBox.exe", "winbox64.exe", "winbox.exe" });
            if (managed != null) return managed;
        }

        return null;
    }

    private static string FindFirstExistingFile(string root, string[] names)
    {
        if (!Directory.Exists(root)) return null;
        foreach (string name in names)
        {
            string[] matches = Directory.GetFiles(root, name, SearchOption.AllDirectories);
            if (matches.Length > 0) return matches[0];
        }
        return null;
    }

    private static string FindSshClient(string client)
    {
        bool isPlink = client == "plink";
        string executableName = isPlink ? "plink.exe" : "putty.exe";
        string folderName = "PuTTY";
        string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        List<string> candidates = new List<string>();
        string overrideDirectory = Environment.GetEnvironmentVariable("WINBOX_GLPI_CLIENT_DIR");
        if (!String.IsNullOrWhiteSpace(overrideDirectory))
        {
            candidates.Add(Path.Combine(overrideDirectory.Trim().Trim('"'), executableName));
        }
        if (!String.IsNullOrEmpty(localAppData))
        {
            candidates.Add(Path.Combine(localAppData, InstallFolderName, "clients", folderName, executableName));
            candidates.Add(Path.Combine(localAppData, InstallFolderName, "clients", executableName));
        }
        candidates.Add(Path.Combine(@"C:\Tools", executableName));
        candidates.Add(Path.Combine(@"C:\Tools", folderName, executableName));
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), folderName, executableName));
        candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), folderName, executableName));
        if (!String.IsNullOrEmpty(localAppData))
        {
            candidates.Add(Path.Combine(localAppData, "Programs", folderName, executableName));
            candidates.Add(Path.Combine(localAppData, folderName, executableName));
        }

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

        string pathValue = Environment.GetEnvironmentVariable("PATH") ?? String.Empty;
        foreach (string directory in pathValue.Split(Path.PathSeparator))
        {
            if (String.IsNullOrWhiteSpace(directory)) continue;
            try
            {
                string candidate = Path.Combine(directory.Trim().Trim('"'), executableName);
                if (File.Exists(candidate)) return candidate;
            }
            catch
            {
                // Ignore malformed PATH entries and continue searching.
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

    // Secret-safe diagnostics retained for PuTTY SSH troubleshooting.
    private static void WriteSshTestLog(string message)
    {
        try
        {
            string localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
            if (String.IsNullOrEmpty(localAppData)) return;
            string directory = Path.Combine(localAppData, InstallFolderName);
            Directory.CreateDirectory(directory);
            string path = Path.Combine(directory, "winbox-glpi-ssh-test.log");
            FileInfo current = new FileInfo(path);
            if (current.Exists && current.Length > 32768)
            {
                File.WriteAllText(path, String.Empty, new UTF8Encoding(false));
            }
            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + SanitizeLogValue(message) + Environment.NewLine;
            File.AppendAllText(path, line, new UTF8Encoding(false));
        }
        catch
        {
            // Temporary diagnostics must never block an SSH launch.
        }
    }

    private static string SanitizeLogValue(string value)
    {
        return Regex.Replace(value ?? String.Empty, "[\\r\\n\\t]", " ");
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
