$path = 'C:\Users\alamd\Projects\Taxi_project\frontend\customer-app\App.js'
$content = Get-Content $path -Raw
$content = $content -replace "import \{\n  Alert,\n  BackHandler,\n  StatusBar,\n  StyleSheet,\n  Text,\n  TextInput,\n  TouchableOpacity,\n  View\n\} from 'react-native';", "import {`n  Alert,`n  BackHandler,`n  StatusBar,`n  StyleSheet,`n  Text,`n  TextInput,`n  TouchableOpacity,`n  View,`n  ScrollView,`n  KeyboardAvoidingView,`n  Platform`n} from 'react-native';"
$content = $content -replace "return \(\n    <View style=\{styles.screen\}>", 'return (`n    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === ''ios'' ? ''padding'' : undefined}>`n      <ScrollView contentContainerStyle={styles.regScroll} keyboardShouldPersistTaps="handled">'
$content = $content -replace "<\/View>\n  \);\n}\n", '      </ScrollView>`n    </KeyboardAvoidingView>`n  );`n}`n'
if ($content -notmatch 'regScroll') {
  $content = $content -replace "regCard: \{[\s\S]*?\},", "regCard: {`n    paddingBottom: 18`n  },`n  regScroll: {`n    paddingBottom: 24`n  },"
}
Set-Content -Path $path -Value $content
