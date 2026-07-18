param([string]$Version = "2.14.14")
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dist = Join-Path $Root "dist"
$Stage = Join-Path $Dist "mkauth-geocodificacao-$Version"
Remove-Item -Recurse -Force $Dist -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $Stage | Out-Null
Copy-Item -Recurse (Join-Path $Root "addons") $Stage
Copy-Item -Recurse (Join-Path $Root "installers") $Stage
Copy-Item -Recurse (Join-Path $Root "docs") $Stage
Copy-Item (Join-Path $Root "README.md") $Stage
Copy-Item (Join-Path $Root "VERSION") $Stage
$Archive = Join-Path $Dist "mkauth-geocodificacao-$Version.tar.gz"
tar -czf $Archive -C $Dist "mkauth-geocodificacao-$Version"
$Hash = (Get-FileHash -Algorithm SHA256 $Archive).Hash.ToLowerInvariant()
Set-Content -Encoding ascii -Path "$Archive.sha256" -Value "$Hash  mkauth-geocodificacao-$Version.tar.gz"
$Run = Join-Path $Dist "mkauth-geocodificacao-$Version.run"
$Header = @(
    '#!/bin/sh',
    'set -eu',
    'SELF="$0"',
    'TMP=$(mktemp -d /tmp/mkauth-geocodificacao.XXXXXX)',
    'trap ''rm -rf "$TMP"'' EXIT HUP INT TERM',
    'LINE=$(awk ''/^__ARCHIVE_BELOW__$/ { print NR + 1; exit }'' "$SELF")',
    '[ -n "$LINE" ] || { echo "ERRO: pacote invalido" >&2; exit 1; }',
    'tail -n +"$LINE" "$SELF" | tar -xzf - -C "$TMP"',
    ('exec sh "$TMP/mkauth-geocodificacao-' + $Version + '/installers/install-all.sh"'),
    'exit 0',
    '__ARCHIVE_BELOW__',
    ''
) -join "`n"
$HeaderBytes = [Text.Encoding]::ASCII.GetBytes($Header)
$ArchiveBytes = [IO.File]::ReadAllBytes($Archive)
$RunBytes = New-Object byte[] ($HeaderBytes.Length + $ArchiveBytes.Length)
[Array]::Copy($HeaderBytes, 0, $RunBytes, 0, $HeaderBytes.Length)
[Array]::Copy($ArchiveBytes, 0, $RunBytes, $HeaderBytes.Length, $ArchiveBytes.Length)
[IO.File]::WriteAllBytes($Run, $RunBytes)
$RunHash = (Get-FileHash -Algorithm SHA256 $Run).Hash.ToLowerInvariant()
Set-Content -Encoding ascii -Path "$Run.sha256" -Value "$RunHash  mkauth-geocodificacao-$Version.run"
Write-Host "Gerado: $Archive"
Write-Host "SHA256: $Hash"
Write-Host "Gerado: $Run"
Write-Host "SHA256: $RunHash"
