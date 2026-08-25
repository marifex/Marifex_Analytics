param(
    [string]$Version = ''
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path

if ($Version -eq '') {
    $setup = Get-Content -LiteralPath (Join-Path $repositoryRoot 'setup.php') -Raw
    $match = [regex]::Match($setup, "PLUGIN_MARIFEX_VERSION',\s*'([^']+)'\)")
    if (-not $match.Success) {
        throw 'Could not read the plugin version from setup.php.'
    }
    $Version = $match.Groups[1].Value
}

if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?$') {
    throw "Invalid release version: $Version"
}

$manifestVersions = @{
    'composer.json' = (Get-Content -LiteralPath (Join-Path $repositoryRoot 'composer.json') -Raw | ConvertFrom-Json).version
    'package.json' = (Get-Content -LiteralPath (Join-Path $repositoryRoot 'package.json') -Raw | ConvertFrom-Json).version
    'marifex.xml' = ([regex]::Match((Get-Content -LiteralPath (Join-Path $repositoryRoot 'marifex.xml') -Raw), '<version>([^<]+)</version>')).Groups[1].Value
}
$packageLockText = Get-Content -LiteralPath (Join-Path $repositoryRoot 'package-lock.json') -Raw
$packageLockVersions = [regex]::Matches($packageLockText, '"version"\s*:\s*"([^"]+)"')
if ($packageLockVersions.Count -lt 2) {
    throw 'Could not read root versions from package-lock.json.'
}
$manifestVersions['package-lock.json'] = $packageLockVersions[0].Groups[1].Value
$manifestVersions['package-lock.json packages root'] = $packageLockVersions[1].Groups[1].Value
$readme = Get-Content -LiteralPath (Join-Path $repositoryRoot 'README.md') -Raw
if (-not $readme.Contains($Version)) {
    throw "Version mismatch: README.md does not contain $Version"
}
$composer = Get-Content -LiteralPath (Join-Path $repositoryRoot 'composer.json') -Raw | ConvertFrom-Json
$package = Get-Content -LiteralPath (Join-Path $repositoryRoot 'package.json') -Raw | ConvertFrom-Json
$pluginManifest = Get-Content -LiteralPath (Join-Path $repositoryRoot 'marifex.xml') -Raw
$setupMetadata = Get-Content -LiteralPath (Join-Path $repositoryRoot 'setup.php') -Raw
if (-not ($composer.authors | Where-Object { $_.name -eq 'MarifeX' })) {
    throw 'Authorship mismatch: composer.json must identify MarifeX as an author.'
}
if ($package.author -ne 'MarifeX') {
    throw 'Authorship mismatch: package.json must identify MarifeX as the author.'
}
if ($pluginManifest -notmatch '<authors>\s*<author>MarifeX</author>\s*</authors>') {
    throw 'Authorship mismatch: marifex.xml must identify MarifeX as an author.'
}
if ($setupMetadata -notmatch "'author'\s*=>\s*'MarifeX'") {
    throw 'Authorship mismatch: setup.php must identify MarifeX as the author.'
}
if ($readme -notmatch 'authored and maintained by MarifeX') {
    throw 'Authorship mismatch: README.md must identify MarifeX ownership.'
}
if ($composer.license -ne 'GPL-3.0-only') {
    throw 'License mismatch: composer.json must declare GPL-3.0-only.'
}
if (-not ($composer.authors | Where-Object { $_.homepage -eq 'https://www.marifextech.com' })) {
    throw 'Contact mismatch: composer.json must identify the approved MarifeX Technologies website.'
}
if ($pluginManifest -notmatch '<homepage>https://www.marifextech.com</homepage>') {
    throw 'Contact mismatch: marifex.xml must identify the approved MarifeX Technologies website.'
}
if ($setupMetadata -notmatch "'homepage'\s*=>\s*'https://www.marifextech.com'") {
    throw 'Contact mismatch: setup.php must identify the approved MarifeX Technologies website.'
}
if ($readme -notmatch 'https://www.marifextech.com') {
    throw 'Contact mismatch: README.md must identify the approved MarifeX Technologies website.'
}
if ($readme -notmatch 'mohammed@marifextech.com') {
    throw 'Contact mismatch: README.md must identify the approved support email.'
}
if ($pluginManifest -notmatch '<license>GPL-3.0-only</license>') {
    throw 'License mismatch: marifex.xml must declare GPL-3.0-only.'
}
if ($setupMetadata -notmatch "'license'\s*=>\s*'GPL-3.0-only'") {
    throw 'License mismatch: setup.php must declare GPL-3.0-only.'
}
if ($readme -notmatch 'GNU General Public License version 3') {
    throw 'License mismatch: README.md must identify GPLv3.'
}
if (-not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'LICENSE'))) {
    throw 'License mismatch: canonical GPLv3 LICENSE file is missing.'
}
foreach ($manifest in $manifestVersions.GetEnumerator()) {
    if ($manifest.Value -ne $Version) {
        throw "Version mismatch: $($manifest.Key) contains $($manifest.Value), expected $Version"
    }
}

$releaseRoot = Join-Path $repositoryRoot "versions\$Version"
New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
$finalArchive = Join-Path $releaseRoot "marifex-$Version.zip"
$candidateArchive = Join-Path $releaseRoot ("marifex-$Version.{0}.tmp.zip" -f [guid]::NewGuid().ToString('N'))
$directories = @('locales', 'public', 'Screenshots', 'src', 'templates', 'vendor')
$files = @('Adminsetup.md', 'CHANGELOG.md', 'composer.json', 'hook.php', 'LICENSE', 'marifex.xml', 'README.md', 'setup.php')

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

try {
    $archive = [System.IO.Compression.ZipFile]::Open($candidateArchive, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($directory in $directories) {
            $sourceDirectory = Join-Path $repositoryRoot $directory
            Get-ChildItem -LiteralPath $sourceDirectory -Recurse -File | ForEach-Object {
                $relativePath = $_.FullName.Substring($repositoryRoot.Length + 1).Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive,
                    $_.FullName,
                    "marifex/$relativePath",
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        }

        foreach ($file in $files) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                (Join-Path $repositoryRoot $file),
                "marifex/$file",
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }

    $verification = [System.IO.Compression.ZipFile]::OpenRead($candidateArchive)
    try {
        $entryNames = @($verification.Entries | ForEach-Object FullName)
        $invalidEntry = $entryNames | Where-Object { $_ -match '\\' -or -not $_.StartsWith('marifex/') } | Select-Object -First 1
        if ($null -ne $invalidEntry) {
            throw "Invalid ZIP entry path: $invalidEntry"
        }
        foreach ($requiredEntry in @('marifex/setup.php', 'marifex/LICENSE', 'marifex/public/css/marifex.css', 'marifex/public/js/dashboard.js')) {
            if ($requiredEntry -notin $entryNames) {
                throw "Required ZIP entry is missing: $requiredEntry"
            }
        }
    } finally {
        $verification.Dispose()
    }

    Move-Item -LiteralPath $candidateArchive -Destination $finalArchive -Force
    Get-FileHash -Algorithm SHA256 -LiteralPath $finalArchive
} finally {
    if (Test-Path -LiteralPath $candidateArchive) {
        Remove-Item -LiteralPath $candidateArchive -Force
    }
}
