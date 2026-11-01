<#
.SYNOPSIS
  Back up the ServiceNow mock into the pac-history repository and push it to GitHub.
.DESCRIPTION
  Shares the repository with PAC Sync, so it takes PAC Sync's lock file <repo>\.pac-sync.lock while it runs:
  it waits up to -LockWaitSeconds (default 60) for a running pac-sync to finish, then gives up. PAC Sync in turn
  refuses to start while the backup holds the lock. Only backups\ is ever staged here; PAC Sync only commits regions\.
.EXAMPLE
  .\backup.ps1                                   # ..\pac-sync\pac-history, keep 14 snapshots, push
  .\backup.ps1 -Keep 30 -NoPush
  .\backup.ps1 -HistoryRepo D:\repos\pac-history
#>
param(
    [string]$HistoryRepo = (Join-Path $PSScriptRoot "..\pac-sync\pac-history"),
    [int]$Keep = 14,
    [switch]$NoPush,
    [int]$LockWaitSeconds = 60
)
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "tools\find-php.ps1")

$HistoryRepo = (Resolve-Path $HistoryRepo).Path
if (-not (Test-Path (Join-Path $HistoryRepo ".git"))) { throw "$HistoryRepo is not a git repository" }
$dest = Join-Path $HistoryRepo "backups\snow-mock"
$lockPath = Join-Path $HistoryRepo ".pac-sync.lock"

function Enter-HistoryLock([string]$Path, [int]$Seconds) {
    # CreateNew is atomic: it fails if pac-sync (or another backup) already holds the lock.
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ($true) {
        try {
            $stream = [System.IO.File]::Open($Path, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write)
            $bytes = [System.Text.Encoding]::ASCII.GetBytes("backup.ps1 pid $PID")
            $stream.Write($bytes, 0, $bytes.Length)
            $stream.Close()
            return
        } catch [System.IO.IOException] {
            if ((Get-Date) -ge $deadline) {
                $age = [int]((Get-Date) - (Get-Item $Path).LastWriteTime).TotalMinutes
                throw "$Path is held by another run (created $age min ago). Waited $Seconds s. Delete it only if that run is dead."
            }
            Start-Sleep -Seconds 1
        }
    }
}

Enter-HistoryLock $lockPath $LockWaitSeconds
try {
    $php = Find-Php
    & $php @(Get-PhpArgs $php) (Join-Path $PSScriptRoot "tools\backup.php") $dest $Keep
    if ($LASTEXITCODE -ne 0) { throw "backup.php failed ($LASTEXITCODE)" }

    git -C $HistoryRepo add -A backups
    if ($LASTEXITCODE -ne 0) { throw "git add failed" }
    git -C $HistoryRepo diff --cached --quiet -- backups
    if ($LASTEXITCODE -eq 0) { Write-Host "No change since the last backup."; return }
    $stamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd HH:mm 'UTC'")
    git -C $HistoryRepo -c user.name="PAC Sync" -c user.email="pac-sync@example.com" commit -q -m "Backup snow-mock $stamp" -- backups
    if ($LASTEXITCODE -ne 0) { throw "git commit failed" }
    if (-not $NoPush) {
        git -C $HistoryRepo push -q origin HEAD
        if ($LASTEXITCODE -ne 0) { throw "git push failed" }
        Write-Host "Backup committed and pushed."
    } else {
        Write-Host "Backup committed (not pushed)."
    }
} finally {
    Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
}
