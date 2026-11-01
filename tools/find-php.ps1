# Dot-source to get Find-Php and Get-PhpArgs (PHP from winget, extensions loaded with -d flags).
function Find-Php {
    $pkg = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Directory -Filter "PHP.PHP.*" -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending | ForEach-Object { Join-Path $_.FullName "php.exe" } | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($pkg) { return $pkg }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $item = Get-Item $cmd.Source; if ($item.Target) { return $item.Target } return $cmd.Source }
    throw "PHP not found. Install it with: winget install PHP.PHP.8.4"
}

function Get-PhpArgs([string]$php) {
    $ext = Join-Path (Split-Path $php) "ext"
    return @("-n", "-d", "extension_dir=$ext", "-d", "extension=pdo_sqlite", "-d", "extension=mbstring")
}
