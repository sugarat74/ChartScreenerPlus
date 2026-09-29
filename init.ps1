#Requires -Version 5.1
$ErrorActionPreference = "Stop"

if ($PSScriptRoot) { Set-Location $PSScriptRoot }

Write-Host "Repository: $(Get-Location)"
Write-Host "Harness status: pre-bootstrap"
Write-Host "The AlphaPulse product stack (Laravel + Python engine + React SPA) is not initialized yet."
Write-Host "The 'alphapulse' directory is a UI reference prototype only; it is not the product baseline."
Write-Host ""
Write-Host "Next step: pick the first ready feature from feature_list.json (see PROGRESS.md)."
Write-Host ""
Write-Host "Expected future commands (wire these here after bootstrap):"
Write-Host "  Laravel:  composer install; php artisan migrate; php artisan test"
Write-Host "  Engine:   python -m venv .venv; pip install -r requirements.txt; pytest"
Write-Host "  Frontend: npm install; npm run build; npm run test"
Write-Host ""
Write-Host "This script is intentionally informational until the stack exists."
