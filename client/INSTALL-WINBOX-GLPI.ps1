$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$serverBase = '__WINBOX_GLPI_BASE_URL__'
$installDirectory = $null
$helperPath = $null
$temporaryHelper = Join-Path $env:TEMP ('winbox-glpi-helper-' + [guid]::NewGuid().ToString('N') + '.ps1')

try {
    if ($serverBase -like '__WINBOX_GLPI_*__') {
        throw 'This installer was not downloaded from the Winbox - GLPI setup page.'
    }

    $helperUrl = $serverBase.TrimEnd('/') + '/plugins/winbox/InstallClient?file=launch_winbox.ps1'
    Invoke-WebRequest -UseBasicParsing -Uri $helperUrl -OutFile $temporaryHelper -TimeoutSec 30
    $helperContent = Get-Content -LiteralPath $temporaryHelper -Raw -Encoding UTF8
    if ($helperContent.Contains('__WINBOX_GLPI_REDEEM_URL__') -or $helperContent -notmatch 'winbox-glpi-launch\.log') {
        throw 'The downloaded helper is incomplete or was not configured by GLPI.'
    }

    if ([string]::IsNullOrWhiteSpace($env:LOCALAPPDATA)) {
        throw 'Windows did not provide a local application data folder for this user.'
    }
    $installDirectory = Join-Path $env:LOCALAPPDATA 'Winbox-GLPI'
    $helperPath = Join-Path $installDirectory 'launch_winbox.ps1'
    New-Item -ItemType Directory -Path $installDirectory -Force | Out-Null
    Copy-Item -LiteralPath $temporaryHelper -Destination $helperPath -Force

    $protocols = @{
        'mikrotik' = 'URL:MikroTik Winbox Protocol'
        'mikrotik-putty' = 'URL:MikroTik SSH via PuTTY Protocol'
        'mikrotik-kitty' = 'URL:MikroTik SSH via KiTTY Protocol'
    }
    $handlerCommand = 'powershell.exe -WindowStyle Hidden -NoLogo -NoProfile -ExecutionPolicy Bypass -File "' + $helperPath + '" "%1"'
    foreach ($protocol in $protocols.Keys) {
        $protocolKey = 'HKCU:\Software\Classes\' + $protocol
        $commandKey = Join-Path $protocolKey 'shell\open\command'
        New-Item -Path $commandKey -Force | Out-Null
        Set-Item -LiteralPath $protocolKey -Value $protocols[$protocol]
        New-ItemProperty -LiteralPath $protocolKey -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
        Set-Item -LiteralPath $commandKey -Value $handlerCommand

        $effectivePath = 'Registry::HKEY_CLASSES_ROOT\' + $protocol + '\shell\open\command'
        $effectiveCommand = (Get-Item -LiteralPath $effectivePath).GetValue('')
        if ($effectiveCommand.IndexOf($helperPath, [System.StringComparison]::OrdinalIgnoreCase) -lt 0) {
            throw "Windows is not using the current $protocol handler. Contact the GLPI administrator."
        }
    }

    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show('Winbox - GLPI е инсталиран за текущия Windows потребител. Обработчиците за Winbox, PuTTY и KiTTY са регистрирани. За SSH инсталирайте PuTTY или KiTTY отделно. Помощникът и логът са в ' + $installDirectory + '.', 'Winbox - GLPI', 'OK', 'Information') | Out-Null
} catch {
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($_.Exception.Message, 'Winbox - GLPI — грешка при инсталиране', 'OK', 'Error') | Out-Null
    exit 1
} finally {
    Remove-Item -LiteralPath $temporaryHelper -Force -ErrorAction SilentlyContinue
}
