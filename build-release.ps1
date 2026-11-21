Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repository = Split-Path -Parent $MyInvocation.MyCommand.Path
$setupFile = Join-Path $repository 'setup.php'
$setupSource = Get-Content -LiteralPath $setupFile -Raw
if ($setupSource -notmatch "'version'\s*=>\s*'([^']+)'" ) {
    throw 'Could not read the plugin version from setup.php.'
}
$version = $Matches[1]

$dist = Join-Path $repository 'dist'
$archive = Join-Path $dist "glpi-winbox-plugin-$version.zip"
$staging = Join-Path ([System.IO.Path]::GetTempPath()) ("winbox-glpi-package-" + [guid]::NewGuid().ToString('N'))
$pluginDirectory = Join-Path $staging 'winbox'

New-Item -ItemType Directory -Path $dist -Force | Out-Null
New-Item -ItemType Directory -Path $pluginDirectory -Force | Out-Null

$excluded = @('.git', '.github', 'docs', 'dist', 'build-release.ps1', 'winbox.xml', 'logo.svg', '.gitignore')
Get-ChildItem -LiteralPath $repository -Force |
    Where-Object { $_.Name -notin $excluded } |
    ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $pluginDirectory -Recurse -Force }

if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}
Compress-Archive -Path $pluginDirectory -DestinationPath $archive -CompressionLevel Optimal
Remove-Item -LiteralPath $staging -Recurse -Force

Write-Output "Created $archive"
