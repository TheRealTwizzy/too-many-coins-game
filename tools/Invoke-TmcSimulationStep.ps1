param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('export', 'sim-b', 'sim-c', 'sim-d', 'sim-e')]
    [string]$Step,

    [string]$ConfigPath = (Join-Path $PSScriptRoot 'local/tmc-sim.current.ps1'),
    [string]$Seed = 'current-db',
    [int]$PlayersPerArchetype = 5,
    [int]$Seasons = 12,
    [string]$Scenarios = 'hoarder-pressure-v1'
)

$ErrorActionPreference = 'Stop'

function Get-TmcSimConfig {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Config file not found: $Path"
    }

    $loaded = & $Path
    if ($null -eq $loaded) {
        throw "Config file did not return a configuration hashtable: $Path"
    }

    if ($loaded -isnot [hashtable]) {
        throw "Config file must return a hashtable: $Path"
    }

    return $loaded
}

function Require-ConfigValue {
    param(
        [hashtable]$Config,
        [string]$Name
    )

    $value = $Config[$Name]
    if ($null -eq $value -or [string]::IsNullOrWhiteSpace([string]$value)) {
        throw "Missing required config value '$Name' in $resolvedConfigPath"
    }

    return [string]$value
}

function Get-ConfigValue {
    param(
        [hashtable]$Config,
        [string[]]$Names
    )

    foreach ($name in $Names) {
        if ($Config.ContainsKey($name)) {
            $value = $Config[$name]
            if ($null -ne $value -and -not [string]::IsNullOrWhiteSpace([string]$value)) {
                return [string]$value
            }
        }
    }

    return $null
}

function Require-AnyConfigValue {
    param(
        [hashtable]$Config,
        [string[]]$Names,
        [string]$Label
    )

    $value = Get-ConfigValue -Config $Config -Names $Names
    if ($null -eq $value) {
        throw "Missing required config value '$Label' in $resolvedConfigPath"
    }

    return $value
}

function Invoke-PhpScript {
    param(
        [string[]]$Arguments
    )

    & php @Arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "php exited with code $exitCode"
    }
}

function Assert-PathExists {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw $Message
    }
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$resolvedConfigPath = [string](Resolve-Path -LiteralPath $ConfigPath)
$config = Get-TmcSimConfig -Path $resolvedConfigPath

$outputRoot = Join-Path $PSScriptRoot '..\simulation_output\current-db'
$exportDir = Join-Path $outputRoot 'export'
$seasonDir = Join-Path $outputRoot 'season'
$lifetimeDir = Join-Path $outputRoot 'lifetime'
$sweepDir = Join-Path $outputRoot 'sweep'
$comparatorDir = Join-Path $outputRoot 'comparator'

$null = New-Item -ItemType Directory -Force -Path $exportDir, $seasonDir, $lifetimeDir, $sweepDir, $comparatorDir

$exportPath = Join-Path $exportDir 'current_season.json'
$manifestPath = Join-Path $sweepDir ("policy_sweep_{0}_ppa{1}_s{2}.json" -f $Seed, $PlayersPerArchetype, $Seasons)

$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = Require-AnyConfigValue -Config $config -Names @('TmcLocalForwardPort', 'LocalForwardPort') -Label 'TmcLocalForwardPort'
$env:DB_NAME = Require-AnyConfigValue -Config $config -Names @('TmcDbName', 'DbName') -Label 'TmcDbName'
$env:DB_USER = Require-AnyConfigValue -Config $config -Names @('TmcDbUser', 'DbUser') -Label 'TmcDbUser'
$env:DB_PASS = Require-AnyConfigValue -Config $config -Names @('TmcDbPass', 'DbPass') -Label 'TmcDbPass'

$previousTickRealSeconds = $env:TMC_TICK_REAL_SECONDS
$shouldClearTickRealSeconds = $false

try {
    switch ($Step) {
        'export' {
            Write-Host "Exporting current season config to $exportPath"
            Invoke-PhpScript -Arguments @(
                (Join-Path $PSScriptRoot 'export-season-config.php'),
                "--output=$exportPath"
            )
        }
        'sim-b' {
            Assert-PathExists -Path $exportPath -Message "Expected export file not found: $exportPath. Run export first."
            Write-Host "Running Sim B with export $exportPath"
            Invoke-PhpScript -Arguments @(
                (Join-Path $PSScriptRoot '..\scripts\simulate_economy.php'),
                "--seed=$Seed",
                "--players-per-archetype=$PlayersPerArchetype",
                "--season-config=$exportPath",
                "--output=$seasonDir"
            )
        }
        'sim-c' {
            Assert-PathExists -Path $exportPath -Message "Expected export file not found: $exportPath. Run export first."
            $env:TMC_TICK_REAL_SECONDS = '3600'
            $shouldClearTickRealSeconds = $true
            Write-Host "Running Sim C with export $exportPath"
            Invoke-PhpScript -Arguments @(
                (Join-Path $PSScriptRoot '..\scripts\simulate_lifetime.php'),
                "--seed=$Seed",
                "--players-per-archetype=$PlayersPerArchetype",
                "--seasons=$Seasons",
                "--season-config=$exportPath",
                "--output=$lifetimeDir"
            )
        }
        'sim-d' {
            Assert-PathExists -Path $exportPath -Message "Expected export file not found: $exportPath. Run export first."
            $env:TMC_TICK_REAL_SECONDS = '3600'
            $shouldClearTickRealSeconds = $true
            Write-Host "Running Sim D with export $exportPath"
            Invoke-PhpScript -Arguments @(
                (Join-Path $PSScriptRoot '..\scripts\simulate_policy_sweep.php'),
                "--seed=$Seed",
                "--players-per-archetype=$PlayersPerArchetype",
                "--seasons=$Seasons",
                "--season-config=$exportPath",
                "--scenarios=$Scenarios",
                '--include-baseline=1',
                "--output=$sweepDir"
            )
        }
        'sim-e' {
            Write-Host "Running Sim E with manifest $manifestPath"
            if (-not (Test-Path -LiteralPath $manifestPath)) {
                throw "Expected sweep manifest not found: $manifestPath. Run sim-d first or keep Seed/PlayersPerArchetype/Seasons aligned."
            }

            Invoke-PhpScript -Arguments @(
                (Join-Path $PSScriptRoot '..\scripts\compare_simulation_results.php'),
                "--seed=$Seed",
                "--sweep-manifest=$manifestPath",
                "--output=$comparatorDir"
            )
        }
    }
} finally {
    if ($shouldClearTickRealSeconds) {
        if ([string]::IsNullOrEmpty($previousTickRealSeconds)) {
            Remove-Item Env:TMC_TICK_REAL_SECONDS -ErrorAction SilentlyContinue
        } else {
            $env:TMC_TICK_REAL_SECONDS = $previousTickRealSeconds
        }
    }
}