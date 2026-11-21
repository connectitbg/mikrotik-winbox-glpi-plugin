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
if (-not (Test-Path -LiteralPath $source)) {
    throw "Client source file was not found: $source"
}

$outputDirectory = Split-Path -Parent $OutputPath
if ($outputDirectory) {
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
}

& $compiler /nologo /target:winexe /platform:anycpu /optimize+ `
    "/out:$OutputPath" `
    /reference:System.Windows.Forms.dll `
    /reference:System.Web.Extensions.dll `
    $source
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
