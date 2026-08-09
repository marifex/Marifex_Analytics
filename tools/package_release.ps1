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

$releaseRoot = Join-Path $repositoryRoot "versions\$Version"
New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
$finalArchive = Join-Path $releaseRoot "marifex-$Version.zip"
$candidateArchive = Join-Path $releaseRoot ("marifex-$Version.{0}.tmp.zip" -f [guid]::NewGuid().ToString('N'))
$directories = @('locales', 'public', 'src', 'templates', 'vendor')
$files = @('composer.json', 'hook.php', 'marifex.xml', 'README.md', 'setup.php')

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
        foreach ($requiredEntry in @('marifex/setup.php', 'marifex/public/css/marifex.css', 'marifex/public/js/dashboard.js')) {
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
