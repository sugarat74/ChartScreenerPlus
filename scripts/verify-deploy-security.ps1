param(
    [string]$HostName,
    [string]$IdentityPath,
    [string]$KnownHostsLine
)
$ErrorActionPreference = 'Stop'
$repository = Split-Path -Parent $PSScriptRoot
$bash = Get-Command bash -ErrorAction Stop
$previousHost = $env:CHARTIKO_VERIFY_HOST
$previousIdentity = $env:CHARTIKO_VERIFY_IDENTITY
$previousKnownHosts = $env:CHARTIKO_VERIFY_KNOWN_HOSTS
Push-Location $repository
try {
    if ($HostName) {
        if (-not $IdentityPath -or -not $KnownHostsLine) { throw 'Live checks require IdentityPath and KnownHostsLine.' }
        $env:CHARTIKO_VERIFY_HOST = $HostName
        $env:CHARTIKO_VERIFY_IDENTITY = $IdentityPath.Replace('\', '/')
        $env:CHARTIKO_VERIFY_KNOWN_HOSTS = $KnownHostsLine
    } else {
        $env:CHARTIKO_VERIFY_HOST = $null
    }
    & $bash.Source '.\deploy\verify-security.sh'
    if ($LASTEXITCODE -ne 0) { throw 'Deployment security checks failed.' }
} finally {
    $env:CHARTIKO_VERIFY_HOST = $previousHost
    $env:CHARTIKO_VERIFY_IDENTITY = $previousIdentity
    $env:CHARTIKO_VERIFY_KNOWN_HOSTS = $previousKnownHosts
    Pop-Location
}
