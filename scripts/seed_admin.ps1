param(
  [string]$MysqlUser = 'appuser',
  [string]$MysqlDb = 'mobility',
  [string]$MysqlHost = '127.0.0.1',
  [int]$MysqlPort = 3306
)

$scriptPath = Join-Path $PSScriptRoot '..\db\mysql\016_seed_admin.sql'
$scriptPath = Resolve-Path $scriptPath

Write-Host "Seeding admin using $scriptPath"
Get-Content $scriptPath | & mysql -u $MysqlUser -h $MysqlHost -P $MysqlPort $MysqlDb
