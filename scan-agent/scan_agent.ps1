# ============================================================
# DHA DMS - Local Scan Agent (PowerShell edition)
# ------------------------------------------------------------
# Runs on the THIN CLIENT (the PC physically connected to the
# scanner), not on the server. It listens on 127.0.0.1 only and
# exposes a small JSON API that the browser page (scan.php) talks
# to directly, so "Start Scan" drives the scanner attached to the
# machine actually running the browser, not the DMS server.
#
# It intentionally has no server/database knowledge. Its only job:
#   1. Run NAPS2 console locally against the configured device.
#   2. Hand the resulting PDF bytes back to the page that asked for
#      them (GET /files/<token>/<filename>).
#   3. Let the page cancel a running scan (POST /stop).
#
# The browser then uploads the actual PDF bytes to the DMS server
# as a normal file upload (see pages/scan.php) — from the server's
# point of view this is indistinguishable from someone picking a
# file with the "Upload" button, which is exactly what lets this
# work without any change to how documents get stored.
#
# Every scanned file is ALSO kept on this PC, in SCAN_SAVE_DIR
# below, as a local backup archive for SCAN_RETENTION_DAYS.
#
# Requires nothing but Windows' built-in PowerShell (5.1+) and
# NAPS2 (https://www.naps2.com/) installed on this PC. No Python,
# no Node, no separate install of anything for the agent itself.
# ============================================================

$ErrorActionPreference = 'Stop'

# ── Config file (agent_config.json, next to this script) ───────────────
$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir 'agent_config.json'

$DefaultConfig = [ordered]@{
    NAPS2_CONSOLE_PATH         = 'C:\Program Files\NAPS2\naps2.console.exe'
    # Leave SCANNER_DEVICE_NAME set to scan ad hoc against a named TWAIN/WIA
    # device (bypassing any saved NAPS2 profile). Set it to "" to fall back
    # to the NAPS2 GUI profile named SCAN_PROFILE_NAME instead.
    # *** This is the constant to edit to change scanners. ***
    SCANNER_DEVICE_NAME        = 'Canon DR-3010C TWAIN'
    SCANNER_DRIVER             = 'twain'
    SCAN_PROFILE_NAME          = 'DHA-Default'
    SCAN_TIMEOUT_SECONDS       = 300
    # Port this agent listens on. Must match SCAN_AGENT_PORT in the
    # server's includes/config.php.
    PORT                       = 51823
    # Which page origin is allowed to call this agent. Use the exact
    # scheme+host+port of the DMS site, e.g. "https://dms.dha.example".
    # "*" works too but is looser than necessary.
    ALLOWED_ORIGIN             = '*'
    # Where scanned PDFs are kept permanently on THIS PC (never the OS
    # temp folder, which can be cleared at any time). Every batch gets
    # its own sub-folder named after its token, so nothing is ever
    # overwritten. "~" expands to this Windows user's profile folder.
    SCAN_SAVE_DIR              = '~/DHA_Scans'
    # How long a batch's local copy is kept before this agent deletes it
    # automatically, counted from when it was scanned. 0 or negative =
    # keep every scan forever. Checked at startup and then re-checked
    # opportunistically (roughly every SCAN_RETENTION_CHECK_HOURS) as
    # requests come in while the agent keeps running.
    SCAN_RETENTION_DAYS        = 30
    SCAN_RETENTION_CHECK_HOURS = 6
}

function Copy-OrderedConfig([System.Collections.Specialized.OrderedDictionary]$Source) {
    # [ordered]@{}.Clone() is unreliable on literal ordered hashtables in
    # Windows PowerShell 5.1 ("does not contain a method named 'Clone'").
    # Build a fresh copy by hand instead.
    $copy = [ordered]@{}
    foreach ($key in $Source.Keys) { $copy[$key] = $Source[$key] }
    return $copy
}

function Import-AgentConfig {
    if (-not (Test-Path -LiteralPath $ConfigPath)) {
        ($DefaultConfig | ConvertTo-Json -Depth 5) | Set-Content -LiteralPath $ConfigPath -Encoding UTF8
        Write-Host "[scan-agent] Created $ConfigPath with default settings - edit it, then restart this agent."
        return Copy-OrderedConfig $DefaultConfig
    }
    try {
        $raw = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Host "[scan-agent] Could not read $ConfigPath ($($_.Exception.Message)) - using built-in defaults."
        return Copy-OrderedConfig $DefaultConfig
    }
    $merged = Copy-OrderedConfig $DefaultConfig
    foreach ($prop in $raw.PSObject.Properties) {
        $merged[$prop.Name] = $prop.Value
    }
    return $merged
}

$Config = Import-AgentConfig

function Expand-UserPath([string]$Path) {
    if ($Path.StartsWith('~')) {
        return $Path -replace '^~', [Environment]::GetFolderPath('UserProfile')
    }
    return $Path
}

$WorkDir = Expand-UserPath ([string]$Config.SCAN_SAVE_DIR)
New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null

# ── State (single scanner => single scan at a time) ─────────────────────
$script:ScanLock       = $false
$script:ActiveProcess  = $null
$script:StopRequested  = $false
$script:LastPruneCheck = Get-Date

# ── Retention cleanup ────────────────────────────────────────────────────
function Invoke-PruneOldScans {
    $retentionDays = [int]$Config.SCAN_RETENTION_DAYS
    if ($retentionDays -le 0) { return }

    $cutoff  = (Get-Date).AddDays(-$retentionDays)
    $removed = 0

    Get-ChildItem -LiteralPath $WorkDir -Directory -ErrorAction SilentlyContinue | ForEach-Object {
        $batchDir = $_.FullName
        $files    = Get-ChildItem -LiteralPath $batchDir -File -ErrorAction SilentlyContinue
        $newest   = if ($files) {
            ($files | Sort-Object LastWriteTime -Descending | Select-Object -First 1).LastWriteTime
        } else {
            $_.LastWriteTime
        }
        if ($newest -lt $cutoff) {
            Remove-Item -LiteralPath $batchDir -Recurse -Force -ErrorAction SilentlyContinue
            $removed++
        }
    }

    if ($removed -gt 0) {
        Write-Host "[scan-agent] Retention cleanup: removed $removed scan batch(es) older than $retentionDays day(s)."
    }
}

# ── NAPS2 invocation ─────────────────────────────────────────────────────
function Test-Naps2Exists { Test-Path -LiteralPath ([string]$Config.NAPS2_CONSOLE_PATH) -PathType Leaf }

function Convert-ScanSettings($Body) {
    $rawRes = [string]$Body.resolution
    if ([string]::IsNullOrWhiteSpace($rawRes)) { $rawRes = '300 DPI' }
    $digits = ($rawRes -replace '[^\d]', '')
    $dpi    = if ($digits) { [int]$digits } else { 300 }
    if ($dpi -lt 75 -or $dpi -gt 1200) { $dpi = 300 }

    $colorMode = ([string]$Body.color_mode).ToLower()
    $bitdepth  = if ($colorMode -match 'gr[ae]y') { 'gray' }
                 elseif ($colorMode -match 'black|bw') { 'bw' }
                 else { 'color' }

    $rawPaper = ([string]$Body.paper_size).ToLower()
    $pagesize = if ($rawPaper -match 'letter') { 'letter' }
                elseif ($rawPaper -match 'legal') { 'legal' }
                else { 'a4' }

    return @{
        dpi      = $dpi
        bitdepth = $bitdepth
        pagesize = $pagesize
        deskew   = [bool]$Body.deskew
        ocr      = [bool]$Body.ocr
    }
}

function Build-Naps2Args([string]$OutputPath, $Settings, [bool]$IsMultiple, [bool]$IsDuplex) {
    $argsList = [System.Collections.Generic.List[string]]::new()
    $argsList.Add('-o'); $argsList.Add($OutputPath)

    $useAdhoc = -not [string]::IsNullOrWhiteSpace([string]$Config.SCANNER_DEVICE_NAME)
    if ($useAdhoc) {
        $driver = if ([string]::IsNullOrWhiteSpace([string]$Config.SCANNER_DRIVER)) { 'twain' } else { [string]$Config.SCANNER_DRIVER }
        $argsList.Add('--noprofile')
        $argsList.Add('--driver'); $argsList.Add($driver)
        $argsList.Add('--device'); $argsList.Add([string]$Config.SCANNER_DEVICE_NAME)
    } else {
        $argsList.Add('--profile'); $argsList.Add([string]$Config.SCAN_PROFILE_NAME)
    }

    $argsList.Add('--dpi');      $argsList.Add([string]$Settings.dpi)
    $argsList.Add('--bitdepth'); $argsList.Add($Settings.bitdepth)
    $argsList.Add('--pagesize'); $argsList.Add($Settings.pagesize)
    $argsList.Add('--source');   $argsList.Add($(if ($IsDuplex) { 'duplex' } else { 'feeder' }))
    if ($Settings.deskew) { $argsList.Add('--deskew') }
    $argsList.Add($(if ($Settings.ocr) { '--enableocr' } else { '--disableocr' }))
    $argsList.Add('--force'); $argsList.Add('-v')

    if ($IsMultiple) {
        $argsList.Add('--splitsize')
        $argsList.Add($(if ($IsDuplex) { '2' } else { '1' }))
    }

    return $argsList
}

function Stop-ProcessTree([int]$ProcId) {
    try {
        Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/F', '/T', '/PID', "$ProcId") `
            -WindowStyle Hidden -Wait -ErrorAction SilentlyContinue | Out-Null
    } catch { }
}

# Builds a single Win32 command-line string from a list of arguments.
# We deliberately use ProcessStartInfo.Arguments (a plain string) rather
# than ProcessStartInfo.ArgumentList (a Collection<string>) because
# ArgumentList only exists on .NET Framework 4.7.1+ / .NET Core 2.1+.
# On an older .NET Framework - still common on thin-client PCs -
# PowerShell silently returns $null for that property instead of
# erroring, so ".Add()" on it fails with "You cannot call a method on
# a null-valued expression." Arguments has existed since .NET 1.0, so
# this works everywhere. Quoting follows the rules CommandLineToArgvW
# expects (same rules .NET's own ArgumentList escaping uses internally).
function ConvertTo-Naps2ArgumentString([System.Collections.Generic.List[string]]$ArgsList) {
    $parts = foreach ($arg in $ArgsList) {
        if ($null -eq $arg) { $arg = '' }
        $needsQuotes = ($arg -eq '') -or ($arg -match '[\s"]')
        # Double any backslashes that precede a quote, then escape the quote.
        $escaped = $arg -replace '(\\*)"', '$1$1\"'
        if ($needsQuotes) {
            # Double any trailing backslashes so they don't escape the closing quote.
            $escaped = $escaped -replace '(\\+)$', '$1$1'
            '"' + $escaped + '"'
        } else {
            $escaped
        }
    }
    return ($parts -join ' ')
}

# Runs NAPS2 console, polling so /stop can kill it mid-scan. Output is read
# via events (not ReadToEnd after exit) to avoid a pipe-buffer deadlock if
# NAPS2 writes more than a few KB before finishing.
function Invoke-Naps2($ArgsList, [int]$TimeoutSeconds) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = [string]$Config.NAPS2_CONSOLE_PATH
    $psi.Arguments              = ConvertTo-Naps2ArgumentString $ArgsList
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true

    $outBuf = New-Object System.Text.StringBuilder
    $errBuf = New-Object System.Text.StringBuilder

    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    $proc.EnableRaisingEvents = $true

    $outSub = Register-ObjectEvent -InputObject $proc -EventName OutputDataReceived -Action {
        if ($EventArgs.Data) { [void]$Event.MessageData.AppendLine($EventArgs.Data) }
    } -MessageData $outBuf
    $errSub = Register-ObjectEvent -InputObject $proc -EventName ErrorDataReceived -Action {
        if ($EventArgs.Data) { [void]$Event.MessageData.AppendLine($EventArgs.Data) }
    } -MessageData $errBuf

    try {
        [void]$proc.Start()
        $proc.BeginOutputReadLine()
        $proc.BeginErrorReadLine()

        $script:ActiveProcess = $proc
        $script:StopRequested = $false
        $startTime = Get-Date
        $stopped   = $false

        while (-not $proc.HasExited) {
            if ($script:StopRequested) {
                Stop-ProcessTree $proc.Id
                $proc.WaitForExit(5000) | Out-Null
                $stopped = $true
                break
            }
            if (((Get-Date) - $startTime).TotalSeconds -gt $TimeoutSeconds) {
                Stop-ProcessTree $proc.Id
                $proc.WaitForExit(5000) | Out-Null
                throw "Scan timed out after $TimeoutSeconds s"
            }
            Start-Sleep -Milliseconds 200
        }

        if (-not $proc.HasExited) { $proc.WaitForExit() }

        return @{
            ExitCode = $proc.ExitCode
            StdOut   = $outBuf.ToString()
            StdErr   = $errBuf.ToString()
            Stopped  = $stopped
        }
    } finally {
        Unregister-Event -SourceIdentifier $outSub.Name -ErrorAction SilentlyContinue
        Unregister-Event -SourceIdentifier $errSub.Name -ErrorAction SilentlyContinue
        $script:ActiveProcess = $null
    }
}

function Get-ScanErrorKind([string]$ErrText) {
    $lower = $ErrText.ToLower()
    $notFoundPatterns = @('no such device', 'device not found', 'could not find', 'no devices found',
        'not found', 'no scanners', 'unable to open', 'device is offline', 'device offline',
        'not available', 'no driver')
    $noPagesPatterns  = @('0 page(s) scanned', 'no scanned pages', 'no pages scanned', 'no pages to export')

    if ([string]::IsNullOrWhiteSpace($ErrText)) { return 'device_unavailable' }
    foreach ($p in $notFoundPatterns) { if ($lower.Contains($p)) { return 'device_unavailable' } }
    foreach ($p in $noPagesPatterns)  { if ($lower.Contains($p)) { return 'no_pages' } }
    return 'other'
}

function Invoke-DoScan($Body) {
    if (-not (Test-Naps2Exists)) {
        return @{ success = $false; error = "NAPS2 not found at: $($Config.NAPS2_CONSOLE_PATH)" }
    }

    $settings   = Convert-ScanSettings $Body
    $isDuplex   = [bool]$Body.duplex
    $pdfOutput  = [string]$Body.pdf_output
    if ([string]::IsNullOrWhiteSpace($pdfOutput)) { $pdfOutput = 'single' }
    $isMultiple = $pdfOutput.ToLower() -eq 'multiple'

    $token    = ([guid]::NewGuid().ToString('N')).Substring(0, 12)
    $batchDir = Join-Path $WorkDir $token
    New-Item -ItemType Directory -Force -Path $batchDir | Out-Null

    $baseName = 'Scan_' + (Get-Date -Format 'yyyyMMdd_HHmmss')
    $tempBase = $null
    $outputPath = $null

    if ($isMultiple) {
        $tempBase   = '_tmp_' + $baseName
        $outputPath = Join-Path $batchDir ($tempBase + '_$(n).pdf')
    } else {
        $custom  = [string]$Body.custom_filename
        $safe    = ($custom -replace '[^A-Za-z0-9 _\-]', '_').Trim()
        $desired = if ($safe) { $safe } else { $baseName }
        $outputPath = Join-Path $batchDir ($desired + '.pdf')
    }

    $argsList = Build-Naps2Args -OutputPath $outputPath -Settings $settings -IsMultiple $isMultiple -IsDuplex $isDuplex

    try {
        $result = Invoke-Naps2 -ArgsList $argsList -TimeoutSeconds ([int]$Config.SCAN_TIMEOUT_SECONDS)
    } catch {
        # TEMP DIAGNOSTIC: include the failing line + stack so we can pinpoint
        # the real bug instead of guessing from the bare exception message.
        $diag = "$($_.Exception.Message) [line $($_.InvocationInfo.ScriptLineNumber): $($_.InvocationInfo.Line.Trim())]"
        return @{ success = $false; error = $diag }
    }

    if ($result.Stopped) {
        return @{ success = $false; stopped = $true; error = 'Scan stopped by user.' }
    }

    if ($result.ExitCode -ne 0) {
        $errText = if ($result.StdErr.Trim()) { $result.StdErr.Trim() } else { $result.StdOut.Trim() }
        $kind = Get-ScanErrorKind $errText
        $deviceLabel = if ([string]$Config.SCANNER_DEVICE_NAME) { [string]$Config.SCANNER_DEVICE_NAME } else { [string]$Config.SCAN_PROFILE_NAME }

        if ($kind -eq 'device_unavailable') {
            $msg = "Could not connect to the scanner (`"$deviceLabel`"). Please check that it is powered on, connected, and its driver is installed on this PC, then try again."
            if ($errText) { $msg += " Details: $errText" }
            return @{ success = $false; error = $msg }
        }
        if ($kind -eq 'no_pages') {
            return @{ success = $false; error = "No pages came through the scanner (`"$deviceLabel`"). Please check paper is loaded, then try again." }
        }
        return @{ success = $false; error = "NAPS2 exited with code $($result.ExitCode). $errText" }
    }

    if ($isMultiple) {
        $pattern = Join-Path $batchDir ($tempBase + '_*.pdf')
        $matches = Get-ChildItem -Path $pattern -ErrorAction SilentlyContinue |
            Where-Object { $_.Length -gt 0 } | Sort-Object Name
        if (-not $matches) {
            return @{ success = $false; error = 'NAPS2 ran successfully but no output files were produced.' }
        }
        $files = @()
        $i = 1
        foreach ($m in $matches) {
            $finalPath = Join-Path $batchDir "$i.pdf"
            try {
                Move-Item -LiteralPath $m.FullName -Destination $finalPath -Force
            } catch {
                $finalPath = $m.FullName
            }
            $files += @{ filename = (Split-Path $finalPath -Leaf); size = (Get-Item -LiteralPath $finalPath).Length }
            $i++
        }
        return @{ success = $true; token = $token; files = $files; count = $files.Count }
    }

    if (-not (Test-Path -LiteralPath $outputPath) -or (Get-Item -LiteralPath $outputPath).Length -eq 0) {
        return @{ success = $false; error = 'NAPS2 ran successfully but no output file was produced.' }
    }

    return @{
        success = $true
        token   = $token
        files   = @(@{ filename = (Split-Path $outputPath -Leaf); size = (Get-Item -LiteralPath $outputPath).Length })
        count   = 1
    }
}

# ── Minimal HTTP server (System.Net.HttpListener, no external deps) ─────
function Add-CorsHeaders($Response) {
    $Response.Headers.Add('Access-Control-Allow-Origin', [string]$Config.ALLOWED_ORIGIN)
    $Response.Headers.Add('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    $Response.Headers.Add('Access-Control-Allow-Headers', 'Content-Type')
    # Chrome's Private Network Access check: an HTTPS page calling a
    # 127.0.0.1 server must see this on the preflight or the real
    # request gets blocked before it reaches us.
    $Response.Headers.Add('Access-Control-Allow-Private-Network', 'true')
}

function Send-JsonResponse($Context, [int]$StatusCode, $Payload) {
    $json  = $Payload | ConvertTo-Json -Depth 8 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $resp = $Context.Response
    $resp.StatusCode = $StatusCode
    Add-CorsHeaders $resp
    $resp.ContentType = 'application/json'
    $resp.ContentLength64 = $bytes.Length
    $resp.OutputStream.Write($bytes, 0, $bytes.Length)
    $resp.OutputStream.Close()
}

function Read-RequestBody($Request) {
    if (-not $Request.HasEntityBody) { return '' }
    $reader = New-Object System.IO.StreamReader($Request.InputStream, $Request.ContentEncoding)
    try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
}

function Invoke-RequestHandler($Context) {
    $request  = $Context.Request
    $response = $Context.Response
    $path     = $request.Url.AbsolutePath

    if ($request.HttpMethod -eq 'OPTIONS') {
        $response.StatusCode = 204
        Add-CorsHeaders $response
        $response.OutputStream.Close()
        return
    }

    if ($request.HttpMethod -eq 'GET' -and $path -eq '/health') {
        Send-JsonResponse $Context 200 @{ status = 'ok'; naps2_found = (Test-Naps2Exists) }
        return
    }

    if ($request.HttpMethod -eq 'GET' -and $path.StartsWith('/files/')) {
        $rel = $path.Substring(7).Trim('/')
        $parts = $rel -split '/'
        if ($parts.Count -ne 2) { Send-JsonResponse $Context 404 @{ error = 'Not found' }; return }

        $token    = [System.Uri]::UnescapeDataString($parts[0])
        $filename = [System.Uri]::UnescapeDataString($parts[1])
        if ($token -match '[\\/]' -or $filename -match '[\\/]') {
            Send-JsonResponse $Context 400 @{ error = 'Invalid path' }
            return
        }

        $fullPath = Join-Path (Join-Path $WorkDir $token) $filename
        if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
            Send-JsonResponse $Context 404 @{ error = 'File not found' }
            return
        }

        $bytes = [System.IO.File]::ReadAllBytes($fullPath)
        $response.StatusCode = 200
        Add-CorsHeaders $response
        $response.ContentType = 'application/pdf'
        $response.ContentLength64 = $bytes.Length
        $response.OutputStream.Write($bytes, 0, $bytes.Length)
        $response.OutputStream.Close()
        return
    }

    if ($request.HttpMethod -eq 'POST' -and $path -eq '/scan') {
        if ($script:ScanLock) {
            Send-JsonResponse $Context 409 @{ success = $false; error = 'A scan is already in progress.' }
            return
        }
        $script:ScanLock = $true
        try {
            $bodyText = Read-RequestBody $request
            $body = @{}
            if ($bodyText) {
                try { $body = $bodyText | ConvertFrom-Json } catch {
                    Send-JsonResponse $Context 400 @{ success = $false; error = 'Invalid JSON body.' }
                    return
                }
            }
            $result = Invoke-DoScan $body
            Send-JsonResponse $Context 200 $result
        } finally {
            $script:ScanLock = $false
        }
        return
    }

    if ($request.HttpMethod -eq 'POST' -and $path -eq '/stop') {
        $script:StopRequested = $true
        if ($script:ActiveProcess -and -not $script:ActiveProcess.HasExited) {
            Stop-ProcessTree $script:ActiveProcess.Id
        }
        Send-JsonResponse $Context 200 @{ success = $true }
        return
    }

    if ($request.HttpMethod -eq 'POST' -and $path -eq '/cleanup') {
        $bodyText = Read-RequestBody $request
        $token = ''
        if ($bodyText) {
            try { $token = [string](($bodyText | ConvertFrom-Json).token) } catch { $token = '' }
        }
        if ($token -and ($token -notmatch '[\\/]')) {
            $target = Join-Path $WorkDir $token
            if (Test-Path -LiteralPath $target) {
                Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction SilentlyContinue
            }
        }
        Send-JsonResponse $Context 200 @{ success = $true }
        return
    }

    Send-JsonResponse $Context 404 @{ error = 'Not found' }
}

# ── Startup ───────────────────────────────────────────────────────────
$port = [int]$Config.PORT
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$port/")
$listener.Start()

$retentionDays = [int]$Config.SCAN_RETENTION_DAYS
$retentionDesc = if ($retentionDays -le 0) { 'kept forever (cleanup disabled)' } else { "kept $retentionDays day(s)" }

Write-Host "[scan-agent] Config file: $ConfigPath"
Write-Host "[scan-agent] Listening on http://127.0.0.1:$port"
Write-Host "[scan-agent] NAPS2 console: $($Config.NAPS2_CONSOLE_PATH) $(if (Test-Naps2Exists) { '(found)' } else { '(NOT FOUND)' })"
Write-Host "[scan-agent] Device: $(if ([string]$Config.SCANNER_DEVICE_NAME) { $Config.SCANNER_DEVICE_NAME } else { "(using profile $($Config.SCAN_PROFILE_NAME))" })"
Write-Host "[scan-agent] Saving scans permanently to: $WorkDir ($retentionDesc)"

Invoke-PruneOldScans

try {
    while ($listener.IsListening) {
        $context = $listener.GetContext()   # blocks until a request arrives
        try {
            Invoke-RequestHandler $context
        } catch {
            Write-Host "[scan-agent] Error handling request: $($_.Exception.Message)"
            try {
                $context.Response.StatusCode = 500
                $context.Response.OutputStream.Close()
            } catch { }
        }

        $checkHours = [int]$Config.SCAN_RETENTION_CHECK_HOURS
        if ($retentionDays -gt 0 -and $checkHours -gt 0) {
            if (((Get-Date) - $script:LastPruneCheck).TotalHours -ge $checkHours) {
                Invoke-PruneOldScans
                $script:LastPruneCheck = Get-Date
            }
        }
    }
} finally {
    $listener.Stop()
    $listener.Close()
}