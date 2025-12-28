<?php
/**
 * Configuration file for Whisper AGI script
 * Copy this file and adjust settings for your environment
 */

// OpenAI API Key
// Can also be set via environment variable OPENAI_API_KEY
define('OPENAI_API_KEY', 'sk-your-api-key-here');

// Whisper model
define('WHISPER_MODEL', 'whisper-1');

// Corporate GPT proxy URL (same as LibreChat uses)
// Can also be set via environment variable GPT_PROXY_URL
define('GPT_PROXY_BASE_URL', 'https://gptproxy.example.com/v1/');

// Whisper API endpoint (built from proxy base URL)
define('WHISPER_API_URL', rtrim(GPT_PROXY_BASE_URL, '/') . '/audio/transcriptions');

// Path to Asterisk recordings
define('RECORDINGS_PATH', '/var/spool/asterisk/monitor/');

// Recording file extension
define('RECORDING_EXT', '.wav');

// Debug mode (logs to Asterisk CLI and syslog)
define('DEBUG', true);

// Default language for transcription (ISO 639-1)
// 'ru' for Russian, 'en' for English, etc.
define('TRANSCRIPTION_LANGUAGE', 'ru');

