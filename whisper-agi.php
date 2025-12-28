#!/usr/bin/php
<?php
/**
 * Asterisk AGI script for speech-to-text using OpenAI Whisper API
 * Compatible with PHP 5.6
 * 
 * Usage in dialplan:
 *   exten => s,n,AGI(whisper-agi.php)
 *   exten => s,n,NoOp(Transcribed digits: ${TRANSCRIBED_DIGITS})
 */

// ============================================
// CONFIGURATION
// ============================================

// Load config file if exists
$configFile = __DIR__ . '/whisper-config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Default configuration (can be overridden by whisper-config.php or env variables)
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: 'your-api-key-here');
}
if (!defined('WHISPER_MODEL')) {
    define('WHISPER_MODEL', 'whisper-1');
}
if (!defined('GPT_PROXY_BASE_URL')) {
    define('GPT_PROXY_BASE_URL', getenv('GPT_PROXY_URL') ?: 'https://gptproxy.example.com/v1/');
}
if (!defined('WHISPER_API_URL')) {
    define('WHISPER_API_URL', rtrim(GPT_PROXY_BASE_URL, '/') . '/audio/transcriptions');
}
if (!defined('RECORDINGS_PATH')) {
    define('RECORDINGS_PATH', '/var/spool/asterisk/monitor/');
}
if (!defined('RECORDING_EXT')) {
    define('RECORDING_EXT', '.wav');
}
if (!defined('DEBUG')) {
    define('DEBUG', true);
}

// ============================================
// AGI CLASS
// ============================================

class AGI {
    public $request = array();
    private $stdin;
    private $stdout;
    
    public function __construct() {
        $this->stdin = fopen('php://stdin', 'r');
        $this->stdout = fopen('php://stdout', 'w');
        
        // Read AGI environment variables
        while (!feof($this->stdin)) {
            $line = trim(fgets($this->stdin));
            if (empty($line)) {
                break;
            }
            if (strpos($line, 'agi_') === 0) {
                $parts = explode(':', $line, 2);
                if (count($parts) == 2) {
                    $key = substr(trim($parts[0]), 4); // Remove 'agi_' prefix
                    $value = trim($parts[1]);
                    $this->request[$key] = $value;
                }
            }
        }
    }
    
    public function execute($command) {
        fwrite($this->stdout, $command . "\n");
        fflush($this->stdout);
        $response = trim(fgets($this->stdin));
        return $response;
    }
    
    public function verbose($message, $level = 1) {
        $this->execute("VERBOSE \"$message\" $level");
    }
    
    public function setVariable($name, $value) {
        $this->execute("SET VARIABLE $name \"$value\"");
    }
    
    public function getVariable($name) {
        $response = $this->execute("GET VARIABLE $name");
        if (preg_match('/\((.+)\)/', $response, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    public function log($message) {
        if (DEBUG) {
            $this->verbose("[Whisper AGI] $message", 3);
            error_log("[Whisper AGI] $message");
        }
    }
}

// ============================================
// WHISPER API FUNCTIONS
// ============================================

/**
 * Send audio file to OpenAI Whisper API
 * 
 * @param string $audioFile Path to audio file
 * @param AGI $agi AGI instance for logging
 * @return string|false Transcription text or false on error
 */
function transcribeAudio($audioFile, $agi) {
    if (!file_exists($audioFile)) {
        $agi->log("File not found: $audioFile");
        return false;
    }
    
    // Prepare cURL
    $ch = curl_init();
    
    // Use CURLFile for PHP 5.5+
    if (class_exists('CURLFile')) {
        $postFile = new CURLFile($audioFile, 'audio/wav', basename($audioFile));
    } else {
        // Fallback for older PHP versions
        $postFile = '@' . realpath($audioFile);
    }
    
    $postData = array(
        'file' => $postFile,
        'model' => WHISPER_MODEL,
        'response_format' => 'json',
        'language' => 'ru' // Russian language, change if needed
    );
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => WHISPER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . OPENAI_API_KEY
        ),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        $agi->log("cURL error: $error");
        return false;
    }
    
    if ($httpCode !== 200) {
        $agi->log("HTTP error $httpCode: $response");
        return false;
    }
    
    $data = json_decode($response, true);

    $agi->log("API response: $response");
    
    if (isset($data['text'])) {
        return $data['text'];
    }
    
    $agi->log("Invalid API response: $response");
    return false;
}

/**
 * Extract digits from transcribed text
 * Handles both numeric digits and spoken numbers (Russian and English)
 * 
 * @param string $text Transcription text
 * @return string Extracted digits only
 */
function extractDigits($text) {
    // First, extract any existing numeric digits
    $digits = preg_replace('/[^0-9]/', '', $text);
    
    // If we found digits, return them
    if (!empty($digits)) {
        return $digits;
    }
    
    // Otherwise, try to convert spoken numbers to digits
    $text = mb_strtolower($text, 'UTF-8');
    
    // Russian number words
    $ruNumbers = array(
        'ноль' => '0', 'нуль' => '0',
        'один' => '1', 'одна' => '1', 'одно' => '1', 'раз' => '1',
        'два' => '2', 'две' => '2',
        'три' => '3',
        'четыре' => '4',
        'пять' => '5',
        'шесть' => '6',
        'семь' => '7',
        'восемь' => '8',
        'девять' => '9',
    );
    
    // English number words
    $enNumbers = array(
        'zero' => '0',
        'one' => '1',
        'two' => '2',
        'three' => '3',
        'four' => '4',
        'five' => '5',
        'six' => '6',
        'seven' => '7',
        'eight' => '8',
        'nine' => '9',
    );
    
    $allNumbers = array_merge($ruNumbers, $enNumbers);
    
    // Split text into words and convert
    $result = '';
    $words = preg_split('/[\s,.\-]+/', $text);
    
    foreach ($words as $word) {
        $word = trim($word);
        if (isset($allNumbers[$word])) {
            $result .= $allNumbers[$word];
        } elseif (preg_match('/^\d+$/', $word)) {
            $result .= $word;
        }
    }
    
    return $result;
}

// ============================================
// MAIN SCRIPT
// ============================================

// Initialize AGI
$agi = new AGI();
$agi->log("Script started");

// Get recording filename from uniqueid
$uniqueid = isset($agi->request['uniqueid']) ? strval($agi->request['uniqueid']) : '';

if (empty($uniqueid)) {
    $agi->log("ERROR: No uniqueid provided");
    $agi->setVariable('TRANSCRIBED_DIGITS', '');
    $agi->setVariable('TRANSCRIBED_TEXT', '');
    $agi->setVariable('WHISPER_STATUS', 'ERROR_NO_UNIQUEID');
    exit(0);
}

$agi->log("Processing uniqueid: $uniqueid");

// Build recording file path
$recordingFile = RECORDINGS_PATH . $uniqueid . RECORDING_EXT;

// Also try with -in or -out suffix (common in Asterisk MixMonitor)
$possibleFiles = array(
    $recordingFile,
    RECORDINGS_PATH . $uniqueid . '-in' . RECORDING_EXT,
    RECORDINGS_PATH . $uniqueid . '-out' . RECORDING_EXT,
    RECORDINGS_PATH . $uniqueid . '-mix' . RECORDING_EXT,
);

$foundFile = null;
foreach ($possibleFiles as $file) {
    if (file_exists($file)) {
        $foundFile = $file;
        break;
    }
}

if ($foundFile === null) {
    $agi->log("ERROR: Recording file not found. Tried: " . implode(', ', $possibleFiles));
    $agi->setVariable('TRANSCRIBED_DIGITS', '');
    $agi->setVariable('TRANSCRIBED_TEXT', '');
    $agi->setVariable('WHISPER_STATUS', 'ERROR_FILE_NOT_FOUND');
    exit(0);
}

$agi->log("Found recording file: $foundFile");

// Transcribe audio
$transcription = transcribeAudio($foundFile, $agi);

if ($transcription === false) {
    $agi->log("ERROR: Transcription failed");
    $agi->setVariable('TRANSCRIBED_DIGITS', '');
    $agi->setVariable('TRANSCRIBED_TEXT', '');
    $agi->setVariable('WHISPER_STATUS', 'ERROR_TRANSCRIPTION_FAILED');
    exit(0);
}

$agi->log("Transcription result: $transcription");

// Extract digits
$digits = extractDigits($transcription);
$agi->log("Extracted digits: $digits");

// Set AGI variables for dialplan
$agi->setVariable('TRANSCRIBED_DIGITS', $digits);
$agi->setVariable('TRANSCRIBED_TEXT', $transcription);
$agi->setVariable('WHISPER_STATUS', 'SUCCESS');

$agi->log("Script completed successfully");
exit(0);

