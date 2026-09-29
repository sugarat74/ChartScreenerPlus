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

if (Test-Path "frontend/package.json") {
    if (-not (Test-Path "frontend/node_modules")) {
        Write-Host "frontend/node_modules missing - installing npm dependencies..."
        Invoke-Checked npm @("--prefix", "frontend", "install")
    }
    Write-Host "Typechecking and linting the SPA..."
    Invoke-Checked npm @("--prefix", "frontend", "run", "lint")
    Write-Host "Building the SPA..."
    Invoke-Checked npm @("--prefix", "frontend", "run", "build")
}

if (Test-Path "engine/requirements.txt") {
    $enginePy = "engine\.venv\Scripts\python.exe"
    if (-not (Test-Path $enginePy)) {
        Write-Host "engine venv missing - creating and installing Python dependencies..."
        Invoke-Checked python @("-m", "venv", "engine\.venv")
        Invoke-Checked $enginePy @("-m", "pip", "install", "-r", "engine\requirements-dev.txt")
    }
    Write-Host "Running the engine tests..."
    Push-Location engine
    try {
        Invoke-Checked ".venv\Scripts\python.exe" @("-m", "pytest", "-q")
    } finally {
        Pop-Location
    }
}

Write-Host ""
Write-Host "Baseline OK."
Write-Host "Manual follow-up (not run by this gate):"
Write-Host "  php artisan serve --port=8123          # Laravel API / admin"
Write-Host "  npm --prefix frontend run dev          # SPA dev server (http://localhost:5173)"
Write-Host "  engine\.venv\Scripts\python.exe -m app  # engine (http://127.0.0.1:8090)"
