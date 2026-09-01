$ErrorActionPreference = 'Stop'

$walletingRoot = Split-Path -Parent $PSScriptRoot
$appRoot = Join-Path (Split-Path -Parent $walletingRoot) 'App'
$candidateFiles = @(
    (Join-Path $appRoot '.env.local'),
    (Join-Path $appRoot '.env')
)

$databaseUrl = $null
foreach ($file in $candidateFiles) {
    if (-not (Test-Path -LiteralPath $file)) {
        continue
    }

    $line = Get-Content -LiteralPath $file | Where-Object { $_ -match '^\s*DATABASE_URL\s*=' } | Select-Object -Last 1
    if ($null -eq $line) {
        continue
    }

    $databaseUrl = ($line -replace '^\s*DATABASE_URL\s*=\s*', '').Trim()
    if (($databaseUrl.StartsWith('"') -and $databaseUrl.EndsWith('"')) -or ($databaseUrl.StartsWith("'") -and $databaseUrl.EndsWith("'"))) {
        $databaseUrl = $databaseUrl.Substring(1, $databaseUrl.Length - 2)
    }
    if ($databaseUrl -match '^postgres(?:ql)?://') {
        break
    }
}

if ([string]::IsNullOrWhiteSpace($databaseUrl)) {
    throw 'Could not resolve a PostgreSQL DATABASE_URL from the sibling App workspace.'
}

$uri = [System.Uri]$databaseUrl
$userInfo = $uri.UserInfo.Split(':', 2)
$username = [System.Uri]::UnescapeDataString($userInfo[0])
$password = if ($userInfo.Count -gt 1) { [System.Uri]::UnescapeDataString($userInfo[1]) } else { '' }
$port = if ($uri.Port -gt 0) { $uri.Port } else { 5432 }
$psql = (Get-Command psql.exe -ErrorAction Stop).Source

$testToken = '_local_' + ([guid]::NewGuid().ToString('N').Substring(0, 12))
$testDatabase = 'walleting_test' + $testToken

$previousPassword = $env:PGPASSWORD
$env:PGPASSWORD = $password
try {
    & $psql -X --no-psqlrc --set ON_ERROR_STOP=1 --host $uri.Host --port $port --username $username --dbname postgres --command ('CREATE DATABASE ' + $testDatabase)
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not create the isolated Walleting integration database.'
    }
} finally {
    $env:PGPASSWORD = $previousPassword
}

$match = [regex]::Match($databaseUrl, '^(postgres(?:ql)?://[^/]+/)[^?]+(\?.*)?$')
if (-not $match.Success) {
    throw 'Could not rewrite the local PostgreSQL DATABASE_URL safely.'
}

$walletingBaseUrl = $match.Groups[1].Value + 'walleting' + $match.Groups[2].Value
$localEnvPath = Join-Path $walletingRoot '.env.test.local'
$localEnvContent = 'DATABASE_URL=' + $walletingBaseUrl + "`r`n" + 'WALLETING_INTEGRATION_DATABASE_URL=' + $walletingBaseUrl + "`r`n"
[System.IO.File]::WriteAllText($localEnvPath, $localEnvContent, [System.Text.UTF8Encoding]::new($false))
Write-Output 'Local Walleting integration database is provisioned and ignored test configuration is ready.'

$previousTestToken = $env:TEST_TOKEN
$env:TEST_TOKEN = $testToken

Push-Location $walletingRoot
try {
    & composer run-script test:integration
    if ($LASTEXITCODE -ne 0) {
        throw 'Walleting integration suite failed.'
    }
} finally {
    Pop-Location
    $env:TEST_TOKEN = $previousTestToken

    $previousPassword = $env:PGPASSWORD
    $env:PGPASSWORD = $password
    try {
        & $psql -X --no-psqlrc --set ON_ERROR_STOP=1 --host $uri.Host --port $port --username $username --dbname postgres --command ('DROP DATABASE IF EXISTS ' + $testDatabase + ' WITH (FORCE)')
        if ($LASTEXITCODE -ne 0) {
            Write-Warning 'Could not remove the run-scoped Walleting integration database.'
        }
    } finally {
        $env:PGPASSWORD = $previousPassword
    }
}
