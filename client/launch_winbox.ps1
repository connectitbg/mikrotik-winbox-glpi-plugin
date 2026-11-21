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

function Write-LaunchLog([string]$Message) {
    try {
        Add-Content -LiteralPath $launchLog -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Message" -Encoding UTF8
    } catch {
        # Diagnostics must never prevent a launch.
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

try {
    Write-LaunchLog ('Handler started; URI argument present: ' + (-not [string]::IsNullOrWhiteSpace($ProtocolUri)))
    $uri = ([string]$ProtocolUri).Trim().Trim('"').Trim("'")
    if ([string]::IsNullOrWhiteSpace($uri)) {
        Write-LaunchLog 'No URI argument received.'
        Add-Type -AssemblyName PresentationFramework
        [System.Windows.MessageBox]::Show('Този помощен скрипт не се стартира ръчно. Отворете мрежовото устройство в GLPI и използвайте бутон за Winbox, PuTTY или KiTTY.', 'Winbox - GLPI', 'OK', 'Information') | Out-Null
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
    Write-LaunchLog ('Launch code format valid for ' + $client + '; requesting connection details from GLPI.')

    $bodyData = @{token = $token}
    $redeemUri = $endpoint
    if ($client -ne 'winbox') {
        $bodyData.client = $client
        $redeemUri = $sshRedeemEndpoint
    }
    $connection = Invoke-RestMethod -Method Post -Uri $redeemUri -ContentType 'application/json' -Body ($bodyData | ConvertTo-Json -Compress) -TimeoutSec 15
    Write-LaunchLog ('GLPI connection exchange succeeded for ' + $client + '.')

    if ($client -ne 'winbox') {
        $clientName = if ($client -eq 'putty') { 'PuTTY' } else { 'KiTTY' }
        $executableName = if ($client -eq 'putty') { 'putty.exe' } else { 'kitty.exe' }
        $folderName = if ($client -eq 'putty') { 'PuTTY' } else { 'KiTTY' }
        $candidates = @("C:\Tools\$executableName", "C:\Tools\$folderName\$executableName", (Join-Path $env:ProgramFiles "$folderName\$executableName"), (Join-Path ${env:ProgramFiles(x86)} "$folderName\$executableName"))
        $sshClient = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
        if (-not $sshClient) {
            foreach ($root in @('HKCU:\Software\Microsoft\Windows\CurrentVersion\App Paths', 'HKLM:\Software\Microsoft\Windows\CurrentVersion\App Paths', 'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths')) {
                $key = Join-Path $root $executableName
                if (Test-Path -LiteralPath $key) {
                    $candidate = [Environment]::ExpandEnvironmentVariables((Get-Item -LiteralPath $key).GetValue(''))
                    if (Test-Path -LiteralPath $candidate) { $sshClient = $candidate; break }
                }
            }
        }
        if (-not $sshClient) { throw "$clientName.exe was not found. Install it or place it in C:\Tools." }
        $sshArgumentValues = @('-ssh', '-P', [string]$connection.port, '-l', [string]$connection.username)
        $savedPassword = [string]$connection.password
        if ($savedPassword) {
            if ($client -eq 'kitty') {
                $sshArgumentValues += @('-pass', $savedPassword)
            } else {
                $versionText = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($sshClient).ProductVersion
                $versionMatch = [regex]::Match([string]$versionText, '(?:^|\D)(\d+)\.(\d+)(?:\D|$)')
                $major = 0
                $minor = 0
                $supportsPasswordFile = $versionMatch.Success -and [int]::TryParse($versionMatch.Groups[1].Value, [ref]$major) -and [int]::TryParse($versionMatch.Groups[2].Value, [ref]$minor) -and ($major -gt 0 -or $minor -ge 83)
                if ($supportsPasswordFile) {
                    $privateDirectory = Join-Path $env:LOCALAPPDATA 'Winbox-GLPI'
                    New-Item -ItemType Directory -Path $privateDirectory -Force | Out-Null
                    $passwordFile = Join-Path $privateDirectory ('putty-password-' + [guid]::NewGuid().ToString('N') + '.tmp')
                    [System.IO.File]::WriteAllText($passwordFile, $savedPassword + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))
                    [System.IO.File]::SetAttributes($passwordFile, [System.IO.FileAttributes]::Hidden)
                    $sshArgumentValues += @('-pwfile', $passwordFile)
                }
            }
        }
        $sshArgumentValues += [string]$connection.host
        $sshArguments = $sshArgumentValues | ForEach-Object { ConvertTo-WindowsCommandLineArgument ([string]$_) }
        $sshStartInfo = [System.Diagnostics.ProcessStartInfo]::new()
        $sshStartInfo.FileName = $sshClient
        $sshStartInfo.Arguments = $sshArguments -join ' '
        $sshStartInfo.WorkingDirectory = Split-Path -Parent $sshClient
        $sshProcess = [System.Diagnostics.Process]::Start($sshStartInfo)
        if (-not $sshProcess) { throw "Windows could not start $clientName." }
        if ($passwordFile) {
            try { $null = $sshProcess.WaitForInputIdle(5000) } catch { Start-Sleep -Seconds 1 }
            Start-Sleep -Milliseconds 500
            Remove-Item -LiteralPath $passwordFile -Force -ErrorAction SilentlyContinue
            $passwordFile = $null
        }
        if ($savedPassword) {
            if ($client -eq 'kitty') {
                Write-LaunchLog 'KiTTY process started with saved credentials; password omitted from the launch log.'
            } elseif ($supportsPasswordFile) {
                Write-LaunchLog 'PuTTY process started; saved password supplied through a temporary password file.'
            } else {
                Write-LaunchLog 'PuTTY version lacks -pwfile; started without automatic password.'
            }
        } else {
            Write-LaunchLog ($clientName + ' process started without a saved password.')
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
    if ($token -match '^[a-f0-9]{64}$') {
        Send-LaunchReport $token 'failed' $client
    }
    $safeMessage = [string]$_.Exception.Message -replace 'mikrotik://[a-f0-9]{64}', 'mikrotik://[REDACTED]'
    Write-LaunchLog ('Launch failed: ' + $safeMessage)
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($_.Exception.Message, 'Winbox - GLPI', 'OK', 'Error') | Out-Null
    exit 1
}
