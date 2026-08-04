$ErrorActionPreference = 'Stop'

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$logPath = Join-Path $backendPath 'storage\logs\mikrotik-worker.log'
$lockPath = Join-Path $backendPath 'storage\framework\mikrotik-worker.lock'

Set-Location $backendPath

try {
    New-Item -Path $lockPath -ItemType Directory -ErrorAction Stop | Out-Null
} catch {
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path $logPath -Value "[$timestamp] Ya hay un procesador MikroTik activo, se omite esta vuelta."
    exit 0
}

try {
    for ($i = 1; $i -le 4; $i++) {
        $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        Add-Content -Path $logPath -Value "[$timestamp] Procesando operaciones MikroTik ($i/4)..."

        try {
            $output = & php artisan mikrotik:process-pending --retry-failed 2>&1
            $output | ForEach-Object { Add-Content -Path $logPath -Value $_ }
        } catch {
            Add-Content -Path $logPath -Value $_.Exception.Message
        }

        if ($i -lt 4) {
            Start-Sleep -Seconds 15
        }
    }
} finally {
    Remove-Item -LiteralPath $lockPath -Recurse -Force -ErrorAction SilentlyContinue
}
