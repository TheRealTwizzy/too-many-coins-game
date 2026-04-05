param(
    [string]$BaseUrl = "http://127.0.0.1:8080/api/index.php",
    [string]$Action = "game_state",
    [int]$Clients = 40,
    [int]$RequestsPerClient = 15,
    [int]$DelayMs = 150,
    [string]$SessionToken = "",
    [switch]$UniqueSessionPerClient,
    [switch]$NoBody,
    [switch]$ShowFailures
)

$ErrorActionPreference = "Stop"

function New-HexToken {
    param([int]$Bytes = 32)
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $bytes = New-Object byte[] $Bytes
    $rng.GetBytes($bytes)
    ($bytes | ForEach-Object { $_.ToString("x2") }) -join ""
}

function Invoke-ClientBurst {
    param(
        [string]$Url,
        [string]$ActionName,
        [int]$RequestCount,
        [int]$SleepMs,
        [string]$Token,
        [bool]$SkipBody
    )

    $results = New-Object System.Collections.Generic.List[object]

    for ($i = 1; $i -le $RequestCount; $i++) {
        $statusCode = 0
        $errorText = $null
        $jsonError = $null
        $rateTier = $null
        $rateLimit = $null
        $rateRemaining = $null

        try {
            $headers = @{}
            if ($Token -ne "") {
                $headers["X-Session-Token"] = $Token
            }

            $separator = "?"
            if ($Url.Contains("?")) {
                $separator = "&"
            }
            $targetUrl = "$Url${separator}action=$([uri]::EscapeDataString($ActionName))"

            $uriObj = $null
            if (-not [System.Uri]::TryCreate($targetUrl, [System.UriKind]::Absolute, [ref]$uriObj)) {
                throw "Invalid URI built by load helper: $targetUrl"
            }
            if ($SkipBody) {
                $resp = Invoke-WebRequest -Uri $uriObj -Method Post -Headers $headers -ContentType "application/json" -TimeoutSec 10
            } else {
                $body = @{ action = $ActionName } | ConvertTo-Json -Compress
                $resp = Invoke-WebRequest -Uri $uriObj -Method Post -Headers $headers -Body $body -ContentType "application/json" -TimeoutSec 10
            }

            $statusCode = [int]$resp.StatusCode
            $rateTier = $resp.Headers["X-RateLimit-Tier"]
            $rateLimit = $resp.Headers["X-RateLimit-Limit"]
            $rateRemaining = $resp.Headers["X-RateLimit-Remaining"]

            if (-not [string]::IsNullOrWhiteSpace($resp.Content)) {
                try {
                    $json = $resp.Content | ConvertFrom-Json
                    if ($null -ne $json.error) {
                        $jsonError = [string]$json.error
                    }
                } catch {
                    # Ignore non-JSON response for load summary purposes.
                }
            }
        } catch {
            $statusCode = 0
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
            $errorText = $_.Exception.Message
        }

        $results.Add([pscustomobject]@{
            timestamp = (Get-Date).ToString("s")
            status = $statusCode
            error = $errorText
            json_error = $jsonError
            rate_tier = $rateTier
            rate_limit = $rateLimit
            rate_remaining = $rateRemaining
        })

        if ($SleepMs -gt 0) {
            Start-Sleep -Milliseconds $SleepMs
        }
    }

    return $results
}

Write-Host "[load] baseUrl=$BaseUrl action=$Action clients=$Clients requestsPerClient=$RequestsPerClient delayMs=$DelayMs"
if ($SessionToken -ne "") {
    Write-Host "[load] using shared session token"
} elseif ($UniqueSessionPerClient) {
    Write-Host "[load] using unique session-like token per client"
} else {
    Write-Host "[load] running anonymous requests"
}

$jobs = @()
$runAt = Get-Date

for ($c = 1; $c -le $Clients; $c++) {
    $clientToken = ""
    if ($SessionToken -ne "") {
        $clientToken = $SessionToken
    } elseif ($UniqueSessionPerClient) {
        $clientToken = New-HexToken
    }

    $jobs += Start-Job -ScriptBlock {
        param($u, $a, $rpc, $dms, $tok, $skipBodyRaw)

        $skipBody = $false
        if ($skipBodyRaw -is [bool]) {
            $skipBody = $skipBodyRaw
        } elseif ($skipBodyRaw -ne $null) {
            $skipBody = ([string]$skipBodyRaw).ToLowerInvariant() -eq "true"
        }

        function Invoke-ClientBurst {
            param(
                [string]$Url,
                [string]$ActionName,
                [int]$RequestCount,
                [int]$SleepMs,
                [string]$Token,
                [bool]$SkipBody
            )

            $results = New-Object System.Collections.Generic.List[object]

            for ($i = 1; $i -le $RequestCount; $i++) {
                $statusCode = 0
                $errorText = $null
                $jsonError = $null
                $rateTier = $null
                $rateLimit = $null
                $rateRemaining = $null

                try {
                    $headers = @{}
                    if ($Token -ne "") {
                        $headers["X-Session-Token"] = $Token
                    }

                    $separator = "?"
                    if ($Url.Contains("?")) {
                        $separator = "&"
                    }
                    $targetUrl = "$Url${separator}action=$([uri]::EscapeDataString($ActionName))"

                    $uriObj = $null
                    if (-not [System.Uri]::TryCreate($targetUrl, [System.UriKind]::Absolute, [ref]$uriObj)) {
                        throw "Invalid URI built by load helper: $targetUrl"
                    }
                    if ($SkipBody) {
                        $resp = Invoke-WebRequest -Uri $uriObj -Method Post -Headers $headers -ContentType "application/json" -TimeoutSec 10
                    } else {
                        $body = @{ action = $ActionName } | ConvertTo-Json -Compress
                        $resp = Invoke-WebRequest -Uri $uriObj -Method Post -Headers $headers -Body $body -ContentType "application/json" -TimeoutSec 10
                    }

                    $statusCode = [int]$resp.StatusCode
                    $rateTier = $resp.Headers["X-RateLimit-Tier"]
                    $rateLimit = $resp.Headers["X-RateLimit-Limit"]
                    $rateRemaining = $resp.Headers["X-RateLimit-Remaining"]

                    if (-not [string]::IsNullOrWhiteSpace($resp.Content)) {
                        try {
                            $json = $resp.Content | ConvertFrom-Json
                            if ($null -ne $json.error) {
                                $jsonError = [string]$json.error
                            }
                        } catch {
                            # Ignore non-JSON response for load summary purposes.
                        }
                    }
                } catch {
                    $statusCode = 0
                    if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                        $statusCode = [int]$_.Exception.Response.StatusCode
                    }
                    $errorText = $_.Exception.Message
                }

                $results.Add([pscustomobject]@{
                    timestamp = (Get-Date).ToString("s")
                    status = $statusCode
                    error = $errorText
                    json_error = $jsonError
                    rate_tier = $rateTier
                    rate_limit = $rateLimit
                    rate_remaining = $rateRemaining
                })

                if ($SleepMs -gt 0) {
                    Start-Sleep -Milliseconds $SleepMs
                }
            }

            return $results
        }

        Invoke-ClientBurst -Url $u -ActionName $a -RequestCount $rpc -SleepMs $dms -Token $tok -SkipBody:$skipBody
    } -ArgumentList $BaseUrl, $Action, $RequestsPerClient, $DelayMs, $clientToken, ([string][bool]$NoBody)
}

Wait-Job -Job $jobs | Out-Null

$all = @()
foreach ($j in $jobs) {
    $all += Receive-Job -Job $j
}
Remove-Job -Job $jobs | Out-Null

$elapsed = (Get-Date) - $runAt
$total = $all.Count
$byStatus = $all | Group-Object -Property status | Sort-Object Name

Write-Host ""
Write-Host "[load] completed in $([math]::Round($elapsed.TotalSeconds,2))s"
Write-Host "[load] total requests: $total"

foreach ($g in $byStatus) {
    Write-Host ("[load] status {0}: {1}" -f $g.Name, $g.Count)
}

$rateErrors = $all | Where-Object { $_.json_error -match "Rate limit exceeded" }
if ($rateErrors.Count -gt 0) {
    Write-Host ("[load] payload rate-limit errors: {0}" -f $rateErrors.Count)
}

$sampleHeaders = $all | Where-Object { $_.rate_tier -or $_.rate_limit -or $_.rate_remaining } | Select-Object -First 5
if ($sampleHeaders) {
    Write-Host ""
    Write-Host "[load] sample limiter headers:"
    $sampleHeaders | Format-Table -AutoSize status, rate_tier, rate_limit, rate_remaining
}

if ($ShowFailures) {
    $failures = $all | Where-Object { $_.status -ne 200 }
    if ($failures) {
        Write-Host ""
        Write-Host "[load] failure samples:"
        $failures | Select-Object -First 20 | Format-Table -AutoSize timestamp, status, json_error, error
    }
}

Write-Host ""
Write-Host "[load] scenarios:"
Write-Host "  1) Anonymous shared identity (proxy-like): no token"
Write-Host "  2) Auth-like shared identity: -SessionToken <64hex>"
Write-Host "  3) Auth-like per-client identity: -UniqueSessionPerClient"
