#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Crea un tag SemVer de release sobre master.

.DESCRIPTION
    Los tags git (vMAJOR.MINOR.PATCH) son la unica fuente de verdad de la
    version del backend. Jenkins los lee con `git describe --tags` y los
    inyecta en la app desplegada (config/version.php).

    Flujo: develop -> merge a master -> release.ps1 -Bump <x> -Push -> Jenkins.

.EXAMPLE
    ./scripts/release/release.ps1 -Bump patch          # fix retrocompatible
    ./scripts/release/release.ps1 -Bump minor -Push    # feature nueva + push
    ./scripts/release/release.ps1 -Bump major -DryRun  # solo muestra el calculo
#>
param(
    [Parameter(Mandatory)]
    [ValidateSet('major', 'minor', 'patch')]
    [string]$Bump,

    [switch]$Push,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# 1. Releases solo desde master (mismo gate que el Jenkinsfile)
$branch = (git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne 'master') {
    throw "Releases solo desde master (estas en '$branch'). Haz merge de develop a master primero."
}

# 2. Working tree limpio
if (git status --porcelain) {
    throw "Working tree sucio. Commitea o stashea antes de taggear."
}

# 3. Sincronizar tags con el remoto
git fetch --tags --quiet

# 4. Ultimo tag SemVer (o v0.0.0 si es el primer release)
$latest = git tag --list 'v*' --sort=-v:refname | Select-Object -First 1
if (-not $latest) { $latest = 'v0.0.0' }
if ($latest -notmatch '^v(\d+)\.(\d+)\.(\d+)$') {
    throw "El ultimo tag no es SemVer valido: $latest"
}

$major = [int]$Matches[1]
$minor = [int]$Matches[2]
$patch = [int]$Matches[3]

switch ($Bump) {
    'major' { $major++; $minor = 0; $patch = 0 }
    'minor' { $minor++; $patch = 0 }
    'patch' { $patch++ }
}
$next = "v$major.$minor.$patch"

if (git tag --list $next) {
    throw "El tag $next ya existe."
}

Write-Host "Version actual: $latest"
Write-Host "Nueva version:  $next  ($Bump)"

if ($DryRun) {
    Write-Host "[dry-run] no se creo ningun tag."
    exit 0
}

git tag -a $next -m "Release $next"
Write-Host "Tag $next creado localmente."

if ($Push) {
    git push origin $next
    Write-Host "Tag $next pusheado a origin. Lanza el deploy de produccion en Jenkins."
}
else {
    Write-Host "Para publicarlo: git push origin $next"
}
