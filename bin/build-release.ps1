<#
  Build a deploy-ready plugin zip from a committed git ref.

  Produces dist/mclb-lane-booking.zip with the plugin inside a top-level folder
  named "mclb-lane-booking/", so WordPress installs it to the correct directory
  and never creates a duplicate (the problem with GitHub's auto-named source
  zips, whose folder is "moBooking-<ref>").

  Uses `git archive`, so ONLY committed, tracked files ship, and anything marked
  `export-ignore` in .gitattributes (dist, bin, tests, CI, dotfiles) is excluded.

  Usage:
    powershell -File bin/build-release.ps1              # from HEAD
    powershell -File bin/build-release.ps1 v1.1.2       # from a tag (use this for releases)
#>
param([string]$Ref = 'HEAD')

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot           # repo root (bin/..)
$dist = Join-Path $root 'dist'
# Name the zip after the ref (e.g. mclb-lane-booking-v1.4.3.zip); the folder
# PREFIX inside stays mclb-lane-booking/ so WordPress installs it correctly.
$safeRef = ($Ref -replace '[^A-Za-z0-9._-]', '_')
$out     = Join-Path $dist ("mclb-lane-booking-$safeRef.zip")

# Locate git: PATH first, else the git bundled with GitHub Desktop.
$git = (Get-Command git -ErrorAction SilentlyContinue).Source
if (-not $git) {
  $git = Get-ChildItem "$env:LOCALAPPDATA\GitHubDesktop" -Recurse -Filter git.exe -ErrorAction SilentlyContinue |
         Where-Object { $_.FullName -match 'cmd\\git\.exe$' } | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $git) { throw 'git not found (not on PATH and no GitHub Desktop install detected).' }

# Refuse anything that is not a committed revision — releases ship from a tag.
& $git -C $root rev-parse --verify --quiet "$Ref^{commit}" > $null
if ($LASTEXITCODE -ne 0) { throw "Ref '$Ref' is not a committed revision. Commit or tag it first." }

New-Item -ItemType Directory -Force -Path $dist | Out-Null
if (Test-Path $out) { Remove-Item $out -Force }

& $git -C $root archive --format=zip --prefix=mclb-lane-booking/ -o $out $Ref
if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }

$kb = [math]::Round((Get-Item $out).Length / 1KB, 1)
Write-Host "Built $out ($kb KB) from $Ref."
Write-Host "Deploy: WP Admin -> Plugins -> Add New -> Upload Plugin -> choose this zip -> 'Replace current with uploaded'."
