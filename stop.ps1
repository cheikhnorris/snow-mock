# Stop the background ServiceNow mock started by start.ps1.
$pidFile = Join-Path $PSScriptRoot "data\server.pid"
if (-not (Test-Path $pidFile)) { Write-Host "Not running."; exit 0 }
$serverPid = Get-Content $pidFile
Stop-Process -Id $serverPid -ErrorAction SilentlyContinue
Remove-Item $pidFile
Write-Host "Stopped (pid $serverPid)."
