<#
.SYNOPSIS
  Start the ServiceNow mock on http://127.0.0.1:8090 (PHP built-in server, SQLite).
.EXAMPLE
  .\start.ps1               # background, writes logs\server.log and data\server.pid
  .\start.ps1 -WaitReady    # background, return once the server answers (up to 15 s)
  .\start.ps1 -Foreground   # attached to this console, Ctrl+C to stop
  .\start.ps1 -Port 8095
  .\start.ps1 -Bind 0.0.0.0 -AllowRemote   # listen on the network (clients still need allowed_clients in config.php)
#>
param([int]$Port = 8090, [string]$Bind = "127.0.0.1", [switch]$Foreground, [switch]$AllowRemote, [switch]$WaitReady)
$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "tools\find-php.ps1")

$loopback = @("127.0.0.1", "localhost", "::1", "[::1]")
if ($loopback -notcontains $Bind) {
    if (-not $AllowRemote) {
        Write-Host "Refusing to listen on '$Bind': the mock has demo passwords and no CSRF protection." -ForegroundColor Red
        Write-Host "Use the default 127.0.0.1, or pass -AllowRemote if you really need it on the network." -ForegroundColor Red
        exit 1
    }
    Write-Warning ("Listening on $Bind. Anyone who can reach this port sees demo credentials. Remote clients still get " +
        "403 unless allowed by 'allowed_clients' in config.php or SNOW_MOCK_ALLOWED_CLIENTS.")
}

function Wait-MockReady([string]$HostName, [int]$PortNumber, [int]$Seconds = 15) {
    # Any HTTP answer (GET on the token endpoint returns 405) means PHP is up and routing.
    $probeHost = if ($loopback -contains $HostName -or $HostName -eq "0.0.0.0") { "127.0.0.1" } else { $HostName }
    $url = "http://${probeHost}:$PortNumber/oauth_token.do"
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2 | Out-Null
            return $true
        } catch [System.Net.WebException] {
            if ($_.Exception.Response) { return $true }
        } catch {
            # not listening yet
        }
        Start-Sleep -Milliseconds 300
    }
    return $false
}

$php = Find-Php
$root = $PSScriptRoot
New-Item -ItemType Directory -Force (Join-Path $root "data"), (Join-Path $root "logs") | Out-Null
$phpArgs = (Get-PhpArgs $php) + @("-d", "display_errors=stderr", "-d", "log_errors=On", "-S", "${Bind}:$Port",
          "-t", (Join-Path $root "public"), (Join-Path $root "public\index.php"))

Write-Host "ServiceNow mock: http://${Bind}:$Port   (PHP $php)"
if ($Foreground) {
    & $php @phpArgs
} else {
    $pidFile = Join-Path $root "data\server.pid"
    if ((Test-Path $pidFile) -and (Get-Process -Id (Get-Content $pidFile) -ErrorAction SilentlyContinue)) {
        Write-Host "Already running (pid $(Get-Content $pidFile)). Use .\stop.ps1 first."
    } else {
        $quoted = $phpArgs | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
        $p = Start-Process -FilePath $php -ArgumentList $quoted -WindowStyle Hidden -PassThru `
            -RedirectStandardError (Join-Path $root "logs\server.log") -RedirectStandardOutput (Join-Path $root "logs\access.log")
        $p.Id | Set-Content $pidFile
        Write-Host "Started in background (pid $($p.Id)). Logs in logs\. Stop with .\stop.ps1"
    }
    if ($WaitReady) {
        if (Wait-MockReady $Bind $Port) {
            Write-Host "Ready: http://${Bind}:$Port"
        } else {
            Write-Host "The mock did not answer within 15 s: see $(Join-Path $root 'logs\server.log')" -ForegroundColor Red
            exit 1
        }
    }
}
