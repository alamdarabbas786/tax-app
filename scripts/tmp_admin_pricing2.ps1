$path='C:\Users\alamd\Projects\Taxi_project\src\Controllers\AdminController.php'
$lines=Get-Content $path
$out=@()
foreach($line in $lines){
  if ($line -match "echo '<h2>Pricing Management</h2>';" ){
    $out += $line
    $out += '        echo ''<p style="color:#9ca3af;margin:6px 0 12px;">Driver profit margin is fixed at 22% and cannot be changed.</p>'';'
    continue
  }
  if ($line -match 'driver_profit_margin' -and $line -match '<input name=\"driver_profit_margin\"'){
    $out += '                echo ''<td><input name="driver_profit_margin" value="22" readonly /></td>'';'
    continue
  }
  $out += $line
}
Set-Content -Path $path -Value $out
