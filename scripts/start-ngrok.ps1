[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent $scriptDir
$tunnelDir = Join-Path $scriptDir 'tunnel'
$ngrokLog = Join-Path ([System.IO.Path]::GetTempPath()) 'flow-office-ngrok.log'
$ngrokErrorLog = Join-Path ([System.IO.Path]::GetTempPath()) 'flow-office-ngrok-error.log'

function Resolve-Executable {
    param(
        [Parameter(Mandatory)]
        [string] $Name,
        [string] $WinGetPackagePattern
    )

    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    if ($WinGetPackagePattern) {
        $packagesDir = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
        $wingetExecutable = Get-ChildItem `
            -Path $packagesDir `
            -Filter "$Name.exe" `
            -Recurse `
            -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -like "*\$WinGetPackagePattern\*" } |
            Select-Object -First 1
        if ($wingetExecutable) {
            return $wingetExecutable.FullName
        }
    }

    return $null
}

$caddyExecutable = Resolve-Executable 'caddy' 'CaddyServer.Caddy_*'
$ngrokExecutable = Resolve-Executable 'ngrok' 'Ngrok.Ngrok_*'
$dockerExecutable = Resolve-Executable 'docker'

if (-not $caddyExecutable) {
    throw 'caddy was not found. Run: winget install CaddyServer.Caddy'
}
if (-not $ngrokExecutable) {
    throw 'ngrok was not found. Run: winget install Ngrok.Ngrok'
}
if (-not $dockerExecutable) {
    throw 'docker was not found. Install and start Docker Desktop.'
}

$caddyProcess = $null
$ngrokProcess = $null
$previousLocation = Get-Location

try {
    Write-Host 'Starting Caddy local reverse proxy on port 8080...'
    $caddyProcess = Start-Process `
        -FilePath $caddyExecutable `
        -ArgumentList @('run', '--config', (Join-Path $tunnelDir 'Caddyfile'), '--adapter', 'caddyfile') `
        -PassThru `
        -NoNewWindow
    Start-Sleep -Seconds 1

    if ($caddyProcess.HasExited) {
        throw "Caddy failed to start (exit code: $($caddyProcess.ExitCode))."
    }

    Write-Host 'Starting ngrok tunnel for port 8080...'
    $ngrokProcess = Start-Process `
        -FilePath $ngrokExecutable `
        -ArgumentList @('http', '8080', '--log=stdout') `
        -RedirectStandardOutput $ngrokLog `
        -RedirectStandardError $ngrokErrorLog `
        -PassThru `
        -NoNewWindow

    Write-Host 'Waiting for ngrok to assign a public URL...'
    $publicUrl = $null
    foreach ($attempt in 1..30) {
        try {
            $tunnelResponse = Invoke-RestMethod `
                -Uri 'http://127.0.0.1:4040/api/tunnels' `
                -TimeoutSec 2
            $publicUrl = $tunnelResponse.tunnels |
                Where-Object { $_.proto -eq 'https' } |
                Select-Object -First 1 -ExpandProperty public_url
        }
        catch {
            # Retry until the local ngrok API becomes available.
        }

        if ($publicUrl) {
            break
        }
        if ($ngrokProcess.HasExited) {
            break
        }
        Start-Sleep -Seconds 1
    }

    if (-not $publicUrl) {
        throw @"
Could not obtain the ngrok public URL.
Confirm that 'ngrok config add-authtoken <token>' has been run.
Log: $ngrokLog
Error log: $ngrokErrorLog
"@
    }

    Write-Host ''
    Write-Host "Public URL:   $publicUrl/flow-office/"
    Write-Host "Backend API:  $publicUrl/flow-office/api/..."
    Write-Host "MCP endpoint: $publicUrl/flow-office/mcp"
    Write-Host ''
    Write-Host 'Starting docker compose with the public URL environment variables.'
    Write-Host 'Press Ctrl+C to stop.'
    Write-Host ''

    $env:VITE_BASE_PATH = '/flow-office/'
    $env:VITE_API_BASE_URL = "$publicUrl/flow-office/api"
    $env:FRONTEND_PUBLIC_APP_URL = "$publicUrl/flow-office"
    $env:BACKEND_PUBLIC_APP_URL = "$publicUrl/flow-office/api"
    $env:MCP_PUBLIC_APP_URL = "$publicUrl/flow-office/mcp"

    Set-Location $repoRoot
    & $dockerExecutable compose up --build
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose exited with code $LASTEXITCODE."
    }
}
finally {
    Set-Location $previousLocation
    foreach ($process in @($ngrokProcess, $caddyProcess)) {
        if ($null -ne $process -and -not $process.HasExited) {
            Stop-Process -Id $process.Id -ErrorAction SilentlyContinue
        }
    }
}
