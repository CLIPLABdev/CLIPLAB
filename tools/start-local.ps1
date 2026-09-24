[CmdletBinding()]
param(
    [string]$PhpBin = 'C:\xampp\php\php.exe',
    [string]$ProjectRoot = '',
    [ValidateRange(1024, 65535)]
    [int]$Port = 8093,
    [ValidatePattern('^[1-9][0-9]*[KMG]$')]
    [string]$UploadMax = '500M',
    [ValidatePattern('^[1-9][0-9]*[KMG]$')]
    [string]$PostMax = '501M',
    [switch]$WithWorker,
    [ValidateRange(0, 100000)]
    [int]$WorkerIterations = 0,
    [ValidateRange(1, 60)]
    [int]$PollSeconds = 2
)

$ErrorActionPreference = 'Stop'
$webProcess = $null
$workerProcess = $null
$started = $false
$deferredWorkerCleanup = New-Object 'System.Collections.Generic.List[string]'

function Write-SafeEvent {
    param([string]$Event, [hashtable]$Fields = @{})

    $record = [ordered]@{ event = $Event }
    foreach ($key in $Fields.Keys) {
        $record[$key] = $Fields[$key]
    }
    [Console]::Out.WriteLine(($record | ConvertTo-Json -Compress))
}

function Write-SafeError {
    param([string]$Code, [System.Collections.IDictionary]$Fields = @{})

    $record = [ordered]@{ event = 'runtime_error'; error = $Code }
    foreach ($key in @('exception_type', 'line')) {
        if ($Fields.Contains($key)) {
            $record[$key] = $Fields[$key]
        }
    }
    [Console]::Error.WriteLine(($record | ConvertTo-Json -Compress))
}

function Test-LocalPortInUse {
    param([int]$CandidatePort)

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $pending = $client.BeginConnect('127.0.0.1', $CandidatePort, $null, $null)
        if (-not $pending.AsyncWaitHandle.WaitOne(250)) {
            return $false
        }
        try {
            $client.EndConnect($pending)
            return $client.Connected
        } catch {
            return $false
        }
    } finally {
        $client.Close()
    }
}

function Stop-OwnedProcess {
    param($Process)

    if ($null -eq $Process) {
        return
    }
    try {
        $Process.Refresh()
        if (-not $Process.HasExited) {
            Stop-Process -InputObject $Process -Force -ErrorAction SilentlyContinue
            $Process.WaitForExit(3000) | Out-Null
        }
    } catch {
        # The owned child already ended between Refresh and Stop-Process.
    }
}

function Remove-WorkerRunDirectory {
    param(
        [string]$RunDirectory,
        [ValidateRange(0, 30000)]
        [int]$MaxWaitMilliseconds = 0
    )

    $stopwatch = [Diagnostics.Stopwatch]::StartNew()
    do {
        try {
            $stdout = Join-Path $RunDirectory 'stdout.log'
            $stderr = Join-Path $RunDirectory 'stderr.log'
            if (Test-Path -LiteralPath $stdout -PathType Leaf) { [IO.File]::Delete($stdout) }
            if (Test-Path -LiteralPath $stderr -PathType Leaf) { [IO.File]::Delete($stderr) }
            if (Test-Path -LiteralPath $RunDirectory -PathType Container) { [IO.Directory]::Delete($RunDirectory, $false) }
            return $true
        } catch [System.IO.IOException] {
            # A worker descendant can briefly retain an inherited redirection handle.
        } catch [System.UnauthorizedAccessException] {
            # Windows can surface the same transient sharing violation as access denied.
        }
        if ($stopwatch.ElapsedMilliseconds -ge $MaxWaitMilliseconds) {
            return $false
        }
        Start-Sleep -Milliseconds 100
    } while ($true)
}

function Read-WorkerCounters {
    param([string]$OutputPath)

    $empty = [ordered]@{
        claimed = 0
        completed = 0
        retried = 0
        deferred = 0
        failed = 0
        operational_errors = 1
    }
    if (-not (Test-Path -LiteralPath $OutputPath -PathType Leaf)) {
        return $empty
    }
    $candidate = Get-Content -LiteralPath $OutputPath | Where-Object { $_.Trim() -ne '' } | Select-Object -Last 1
    if (-not $candidate) {
        return $empty
    }
    try {
        $decoded = $candidate | ConvertFrom-Json
        $safe = [ordered]@{}
        foreach ($name in @('claimed', 'completed', 'retried', 'deferred', 'failed', 'operational_errors')) {
            $value = $decoded.$name
            if ($null -eq $value -or [int64]$value -lt 0 -or [int64]$value -gt [int]::MaxValue) {
                return $empty
            }
            $safe[$name] = [int]$value
        }
        return $safe
    } catch {
        return $empty
    }
}

try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $scriptDirectory = $PSScriptRoot
        if ([string]::IsNullOrWhiteSpace($scriptDirectory)) {
            $scriptPath = $PSCommandPath
            if ([string]::IsNullOrWhiteSpace($scriptPath)) {
                $scriptPath = $MyInvocation.MyCommand.Path
            }
            if ([string]::IsNullOrWhiteSpace($scriptPath)) {
                throw 'project_root_invalid'
            }
            $scriptDirectory = Split-Path -Parent $scriptPath
        }
        $ProjectRoot = Split-Path -Parent $scriptDirectory
    }
    $resolvedPhp = (Resolve-Path -LiteralPath $PhpBin -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath $resolvedPhp -PathType Leaf)) {
        throw 'php_unavailable'
    }
    $resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath (Join-Path $resolvedRoot 'public\index.php') -PathType Leaf)) {
        throw 'project_root_invalid'
    }
    if ($WithWorker -and -not (Test-Path -LiteralPath (Join-Path $resolvedRoot 'bin\process-jobs.php') -PathType Leaf)) {
        throw 'worker_command_unavailable'
    }
    if (-not $WithWorker -and $WorkerIterations -ne 0) {
        throw 'worker_iterations_require_worker'
    }
    if (Test-LocalPortInUse $Port) {
        throw 'port_in_use'
    }

    $phpCheck = Start-Process -FilePath $resolvedPhp `
        -ArgumentList @('-r', 'exit(PHP_VERSION_ID>=80000?0:1);') `
        -WorkingDirectory $resolvedRoot -WindowStyle Hidden -Wait -PassThru
    if ($phpCheck.ExitCode -ne 0) {
        throw 'php_version_unsupported'
    }

    $webProcess = Start-Process -FilePath $resolvedPhp `
        -ArgumentList @('-d', "upload_max_filesize=$UploadMax", '-d', "post_max_size=$PostMax", '-S', "127.0.0.1:$Port", '-t', 'public', 'public/index.php') `
        -WorkingDirectory $resolvedRoot -WindowStyle Hidden -PassThru
    $started = $true
    $url = "http://127.0.0.1:$Port/"
    $ready = $false
    for ($attempt = 0; $attempt -lt 50; $attempt++) {
        $webProcess.Refresh()
        if ($webProcess.HasExited) {
            throw 'web_process_exited'
        }
        try {
            $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 1
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 100
        }
    }
    if (-not $ready) {
        throw 'web_readiness_timeout'
    }
    Write-SafeEvent 'web_ready' @{ url = $url.TrimEnd('/') }

    if (-not $WithWorker) {
        while ($true) {
            $webProcess.Refresh()
            if ($webProcess.HasExited) {
                throw 'web_process_exited'
            }
            Start-Sleep -Seconds 1
        }
    }

    $runs = 0
    while ($WorkerIterations -eq 0 -or $runs -lt $WorkerIterations) {
        for ($cleanupIndex = $deferredWorkerCleanup.Count - 1; $cleanupIndex -ge 0; $cleanupIndex--) {
            if (Remove-WorkerRunDirectory $deferredWorkerCleanup[$cleanupIndex]) {
                $deferredWorkerCleanup.RemoveAt($cleanupIndex)
            }
        }
        $webProcess.Refresh()
        if ($webProcess.HasExited) {
            throw 'web_process_exited'
        }
        $runDirectory = Join-Path ([IO.Path]::GetTempPath()) ('cliplab-local-worker-' + [Guid]::NewGuid().ToString('N'))
        [IO.Directory]::CreateDirectory($runDirectory) | Out-Null
        $stdoutPath = Join-Path $runDirectory 'stdout.log'
        $stderrPath = Join-Path $runDirectory 'stderr.log'
        try {
            $workerProcess = Start-Process -FilePath $resolvedPhp `
                -ArgumentList @('-d', "upload_max_filesize=$UploadMax", '-d', "post_max_size=$PostMax", 'bin/process-jobs.php', '--queue=media', '--limit=1', '--time-budget=50') `
                -WorkingDirectory $resolvedRoot -WindowStyle Hidden -PassThru `
                -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
            while (-not $workerProcess.HasExited) {
                Start-Sleep -Milliseconds 100
                $workerProcess.Refresh()
            }
            $workerProcess.WaitForExit()
            $counters = Read-WorkerCounters $stdoutPath
            $fields = [ordered]@{ exit_code = [int]$workerProcess.ExitCode }
            foreach ($name in $counters.Keys) {
                $fields[$name] = $counters[$name]
            }
            Write-SafeEvent 'worker_run' $fields
            $workerProcess = $null
        } finally {
            Stop-OwnedProcess $workerProcess
            $workerProcess = $null
            if (-not (Remove-WorkerRunDirectory $runDirectory 5000)) {
                $deferredWorkerCleanup.Add($runDirectory)
            }
        }
        $runs++
        if ($WorkerIterations -eq 0 -or $runs -lt $WorkerIterations) {
            Start-Sleep -Seconds $PollSeconds
        }
    }
    exit 0
} catch {
    $knownFailure = $_.Exception.Message -match '^(php_unavailable|project_root_invalid|worker_command_unavailable|worker_iterations_require_worker|port_in_use|php_version_unsupported|web_process_exited|web_readiness_timeout)$'
    $code = if ($knownFailure) {
        $_.Exception.Message
    } else {
        'runtime_start_failed'
    }
    $diagnostics = @{}
    if (-not $knownFailure) {
        $rootException = $_.Exception
        while ($null -ne $rootException.InnerException) {
            $rootException = $rootException.InnerException
        }
        $diagnostics['exception_type'] = $rootException.GetType().FullName
        $diagnostics['line'] = [int]$_.InvocationInfo.ScriptLineNumber
    }
    Write-SafeError $code $diagnostics
    exit 1
} finally {
    Stop-OwnedProcess $workerProcess
    Stop-OwnedProcess $webProcess
    foreach ($runDirectory in $deferredWorkerCleanup) {
        Remove-WorkerRunDirectory $runDirectory | Out-Null
    }
    if ($started) {
        Write-SafeEvent 'runtime_stopped'
    }
}
