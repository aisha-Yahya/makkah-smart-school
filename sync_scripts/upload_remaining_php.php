<?php
$commitUrl = 'https://firestore.googleapis.com/v1/projects/makkah-smart-school/databases/(default)/documents:commit';

for ($b = 58; $b <= 72; $b++) {
    $fn = sprintf('api/data/firestore_batches/batch_%03d.json', $b);
    if (!file_exists($fn)) continue;

    $payload = file_get_contents($fn);
    $ch = curl_init($commitUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Disable revocation checks or SSL verify status that hang on Windows
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "[SUCCESS] Batch $b uploaded successfully (HTTP 200)\n";
    } else {
        echo "[FAILED] Batch $b: HTTP $httpCode - $err - Response: " . substr($response, 0, 100) . "\n";
    }
}
echo "Finished remaining batches.\n";
