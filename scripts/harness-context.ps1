#Requires -Version 5.1
param(
    [string]$RepositoryPath = (Join-Path $PSScriptRoot '..')
)

$ErrorActionPreference = 'Stop'

function Shorten([string]$Value, [int]$Limit = 180) {
    $clean = ($Value -replace '\s+', ' ').Trim()
    if ($clean.Length -le $Limit) { return $clean }
    return $clean.Substring(0, $Limit - 3) + '...'
}

$root = (Resolve-Path -LiteralPath $RepositoryPath).Path
$progressPath = Join-Path $root 'PROGRESS.md'
$featuresPath = Join-Path $root 'feature_list.json'
$risksPath = Join-Path $root 'docs\risks-and-open-questions.md'

foreach ($path in @($progressPath, $featuresPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required harness file missing: $path"
    }
}

$features = Get-Content -LiteralPath $featuresPath -Raw -Encoding UTF8 | ConvertFrom-Json
$byId = @{}
foreach ($feature in $features) { $byId[[string]$feature.id] = $feature }

$ready = @()
$awaitingValidation = @()
$blocked = @()
foreach ($feature in $features) {
    if ($feature.status -eq 'accepted') { continue }

    $unacceptedDependencies = @($feature.depends_on | Where-Object {
        -not $byId.ContainsKey([string]$_) -or $byId[[string]$_].status -ne 'accepted'
    })
    if ($unacceptedDependencies.Count -gt 0) {
        $blocked += [pscustomobject]@{ id = $feature.id; dependencies = $unacceptedDependencies }
    } elseif ($feature.status -eq 'passing') {
        $awaitingValidation += $feature
    } else {
        $ready += $feature
    }
}

$progressLines = @(Get-Content -LiteralPath $progressPath -Encoding UTF8)
$latestHeading = $progressLines | Where-Object { $_ -match '^## ' } | Select-Object -First 1
$verificationHeading = $null
$verificationLine = $null
$currentHeading = $null
foreach ($line in $progressLines) {
    if ($line -match '^## ') { $currentHeading = $line }
    if ($currentHeading -and $line -match '^- ' -and
        $line -match '(?i)(verified|passed|tests|checks|comparison|baseline|exit 0)') {
        $verificationHeading = $currentHeading
        $verificationLine = $line
        break
    }
}

$branch = '(not a Git repository)'
$changes = @()
if ((Test-Path -LiteralPath (Join-Path $root '.git')) -and (Get-Command git -ErrorAction SilentlyContinue)) {
    $branchResult = & git -C $root branch --show-current 2>$null
    if ($LASTEXITCODE -eq 0) {
        $branch = if ($branchResult) { [string]$branchResult } else { '(detached HEAD)' }
        $changes = @(& git -C $root status --short --untracked-files=normal 2>$null)
    }
}

Write-Output "Repository: $root"
Write-Output "Git: $branch; $($changes.Count) local change(s)"
foreach ($change in ($changes | Select-Object -First 5)) { Write-Output "  $change" }
if ($changes.Count -gt 5) { Write-Output "  ... and $($changes.Count - 5) more" }

if ($latestHeading) { Write-Output "Latest progress: $(Shorten ($latestHeading -replace '^## ', ''))" }
if ($verificationHeading) {
    Write-Output "Latest recorded verification: $(Shorten ($verificationHeading -replace '^## ', ''))"
    Write-Output "  $(Shorten ($verificationLine -replace '^- ', ''))"
}

if ($awaitingValidation.Count -gt 0) {
    Write-Output "Awaiting independent validation: $((@($awaitingValidation | ForEach-Object { $_.id }) -join ', '))"
} else {
    Write-Output 'Awaiting independent validation: none'
}
if ($ready.Count -gt 0) {
    Write-Output "Next implementation-ready feature: $($ready[0].id) [$($ready[0].status)]"
} else {
    Write-Output 'Next implementation-ready feature: none'
}
if ($blocked.Count -gt 0) {
    Write-Output "Blocked unfinished features: $($blocked.Count)"
    foreach ($item in ($blocked | Select-Object -First 3)) {
        Write-Output "  $($item.id): waiting for $($item.dependencies -join ', ')"
    }
}

if (Test-Path -LiteralPath $risksPath -PathType Leaf) {
    $inBlockingSection = $false
    $riskTitles = @()
    foreach ($line in (Get-Content -LiteralPath $risksPath -Encoding UTF8)) {
        if ($line -match '^## ') {
            if ($inBlockingSection) { break }
            $inBlockingSection = $line -match '^## Blocking Next Phase\s*$'
            continue
        }
        if ($inBlockingSection -and $line -match '^- \*\*(.+?)\*\*') {
            $riskTitles += (Shorten (($Matches[1].TrimEnd(':')) -replace '`', '') 65)
        }
    }
    if ($riskTitles.Count -gt 0) {
        Write-Output "Open risks (docs/risks-and-open-questions.md): $($($riskTitles | Select-Object -First 3) -join '; ')"
    }
}
