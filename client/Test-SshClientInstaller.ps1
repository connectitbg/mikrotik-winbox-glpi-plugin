$ErrorActionPreference = 'Stop'
$compilerPath = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path -LiteralPath $compilerPath)) {
    $compilerPath = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'
}
if (-not (Test-Path -LiteralPath $compilerPath)) {
    throw '.NET Framework C# compiler was not found.'
}

$testSource = Join-Path $PSScriptRoot 'tests\SshClientInstallerSmokeTest.cs'
$installerSource = Join-Path $PSScriptRoot 'SshClientInstaller.cs'
$diagnosticClassifierSource = Join-Path $PSScriptRoot 'SshDiagnosticClassifier.cs'
$testExePath = Join-Path $env:TEMP ('WinboxGlpiInstallerSmokeTest-' + [guid]::NewGuid().ToString('N') + '.exe')

try {
    & $compilerPath /nologo /target:exe /platform:anycpu /optimize+ "/out:$testExePath" `
        /reference:System.IO.Compression.dll `
        $installerSource `
        $diagnosticClassifierSource `
        $testSource
    if ($LASTEXITCODE -ne 0) {
        throw "Installer smoke-test compilation failed with exit code $LASTEXITCODE."
    }

    & $testExePath
    if ($LASTEXITCODE -ne 0) {
        throw "Installer smoke tests failed with exit code $LASTEXITCODE."
    }
}
finally {
    if (Test-Path -LiteralPath $testExePath) {
        Remove-Item -LiteralPath $testExePath -Force
    }
}
