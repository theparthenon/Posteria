<?php

$auth_config = [
	'username' => getEnvWithFallback('AUTH_USERNAME', 'admin'),
	'password' => getEnvWithFallback('AUTH_PASSWORD', 'changeme'),
	'session_duration' => getIntEnvWithFallback('SESSION_DURATION', 3600) // 1 hour default
];

// Plex Configuration
$plex_config = [
    'server_url' => getEnvWithFallback('PLEX_SERVER_URL', ''),
    'token' => getEnvWithFallback('PLEX_TOKEN', ''),
    'connect_timeout' => getIntEnvWithFallback('PLEX_CONNECT_TIMEOUT', 10),
    'request_timeout' => getIntEnvWithFallback('PLEX_REQUEST_TIMEOUT', 60),
    'import_batch_size' => getIntEnvWithFallback('PLEX_IMPORT_BATCH_SIZE', 25)
];

?>
