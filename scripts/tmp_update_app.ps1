$path = 'C:\Users\alamd\Projects\Taxi_project\frontend\customer-app\App.js'
$content = Get-Content $path -Raw
if ($content -notmatch 'SelectVehicleScreen') {
  $content = $content.Replace("import LocationSearchScreen from './screens/LocationSearchScreen';", "import LocationSearchScreen from './screens/LocationSearchScreen';`nimport SelectVehicleScreen from './screens/SelectVehicleScreen';`nimport PaymentMethodAndOffersScreen from './screens/PaymentMethodAndOffersScreen';")
}
$marker = '        <Stack.Screen name="LocationSearch" component={LocationSearchScreen} options={{ title: '', headerBackTitleVisible: false }} />'
if ($content -notmatch 'name="SelectVehicle"') {
  $insert = '        <Stack.Screen name="SelectVehicle" component={SelectVehicleScreen} options={{ title: "", headerBackTitleVisible: false }} />' + "`n" +
            '        <Stack.Screen name="PaymentMethodAndOffers" component={PaymentMethodAndOffersScreen} options={{ title: "", headerBackTitleVisible: false }} />' + "`n" +
            $marker
  $content = $content.Replace($marker, $insert)
}
Set-Content -Path $path -Value $content
