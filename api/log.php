<?php
/**
 * Form Submission CSV Logger
 * 
 * Accepts POST requests with JSON body, appends to a local CSV file.
 * Auto-prunes records older than 180 days on each write.
 */

// CORS headers for same-site form submissions
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['form_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request. JSON body with form_name required.']);
    exit;
}

// Configuration
$logsDir = __DIR__ . '/logs';
$csvFile = $logsDir . '/form_submissions.csv';
$retentionDays = 180;

// Ensure logs directory exists
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0750, true);
}

// Master columns (superset of all form fields)
$columns = [
    'timestamp',
    'form_name',
    'first_name',
    'last_name',
    'email',
    'phone',
    'subject',
    'message',
    'preferred_contact',
    'referrer_name',
    'referrer_email',
    'friend_name',
    'friend_email',
    'friend_phone',
    'relationship',
    'sms_consent',
    'dentist_name',
    'practice_name',
    'dentist_phone',
    'dentist_email',
    'patient_first_name',
    'patient_last_name',
    'patient_dob',
    'parent_guardian',
    'patient_phone',
    'patient_email',
    'chief_concern',
    'malocclusion',
    'additional_notes',
    'urgency',
    'leadsigma_status'
];

// Build the row from submitted data
$row = [];
$row['timestamp'] = date('Y-m-d H:i:s');
foreach ($columns as $col) {
    if ($col === 'timestamp') continue;
    $row[$col] = isset($data[$col]) ? $data[$col] : '';
}

// Check if CSV exists and has a header
$needsHeader = !file_exists($csvFile) || filesize($csvFile) === 0;

// Append the row
$fp = fopen($csvFile, 'a');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not open log file']);
    exit;
}

// Lock file for writing
if (flock($fp, LOCK_EX)) {
    if ($needsHeader) {
        fputcsv($fp, $columns);
    }

    $csvRow = [$row['timestamp']];
    foreach ($columns as $col) {
        if ($col === 'timestamp') continue;
        $csvRow[] = $row[$col];
    }
    fputcsv($fp, $csvRow);

    flock($fp, LOCK_UN);
}
fclose($fp);

// Cleanup: prune rows older than retention period (runs on each write)
pruneOldRecords($csvFile, $columns, $retentionDays);

echo json_encode(['success' => true, 'message' => 'Submission logged']);

/**
 * Remove CSV rows older than $retentionDays.
 */
function pruneOldRecords(string $csvFile, array $columns, int $retentionDays): void
{
    if (!file_exists($csvFile)) return;

    $cutoff = strtotime("-{$retentionDays} days");
    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (count($lines) <= 1) return; // Only header or empty

    $header = str_getcsv($lines[0]);
    $timestampIndex = array_search('timestamp', $header);

    if ($timestampIndex === false) return;

    $kept = [$lines[0]]; // Always keep header
    $pruned = false;

    for ($i = 1; $i < count($lines); $i++) {
        $fields = str_getcsv($lines[$i]);
        if (!isset($fields[$timestampIndex])) continue;

        $rowTime = strtotime($fields[$timestampIndex]);
        if ($rowTime !== false && $rowTime >= $cutoff) {
            $kept[] = $lines[$i];
        } else {
            $pruned = true;
        }
    }

    // Only rewrite if we actually pruned something
    if ($pruned) {
        file_put_contents($csvFile, implode(PHP_EOL, $kept) . PHP_EOL, LOCK_EX);
    }
}
