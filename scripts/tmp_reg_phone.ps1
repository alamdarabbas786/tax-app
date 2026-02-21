$path = 'C:\Users\alamd\Projects\Taxi_project\frontend\customer-app\App.js'
$content = Get-Content $path -Raw
# Add phoneNumber state
$content = $content -replace "const phone = route\?\.params\?\.phone \|\| '';\r?\n", "const phone = route?.params?.phone || '';`n  const [phoneNumber, setPhoneNumber] = useState(phone);`n"
# Update phoneValid to use phoneNumber
$content = $content -replace "const phoneValid = /\\^\\d\\{10\\}\\$/.test\(phone\.trim\(\)\);", "const phoneValid = /^\\d{10}$/.test(phoneNumber.trim());"
# Update formValid uses phoneValid already ok
# Update register payload phone
$content = $content -replace "phone,\r?\n", "phone: phoneNumber,`n"
# Update Mobile Number input value + onChangeText + maxLength
$content = $content -replace "value=\{phone\}", "value={phoneNumber}"
if ($content -notmatch 'onChangeText=\{setPhoneNumber\}') {
  $content = $content -replace "placeholderTextColor=\"#9aa3ad\"\r?\n\s*keyboardType=\"number-pad\"", "placeholderTextColor=\"#9aa3ad\"`n          keyboardType=\"number-pad\"`n          maxLength={10}`n          onChangeText={(v) => setPhoneNumber(v.replace(/\\D/g, '').slice(0, 10))}"
}
Set-Content -Path $path -Value $content
