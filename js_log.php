<?php
// Simple JS Error Logger
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    $logFile = __DIR__ . '/js_errors.log';
    $logMsg = "[" . date('Y-m-d H:i:s') . "] URL: " . ($data['url'] ?? '') . "\n" .
              "Message: " . ($data['message'] ?? '') . "\n" .
              "Source: " . ($data['source'] ?? '') . ":" . ($data['lineno'] ?? '') . ":" . ($data['colno'] ?? '') . "\n" .
              "Stack: " . ($data['error'] ?? '') . "\n" .
              str_repeat('-', 80) . "\n";
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}
echo "OK";
