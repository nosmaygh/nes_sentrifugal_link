<?php
// Place in Sentrifugo webroot as test_sync.php
$url = 'http://host.docker.internal:8000/api/v1/sync/employee';
$apiKey = 'nosmay_sentrifugo_secure_sync_secret_key_2026';

$payload = json_encode([
    'emp_code'    => 'LIVE-SYS-TEST',
    'firstname'   => 'Sentrifugo',
    'lastname'    => 'LiveTest',
    'email'       => 'livetest@nosmay.com',
    'date_joined' => date('Y-m-d'),
    'password'    => 'TestPass123!'
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Sentrifugo-Key: ' . $apiKey,
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<h2>Sentrifugo &rarr; Payroll Live Bridge Test</h2>";
echo "<b>Target Destination:</b> " . htmlspecialchars($url) . "<br>";
echo "<b>HTTP Status Code:</b> " . ($httpCode == 201 ? "<span style='color:green;font-weight:bold;'>201 (SUCCESS)</span>" : "<span style='color:red;font-weight:bold;'>$httpCode</span>") . "<br>";

if ($curlError) {
    echo "<b style='color:red;'>cURL Error (Network/SSL issue):</b> " . htmlspecialchars($curlError) . "<br>";
} else {
    echo "<b>Payroll Server Response:</b> <pre>" . htmlspecialchars($response) . "</pre>";
}