param([Parameter(Position = 0)][string]$ProtocolUri)

$ErrorActionPreference = 'Stop'
$endpoint = '__WINBOX_GLPI_REDEEM_URL__'
$reportEndpoint = $endpoint -replace '/Redeem$', '/ReportLaunch'
$sshRedeemEndpoint = $endpoint -replace '/Redeem$', '/RedeemSsh'
$sshReportEndpoint = $endpoint -replace '/Redeem$', '/ReportSshLaunch'
$launchLog = Join-Path $PSScriptRoot 'winbox-glpi-launch.log'
$token = $null
$client = 'winbox'
$passwordFile = $null
$credentialExchangeSucceeded = $false
$sshClient = $null
$clientName = $null

function Write-LaunchLog([string]$Message) {
    try {
        Add-Content -LiteralPath $launchLog -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Message" -Encoding UTF8
    } catch {
        # Diagnostics must never prevent a launch.
    }
}

function Write-SshTestLog([string]$Message) {
    try {
        $diagnosticPath = Join-Path $env:LOCALAPPDATA 'Winbox-GLPI\winbox-glpi-ssh-test.log'
        $diagnosticDirectory = Split-Path -Parent $diagnosticPath
        New-Item -ItemType Directory -Path $diagnosticDirectory -Force | Out-Null
        if ((Test-Path -LiteralPath $diagnosticPath) -and (Get-Item -LiteralPath $diagnosticPath).Length -gt 32768) {
            Set-Content -LiteralPath $diagnosticPath -Value '' -Encoding UTF8
        }
        Add-Content -LiteralPath $diagnosticPath -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Message" -Encoding UTF8
    } catch {
        # Temporary diagnostics must not affect the client launch.
    }
}

function Send-LaunchReport([string]$Code, [string]$Status, [string]$Client) {
    try {
        $reportBody = @{token = $Code; status = $Status}
        $reportUri = $reportEndpoint
        if ($Client -ne 'winbox') {
            $reportBody.client = $Client
            $reportUri = $sshReportEndpoint
        }
        Invoke-RestMethod -Method Post -Uri $reportUri -ContentType 'application/json' -Body ($reportBody | ConvertTo-Json -Compress) -TimeoutSec 10 | Out-Null
        Write-LaunchLog ('GLPI access log report sent for ' + $Client + ': ' + $Status + '.')
    } catch {
        Write-LaunchLog ('Unable to send the ' + $Client + ' process result to GLPI.')
    }
}

function ConvertTo-WindowsCommandLineArgument([string]$Value) {
    $builder = [System.Text.StringBuilder]::new()
    [void]$builder.Append([char]34)
    $backslashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq [char]92) { $backslashes++; continue }
        if ($character -eq [char]34) {
            [void]$builder.Append([char]92, ($backslashes * 2) + 1)
            [void]$builder.Append([char]34)
            $backslashes = 0
            continue
        }
        if ($backslashes -gt 0) {
            [void]$builder.Append([char]92, $backslashes)
            $backslashes = 0
        }
        [void]$builder.Append($character)
    }
    if ($backslashes -gt 0) { [void]$builder.Append([char]92, $backslashes * 2) }
    [void]$builder.Append([char]34)
    return $builder.ToString()
}

function Find-SshClient([string]$SelectedClient) {
    $executableName = if ($SelectedClient -eq 'putty') { 'putty.exe' } else { 'kitty.exe' }
    $folderName = if ($SelectedClient -eq 'putty') { 'PuTTY' } else { 'KiTTY' }
    $candidates = @(
        (Join-Path $env:LOCALAPPDATA "Winbox-GLPI\clients\$folderName\$executableName"),
        (Join-Path $env:LOCALAPPDATA "Winbox-GLPI\clients\$executableName"),
        "C:\Tools\$executableName",
        "C:\Tools\$folderName\$executableName",
        (Join-Path $env:ProgramFiles "$folderName\$executableName"),
        (Join-Path ${env:ProgramFiles(x86)} "$folderName\$executableName"),
        (Join-Path $env:LOCALAPPDATA "Programs\$folderName\$executableName"),
        (Join-Path $env:LOCALAPPDATA "$folderName\$executableName")
    )
    $found = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if ($found) { return $found }

    foreach ($root in @('HKCU:\Software\Microsoft\Windows\CurrentVersion\App Paths', 'HKLM:\Software\Microsoft\Windows\CurrentVersion\App Paths', 'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths')) {
        $key = Join-Path $root $executableName
        if (Test-Path -LiteralPath $key) {
            $candidate = [Environment]::ExpandEnvironmentVariables([string](Get-Item -LiteralPath $key).GetValue(''))
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }
    }

    foreach ($directory in ([string]$env:PATH).Split([System.IO.Path]::PathSeparator)) {
        if ([string]::IsNullOrWhiteSpace($directory)) { continue }
        $candidate = Join-Path $directory.Trim().Trim('"') $executableName
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    return $null
}

try {
    Write-LaunchLog ('Handler started; URI argument present: ' + (-not [string]::IsNullOrWhiteSpace($ProtocolUri)))
    $uri = ([string]$ProtocolUri).Trim().Trim('"').Trim("'")
    if ([string]::IsNullOrWhiteSpace($uri)) {
        Write-LaunchLog 'No URI argument received.'
        Add-Type -AssemblyName PresentationFramework
        [System.Windows.MessageBox]::Show('This helper is not started manually. Open a network equipment record in GLPI and use its WinBox, PuTTY, or KiTTY action.', 'MikroTik Tools for GLPI', 'OK', 'Information') | Out-Null
        exit 0
    }
    if ($uri -match '^mikrotik://([a-f0-9]{64})/?$') {
        $client = 'winbox'
        $token = $Matches[1]
    } elseif ($uri -match '^mikrotik-(putty|kitty)://([a-f0-9]{64})/?$') {
        $client = $Matches[1].ToLowerInvariant()
        $token = $Matches[2]
    } else {
        throw 'Invalid launch code. Return to GLPI and try again.'
    }
    if ($token -notmatch '^[a-f0-9]{64}$') { throw 'Invalid launch code. Return to GLPI and try again.' }
    if ($client -ne 'winbox') {
        $clientName = if ($client -eq 'putty') { 'PuTTY' } else { 'KiTTY' }
        Write-SshTestLog ('event=protocol_received client=' + $client)
        $sshClient = Find-SshClient $client
        if (-not $sshClient) {
            Write-LaunchLog ($clientName + '.exe was not found; the launch code was not redeemed.')
            throw "$clientName.exe was not found. Run Winbox-GLPI-Setup.exe from the setup page again, then retry."
        }
        Write-LaunchLog ($clientName + ' executable found.')
    }
    Write-LaunchLog ('Launch code format valid for ' + $client + '; requesting connection details from GLPI.')

    $bodyData = @{token = $token}
    $redeemUri = $endpoint
    if ($client -ne 'winbox') {
        $bodyData.client = $client
        $redeemUri = $sshRedeemEndpoint
    }
    $connection = Invoke-RestMethod -Method Post -Uri $redeemUri -ContentType 'application/json' -Body ($bodyData | ConvertTo-Json -Compress) -TimeoutSec 15
    $credentialExchangeSucceeded = $true
    Write-LaunchLog ('GLPI connection exchange succeeded for ' + $client + '.')

    if ($client -ne 'winbox') {
        $executableName = if ($client -eq 'putty') { 'putty.exe' } else { 'kitty.exe' }
        $sshArgumentValues = @('-ssh', '-P', [string]$connection.port, '-l', [string]$connection.username)
        $savedPassword = [string]$connection.password
        if ([string]::IsNullOrEmpty($savedPassword)) {
            Write-SshTestLog ('event=credential_fields client=' + $client + ' password_present=false')
            throw 'No SSH password is saved for this device in GLPI. Ask a GLPI administrator to configure the device credentials before launching SSH.'
        }
        $passwordHasLineBreak = $savedPassword.Contains("`r") -or $savedPassword.Contains("`n")
        $passwordHasNonAscii = $savedPassword.ToCharArray() | Where-Object { [int]$_ -gt 127 } | Select-Object -First 1
        $passwordHasQuote = $savedPassword.Contains('"')
        Write-SshTestLog ('event=credential_fields client=' + $client + ' host_present=' + [bool]$connection.host + ' port_present=' + [bool]$connection.port + ' username_present=' + [bool]$connection.username + ' password_present=true password_has_linebreak=' + $passwordHasLineBreak + ' password_has_nonascii=' + [bool]$passwordHasNonAscii + ' password_has_quote=' + $passwordHasQuote)
        if ($passwordHasLineBreak) {
            throw 'The saved SSH password contains a line break and cannot be supplied automatically. Re-save it as a single-line password in GLPI.'
        }
        if ($client -eq 'kitty') {
            $sshArgumentValues += @('-pass', $savedPassword)
        } else {
            $versionText = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($sshClient).ProductVersion
            $versionMatch = [regex]::Match([string]$versionText, '(?:^|\D)(\d+)\.(\d+)(?:\D|$)')
            $major = 0
            $minor = 0
            $supportsPasswordFile = $versionMatch.Success -and [int]::TryParse($versionMatch.Groups[1].Value, [ref]$major) -and [int]::TryParse($versionMatch.Groups[2].Value, [ref]$minor) -and ($major -gt 0 -or $minor -ge 83)
            if (-not $supportsPasswordFile) {
                throw 'This PuTTY version cannot receive the saved password without an interactive prompt. Run the current MikroTik Tools for GLPI setup installer to install its managed PuTTY version.'
            }
            $privateDirectory = Join-Path $env:LOCALAPPDATA 'Winbox-GLPI'
            New-Item -ItemType Directory -Path $privateDirectory -Force | Out-Null
            $passwordFile = Join-Path $privateDirectory ('putty-password-' + [guid]::NewGuid().ToString('N') + '.tmp')
            [System.IO.File]::WriteAllText($passwordFile, $savedPassword + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))
            [System.IO.File]::SetAttributes($passwordFile, [System.IO.FileAttributes]::Hidden)
            $passwordFileMatches = [System.IO.File]::ReadAllText($passwordFile, [System.Text.UTF8Encoding]::new($false)) -ceq ($savedPassword + [Environment]::NewLine)
            $sshArgumentValues += @('-pwfile', $passwordFile)
            Write-SshTestLog ('event=auth_material_ready client=putty method=pwfile file_created=' + (Test-Path -LiteralPath $passwordFile) + ' file_matches_saved_value=' + $passwordFileMatches)
        }
        $sshArgumentValues += [string]$connection.host
        $sshArguments = $sshArgumentValues | ForEach-Object { ConvertTo-WindowsCommandLineArgument ([string]$_) }
        $sshStartInfo = [System.Diagnostics.ProcessStartInfo]::new()
        $sshStartInfo.FileName = $sshClient
        $sshStartInfo.Arguments = $sshArguments -join ' '
        $sshStartInfo.WorkingDirectory = Split-Path -Parent $sshClient
        $sshProcess = [System.Diagnostics.Process]::Start($sshStartInfo)
        if (-not $sshProcess) { throw "Windows could not start $clientName." }
        Write-SshTestLog ('event=process_started client=' + $client + ' pid=' + $sshProcess.Id + ' auth_result=unknown')
        if ($passwordFile) {
            try { $null = $sshProcess.WaitForInputIdle(5000) } catch { Start-Sleep -Seconds 1 }
            Start-Sleep -Milliseconds 500
            Write-SshTestLog ('event=pwfile_cleanup_started client=putty exists=' + (Test-Path -LiteralPath $passwordFile))
            Remove-Item -LiteralPath $passwordFile -Force -ErrorAction SilentlyContinue
            Write-SshTestLog ('event=pwfile_cleanup_finished client=putty exists=' + (Test-Path -LiteralPath $passwordFile))
            $passwordFile = $null
        }
        if ($client -eq 'kitty') {
            Write-LaunchLog 'KiTTY process started with saved credentials; password omitted from the launch log.'
        } else {
            Write-LaunchLog 'PuTTY process started; saved password supplied through a temporary password file.'
        }
        Send-LaunchReport $token 'started' $client
        $connection = $null
        $bodyData = $null
        $savedPassword = $null
        exit 0
    }

    $paths = @('C:\Tools\winbox.exe', 'C:\Program Files\WinBox\winbox.exe', 'C:\Program Files (x86)\WinBox\winbox.exe')
    $winbox = $paths | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if (-not $winbox) {
        $keys = @('HKCU:\Software\Microsoft\Windows\CurrentVersion\App Paths\winbox.exe', 'HKLM:\Software\Microsoft\Windows\CurrentVersion\App Paths\winbox.exe', 'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths\winbox.exe')
        foreach ($key in $keys) {
            if (Test-Path $key) {
                $candidate = (Get-Item $key).GetValue('')
                if ($candidate -and (Test-Path -LiteralPath $candidate)) { $winbox = $candidate; break }
            }
        }
    }
    if (-not $winbox) { throw 'Winbox.exe not found. Place it in C:\Tools or register it in Windows App Paths.' }
    Write-LaunchLog 'Winbox executable found.'

    $winboxArguments = @($connection.address, $connection.username, $connection.password) | ForEach-Object { ConvertTo-WindowsCommandLineArgument ([string]$_) }
    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $winbox
    $startInfo.Arguments = $winboxArguments -join ' '
    [void][System.Diagnostics.Process]::Start($startInfo)
    Write-LaunchLog 'Winbox process start returned successfully.'
    Send-LaunchReport $token 'started' $client
    $connection = $null
    $body = $null
} catch {
    if ($passwordFile) { Remove-Item -LiteralPath $passwordFile -Force -ErrorAction SilentlyContinue }
    if ($credentialExchangeSucceeded -and $token -match '^[a-f0-9]{64}$') {
        Send-LaunchReport $token 'failed' $client
    }
    $safeMessage = [string]$_.Exception.Message -replace 'mikrotik://[a-f0-9]{64}', 'mikrotik://[REDACTED]'
    Write-LaunchLog ('Launch failed: ' + $safeMessage)
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($_.Exception.Message, 'MikroTik Tools for GLPI', 'OK', 'Error') | Out-Null
    exit 1
}
