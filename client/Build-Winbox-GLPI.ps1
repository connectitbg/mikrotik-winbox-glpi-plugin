param(
    [string]$OutputPath = (Join-Path $PSScriptRoot 'Winbox-GLPI-Setup.exe')
)

$ErrorActionPreference = 'Stop'
$compiler = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path -LiteralPath $compiler)) {
    $compiler = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'
}
if (-not (Test-Path -LiteralPath $compiler)) {
    throw '.NET Framework C# compiler was not found. Install .NET Framework 4.x developer tools to build the client installer.'
}

$source = Join-Path $PSScriptRoot 'WinboxGlpiClient.cs'
$clientInstallerSource = Join-Path $PSScriptRoot 'SshClientInstaller.cs'
$diagnosticClassifierSource = Join-Path $PSScriptRoot 'SshDiagnosticClassifier.cs'
if (-not (Test-Path -LiteralPath $source)) {
    throw "Client source file was not found: $source"
}
if (-not (Test-Path -LiteralPath $clientInstallerSource)) {
    throw "SSH client installer source file was not found: $clientInstallerSource"
}
if (-not (Test-Path -LiteralPath $diagnosticClassifierSource)) {
    throw "SSH diagnostic classifier source file was not found: $diagnosticClassifierSource"
}

$outputDirectory = Split-Path -Parent $OutputPath
if ($outputDirectory) {
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
}

& $compiler /nologo /target:winexe /platform:anycpu /optimize+ `
    "/out:$OutputPath" `
    /reference:System.Core.dll `
    /reference:System.Windows.Forms.dll `
    /reference:System.Web.Extensions.dll `
    /reference:System.IO.Compression.dll `
    $source `
    $clientInstallerSource `
    $diagnosticClassifierSource
if ($LASTEXITCODE -ne 0) {
    throw "C# compilation failed with exit code $LASTEXITCODE."
}

$binary = [System.IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $OutputPath))
$marker = [System.Text.Encoding]::Unicode.GetBytes(('~' * 256))
$matches = 0
for ($offset = 0; $offset -le $binary.Length - $marker.Length; $offset++) {
    $match = $true
    for ($index = 0; $index -lt $marker.Length; $index++) {
        if ($binary[$offset + $index] -ne $marker[$index]) {
            $match = $false
            break
        }
    }
    if ($match) {
        $matches++
        $offset += $marker.Length - 1
    }
}
if ($matches -ne 1) {
    throw "The GLPI server URL marker must occur exactly once in the executable; found $matches."
}

Get-Item -LiteralPath $OutputPath | Select-Object FullName, Length, LastWriteTime
