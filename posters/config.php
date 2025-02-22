<?php

$auth_config = [
	'username' => getEnvWithFallback('AUTH_USERNAME', 'admin'),
	'password' => getEnvWithFallback('AUTH_PASSWORD', 'changeme'),
	'session_duration' => getIntEnvWithFallback('SESSION_DURATION', 3600) // 1 hour default
];

?>
