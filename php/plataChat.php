<?php
// audioRx.php
date_default_timezone_set('UTC');

// don't set     
// header('Access-Control-Allow-Origin: *');
// here. is already done in website config

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

$audioDir = __DIR__ . '/audio';
$logFile = __DIR__ . '/platachat.log';

// Helper to log errors
function logError($message, $logFile)
{
    $entry = sprintf("[%s] ERROR: %s\n", date('Y-m-d H:i:s'), $message);
    file_put_contents($logFile, $entry, FILE_APPEND);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['text'])) {
    logError('Missing user text', $logFile);
    http_response_code(400);
    echo json_encode(['error' => 'Missing user text']);
    exit;
}


$userText = escapeshellarg($data['text']); // Protect input

$systemPrompt = implode(' ', array_map('trim', file(__DIR__ . '/plataPrompt.txt')));

//$modelPrompt = $systemPrompt . PHP_EOL . "Der Nutzer fragt folgendes:" . PHP_EOL . $userText;
$modelPrompt = "System: " . $systemPrompt . "User:" . $data['text'] . PHP_EOL;

// Build the `ollama run` command
$model = isset($data['model']) ? escapeshellarg($data['model']) : 'granite3.3:2b';
// qwen2.5:3b   deepseek-r1:1.5b  gemma3:1b  phi3:mini llama3.2:latest 

//set env for ollama

$home = '/home/okl'; // Adjust this to a real user directory where `.ollama` exists

$env = array_merge($_ENV, [
    'HOME' => $home,
]);


$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open("ollama run $model", $descriptorSpec, $pipes, null, $env);

if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to start Ollama']);
    exit;
}

fwrite($pipes[0], $modelPrompt);
fclose($pipes[0]);

$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);

$error = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$returnCode = proc_close($process);

if ($returnCode !== 0) {
    http_response_code(500);
    logError("Ollama failed on: " . $modelPrompt, $logFile);

    echo json_encode([
        'error' => 'Ollama execution failed',
        'exitCode' => $returnCode,
        'stderr' => $error
    ]);
    exit;
}

// trim output 
$output = preg_replace('/<think>.*?<\/think>/is', '', $output);
$output = trim($output);


// check synthesizer 
$port = 9010;
$host = 'localhost';
$ttsUrl = "http://$host:$port/transscribe"; // or any endpoint you defined

// 1. Check if service is responding
$ch = curl_init($ttsUrl);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    logError("TTS service returned: $httpCode", $logFile);
    // Synthesize the output with espeak-ng
    $audioFile = $audioDir . '/response.wav';
    $synth = "espeak-ng";

    // Escape output for shell
    $escapedOutput = escapeshellarg($output);

    // Build espeak-ng command
    $espeakCmd = "espeak-ng -v mb-de2 -w " . escapeshellarg($audioFile) . " $escapedOutput";

    // Execute espeak-ng
    exec($espeakCmd, $espeakOutput, $espeakReturn);

    if ($espeakReturn !== 0) {
        logError("espeak-ng failed: " . implode("\n", $espeakOutput), $logFile);
        http_response_code(500);
        echo json_encode(['error' => 'espeak-ng synthesis failed']);
        exit;
    }
} else {
    // If TTS service is running, use it to synthesize the output
    $audioFile = 'response.wav'; // plain name here 
    $synth = "coqui";
    $postFields = [
        'text' => $output,
        'file' => $audioFile
    ];

    // Prepare cURL for POST
    $ch = curl_init($ttsUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));    

    // Execute POST request
    $ttsResponse = curl_exec($ch);
    $ttsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ttsError = curl_error($ch);
    curl_close($ch);

    if ($ttsHttpCode !== 200 || !$ttsResponse) {
        logError("TTS service failed: $ttsError", $logFile);
        http_response_code(500);
        echo json_encode(['error' => 'TTS service synthesis failed']);
        exit;
    }
    // Try to extract filename from TTS response if present (assume JSON with 'filename')
    $ttsJson = json_decode($ttsResponse, true);
    if (is_array($ttsJson) && isset($ttsJson['filename'])) {
        $audioFile = $ttsJson['filename'];
    } else {
        logError("TTS did not return filename", $logFile);
        http_response_code(500);
        echo json_encode(['error' => 'TTS service synthesis failed (missing filename)']);
        exit;
    }

}

$audioData = file_get_contents($audioFile);
$audioBase64 = base64_encode($audioData);
echo json_encode(value: ['status' => 'ok', 'text' => $output, "audio" => $audioBase64, "synth" => $synth]);



