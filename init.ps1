#Requires -Version 5.1
$ErrorActionPreference = "Stop"

if ($PSScriptRoot) { Set-Location $PSScriptRoot }

function Invoke-Checked {
    param([string]$Exe, [string[]]$CmdArgs)
    & $Exe @CmdArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code $LASTEXITCODE`: $Exe $($CmdArgs -join ' ')"
    }
}

Write-Host "Repository: $(Get-Location)"

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "PHP is not on PATH. Add Laragon's PHP (e.g. C:\laragon\bin\php\php-8.4.8-Win32-vs17-x64) to PATH."
}

if (-not (Test-Path "vendor")) {
    Write-Host "vendor/ missing - installing Composer dependencies..."
    Invoke-Checked composer @("install", "--no-interaction")
}

Write-Host "Checking Laravel version..."
Invoke-Checked php @("artisan", "--version")

Write-Host "Running the test suite..."
Invoke-Checked php @("artisan", "test")

Write-Host ""
Write-Host "Baseline OK."
Write-Host "Manual follow-up (not run by this gate):"
Write-Host "  php artisan serve --port=8123   # start the dev server"
