<?php

# Posteria: A Media Poster Collection App
# Save all your favorite custom media server posters in one convenient place
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.

// Prevent direct output of errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set headers
header('Content-Type: application/json');

// Define helper functions
function getEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getIntEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

// Log function for debugging
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('plex-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Log the request
    logDebug("Request received", [
        'POST' => $_POST,
        'SESSION' => $_SESSION
    ]);

    // Include configuration
    try {
        if (file_exists('./config.php')) {
            require_once './config.php';
            logDebug("Config file loaded successfully from ./include/config.php");
        } else {
            throw new Exception("Config file not found in any of the expected locations");
        }
    } catch (Exception $e) {
        logDebug("Config file error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Config file error: ' . $e->getMessage()]);
        exit;
    }

    // Log config
    logDebug("Configurations loaded", [
        'auth_config_exists' => isset($auth_config),
        'plex_config_exists' => isset($plex_config),
        'plex_config' => isset($plex_config) ? $plex_config : null
    ]);

    // Check if auth_config and plex_config exist
    if (!isset($auth_config) || !isset($plex_config)) {
        logDebug("Missing configuration variables");
        echo json_encode(['success' => false, 'error' => 'Configuration not properly loaded']);
        exit;
    }

    // Check if Plex token is set
    if (empty($plex_config['token'])) {
        logDebug("Plex token is not set");
        echo json_encode(['success' => false, 'error' => 'Plex token is not configured. Please add your token to config.php']);
        exit;
    }

    // Check authentication
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        logDebug("Authentication required");
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Refresh session time
    $_SESSION['login_time'] = time();

    // Helper Functions
    function sanitizeFilename($filename) {
        // Remove any character that isn't alphanumeric, space, underscore, dash, or dot
        $filename = preg_replace('/[^\w\s\.-]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename); // Remove multiple spaces
        return trim($filename);
    }

	function generatePlexFilename($title, $id, $extension, $mediaType = '') {
		$basename = sanitizeFilename($title);
		if (!empty($id)) {
			$basename .= " [{$id}]";
		}
		
		// Add "Collection" before "Plex" for collection posters
		if ($mediaType === 'collections') {
			$basename .= " Collection Plex";
		} else {
			$basename .= " Plex";
		}
		
		return $basename . '.' . $extension;
	}

    function handleExistingFile($targetPath, $overwriteOption, $filename, $extension) {
        if (!file_exists($targetPath)) {
            return $targetPath; // File doesn't exist, no handling needed
        }
        
        switch ($overwriteOption) {
            case 'overwrite':
                return $targetPath; // Will overwrite existing
            case 'copy':
                $dir = dirname($targetPath);
                $basename = pathinfo($filename, PATHINFO_FILENAME);
                $counter = 1;
                $newPath = $targetPath;
                
                while (file_exists($newPath)) {
                    $newName = $basename . " ({$counter})." . $extension;
                    $newPath = $dir . '/' . $newName;
                    $counter++;
                }
                return $newPath;
            case 'skip':
            default:
                return false; // Signal to skip
        }
    }

    function getPlexHeaders($token, $start = 0, $size = 50) {
        return [
            'Accept' => 'application/json',
            'X-Plex-Token' => $token,
            'X-Plex-Client-Identifier' => 'Posteria',
            'X-Plex-Product' => 'Posteria',
            'X-Plex-Version' => '1.0',
            'X-Plex-Container-Start' => $start,
            'X-Plex-Container-Size' => $size
        ];
    }

    function makeApiRequest($url, $headers, $expectJson = true) {
        global $plex_config;
        
        logDebug("Making API request", [
            'url' => $url,
            'headers' => $headers,
            'expectJson' => $expectJson
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
            CURLOPT_TIMEOUT => $plex_config['request_timeout'],
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        // Log the response info
        logDebug("API response", [
            'http_code' => $httpCode,
            'curl_error' => $error,
            'response_length' => strlen($response),
            'curl_info' => $info,
            'response_preview' => $expectJson ? substr($response, 0, 500) . (strlen($response) > 500 ? '...' : '') : '[BINARY DATA]'
        ]);
        
        if ($response === false) {
            logDebug("API request failed: " . $error);
            throw new Exception("API request failed: " . $error);
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            logDebug("API request returned HTTP code: " . $httpCode);
            throw new Exception("API request returned HTTP code: " . $httpCode);
        }
        
        // Only validate JSON if we expect JSON
        if ($expectJson) {
            // Try to parse JSON to verify it's valid
            $jsonTest = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logDebug("Invalid JSON response: " . json_last_error_msg());
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
        }
        
        return $response;
    }

    function validatePlexConnection($serverUrl, $token) {
        try {
            logDebug("Validating Plex connection", [
                'server_url' => $serverUrl,
                'token_length' => strlen($token)
            ]);
            
            $url = rtrim($serverUrl, '/') . "/identity";
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['machineIdentifier'])) {
                logDebug("Invalid Plex server response - missing machineIdentifier");
                return ['success' => false, 'error' => 'Invalid Plex server response'];
            }
            
            logDebug("Plex connection validated successfully", [
                'identifier' => $data['MediaContainer']['machineIdentifier'],
                'version' => $data['MediaContainer']['version'] ?? 'Unknown'
            ]);
            
            return ['success' => true, 'data' => [
                'identifier' => $data['MediaContainer']['machineIdentifier'],
                'version' => $data['MediaContainer']['version'] ?? 'Unknown'
            ]];
        } catch (Exception $e) {
            logDebug("Plex connection validation failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getPlexLibraries($serverUrl, $token) {
        try {
            logDebug("Getting Plex libraries", ['server_url' => $serverUrl]);
            
            $url = rtrim($serverUrl, '/') . "/library/sections";
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Directory'])) {
                logDebug("No libraries found in Plex response");
                return ['success' => false, 'error' => 'No libraries found'];
            }
            
            $libraries = [];
            foreach ($data['MediaContainer']['Directory'] as $lib) {
                $type = $lib['type'] ?? '';
                if (in_array($type, ['movie', 'show'])) {
                    $libraries[] = [
                        'id' => $lib['key'],
                        'title' => $lib['title'],
                        'type' => $type
                    ];
                }
            }
            
            logDebug("Found Plex libraries", ['count' => count($libraries), 'libraries' => $libraries]);
            
            return ['success' => true, 'data' => $libraries];
        } catch (Exception $e) {
            logDebug("Error getting Plex libraries: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getPlexMovies($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No movies found'];
            }
            
            $movies = [];
            foreach ($data['MediaContainer']['Metadata'] as $movie) {
                if (isset($movie['thumb'])) {
                    $movies[] = [
                        'title' => $movie['title'],
                        'id' => $movie['ratingKey'],
                        'thumb' => $movie['thumb'],
                        'year' => $movie['year'] ?? '',
                        'ratingKey' => $movie['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($movies);
            $moreAvailable = ($start + count($movies)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $movies,
                'pagination' => [
                    'start' => $start,
                    'size' => count($movies),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Plex movies with pagination
    function getAllPlexMovies($serverUrl, $token, $libraryId) {
        $allMovies = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexMovies($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allMovies = array_merge($allMovies, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allMovies];
    }

    function getPlexShows($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No shows found'];
            }
            
            $shows = [];
            foreach ($data['MediaContainer']['Metadata'] as $show) {
                if (isset($show['thumb'])) {                  
                    $shows[] = [
                        'title' => $show['title'],
                        'id' => $show['ratingKey'],
                        'thumb' => $show['thumb'],
                        'year' => $show['year'] ?? '',
                        'ratingKey' => $show['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($shows);
            $moreAvailable = ($start + count($shows)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $shows,
                'pagination' => [
                    'start' => $start,
                    'size' => count($shows),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Plex shows with pagination
    function getAllPlexShows($serverUrl, $token, $libraryId) {
        $allShows = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexShows($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allShows = array_merge($allShows, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allShows];
    }

    function getPlexSeasons($serverUrl, $token, $showKey, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/metadata/{$showKey}/children";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No seasons found'];
            }
            
            $seasons = [];
            foreach ($data['MediaContainer']['Metadata'] as $season) {
                if (isset($season['thumb']) && isset($season['index']) && $season['index'] !== 0) { // Skip specials (index 0)
                    $seasons[] = [
                        'title' => $season['parentTitle'] . ' - ' . $season['title'],
                        'id' => $season['ratingKey'],
                        'thumb' => $season['thumb'],
                        'index' => $season['index'],
                        'ratingKey' => $season['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($seasons);
            $moreAvailable = ($start + count($seasons)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $seasons,
                'pagination' => [
                    'start' => $start,
                    'size' => count($seasons),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all seasons for a show with pagination
    function getAllPlexSeasons($serverUrl, $token, $showKey) {
        $allSeasons = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexSeasons($serverUrl, $token, $showKey, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allSeasons = array_merge($allSeasons, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allSeasons];
    }

    function getPlexCollections($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/collections";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => true, 'data' => []]; // Collections might be empty
            }
            
            $collections = [];
            foreach ($data['MediaContainer']['Metadata'] as $collection) {
                if (isset($collection['thumb'])) {
                    $collections[] = [
                        'title' => $collection['title'],
                        'id' => $collection['ratingKey'],
                        'thumb' => $collection['thumb'],
                        'ratingKey' => $collection['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($collections);
            $moreAvailable = ($start + count($collections)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $collections,
                'pagination' => [
                    'start' => $start,
                    'size' => count($collections),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all collections with pagination
    function getAllPlexCollections($serverUrl, $token, $libraryId) {
        $allCollections = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexCollections($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allCollections = array_merge($allCollections, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allCollections];
    }

    function downloadPlexImage($serverUrl, $token, $thumb, $targetPath) {
        try {
            $url = rtrim($serverUrl, '/') . $thumb;
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            // Pass false to indicate we don't expect JSON for image downloads
            $imageData = makeApiRequest($url, $headers, false);
            
            if (!file_put_contents($targetPath, $imageData)) {
                throw new Exception("Failed to save image to: " . $targetPath);
            }
            
            chmod($targetPath, 0644);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Process a batch of items
	function processBatch($items, $serverUrl, $token, $targetDir, $overwriteOption, $mediaType = '') {
		$results = [
		    'successful' => 0,
		    'skipped' => 0,
		    'failed' => 0,
		    'errors' => []
		];
		
		foreach ($items as $item) {
		    $title = $item['title'];
		    $id = $item['id'];
		    $thumb = $item['thumb'];
		    
		    // Generate target filename - passing mediaType parameter
		    $extension = 'jpg'; // Plex thumbnails are usually JPG
		    $filename = generatePlexFilename($title, $id, $extension, $mediaType);
		    $targetPath = $targetDir . $filename;
		    
		    // Handle existing file
		    $finalPath = handleExistingFile($targetPath, $overwriteOption, $filename, $extension);
		    
		    if ($finalPath === false) {
		        $results['skipped']++;
		        continue; // Skip this file
		    }
		    
		    // Download the image
		    $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $finalPath);
		    
		    if ($downloadResult['success']) {
		        $results['successful']++;
		    } else {
		        $results['failed']++;
		        $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
		    }
		}
		
		return $results;
	}

    // API Endpoints

    // Test Plex Connection
    if (isset($_POST['action']) && $_POST['action'] === 'test_plex_connection') {
        logDebug("Processing test_plex_connection action");
        $result = validatePlexConnection($plex_config['server_url'], $plex_config['token']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Get Plex Libraries
    if (isset($_POST['action']) && $_POST['action'] === 'get_plex_libraries') {
        logDebug("Processing get_plex_libraries action");
        $result = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Get Shows for Season Import
    if (isset($_POST['action']) && $_POST['action'] === 'get_plex_shows_for_seasons') {
        if (!isset($_POST['libraryId'])) {
            echo json_encode(['success' => false, 'error' => 'Missing library ID']);
            exit;
        }
        
        $libraryId = $_POST['libraryId'];
        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
        echo json_encode($result);
        exit;
    }

    // Import Plex Posters
    if (isset($_POST['action']) && $_POST['action'] === 'import_plex_posters') {
        if (!isset($_POST['type'], $_POST['libraryId'], $_POST['contentType'], $_POST['overwriteOption'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $type = $_POST['type']; // 'movies', 'shows', 'seasons', 'collections'
        $libraryId = $_POST['libraryId'];
        $contentType = $_POST['contentType']; // This will be the directory key
        $overwriteOption = $_POST['overwriteOption']; // 'overwrite', 'copy', 'skip'
        
        // Optional parameters
        $showKey = isset($_POST['showKey']) ? $_POST['showKey'] : null; // For single show seasons import
        $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true'; // New parameter
        
        // Validate contentType maps to a directory
        $directories = [
            'movies' => '../posters/movies/',
            'tv-shows' => '../posters/tv-shows/',
            'tv-seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];
        
        if (!isset($directories[$contentType])) {
            echo json_encode(['success' => false, 'error' => 'Invalid content type']);
            exit;
        }
        
        $targetDir = $directories[$contentType];
        
        // Ensure directory exists and is writable
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                echo json_encode(['success' => false, 'error' => 'Failed to create directory: ' . $targetDir]);
                exit;
            }
        }
        
        if (!is_writable($targetDir)) {
            echo json_encode(['success' => false, 'error' => 'Directory is not writable: ' . $targetDir]);
            exit;
        }
        
        // Start import process based on content type
        $items = [];
        $error = null;
        $totalStats = [
            'successful' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        try {
            switch ($type) {
                case 'movies':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];
                        
                        // Get all movies using pagination
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allMovies = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allMovies, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allMovies);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                        
                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allMovies),
                                'percentage' => round(($endIndex / count($allMovies)) * 100),
                                'isComplete' => $isComplete,
                                'nextIndex' => $isComplete ? null : $endIndex
                            ],
                            'results' => $batchResults,
                            'totalStats' => [
                                'successful' => $batchResults['successful'],
                                'skipped' => $batchResults['skipped'],
                                'failed' => $batchResults['failed']
                            ]
                        ]);
                        exit;
                    } else {
                        // Process all movies at once
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                case 'shows':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];
                        
                        // Get all shows using pagination
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allShows = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allShows, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allShows);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                        
                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allShows),
                                'percentage' => round(($endIndex / count($allShows)) * 100),
                                'isComplete' => $isComplete,
                                'nextIndex' => $isComplete ? null : $endIndex
                            ],
                            'results' => $batchResults,
                            'totalStats' => [
                                'successful' => $batchResults['successful'],
                                'skipped' => $batchResults['skipped'],
                                'failed' => $batchResults['failed']
                            ]
                        ]);
                        exit;
                    } else {
                        // Process all shows at once
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                case 'seasons':
                    // Check if we're importing all seasons
                    $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true';
                    
                    if ($importAllSeasons) {
                        // Get all shows first
                        $showsResult = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$showsResult['success']) {
                            throw new Exception($showsResult['error']);
                        }
                        $shows = $showsResult['data'];
                        
                        // Handle batch processing for shows to get all seasons
                        if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                            $startIndex = (int)$_POST['startIndex'];
                            
                            // If we're processing shows in batches and handling all shows' seasons
                            if ($startIndex < count($shows)) {
                                // Process seasons for this show
                                $show = $shows[$startIndex];
                                $seasonsResult = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $show['ratingKey']);
                                
                                // Get running totals from previous batches if available
                                $totalStats['successful'] = isset($_POST['totalSuccessful']) ? (int)$_POST['totalSuccessful'] : 0;
                                $totalStats['skipped'] = isset($_POST['totalSkipped']) ? (int)$_POST['totalSkipped'] : 0;
                                $totalStats['failed'] = isset($_POST['totalFailed']) ? (int)$_POST['totalFailed'] : 0;
                                
                                if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
                                    $items = $seasonsResult['data'];
                                    // Process seasons for this show
                                    $batchResults = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                                    
                                    // Update running totals
                                    $totalStats['successful'] += $batchResults['successful'];
                                    $totalStats['skipped'] += $batchResults['skipped'];
                                    $totalStats['failed'] += $batchResults['failed'];
                                    
                                    if (!empty($batchResults['errors'])) {
                                        $totalStats['errors'] = array_merge($totalStats['errors'], $batchResults['errors']);
                                    }
                                } else {
                                    $items = []; // No seasons for this show
                                    $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                                }
                                
                                // Return batch progress information for the controller
                                echo json_encode([
                                    'success' => true,
                                    'batchComplete' => true,
                                    'progress' => [
                                        'processed' => $startIndex + 1,
                                        'total' => count($shows),
                                        'percentage' => round((($startIndex + 1) / count($shows)) * 100),
                                        'isComplete' => ($startIndex + 1) >= count($shows),
                                        'nextIndex' => ($startIndex + 1) >= count($shows) ? null : $startIndex + 1,
                                        'currentShow' => $show['title'],
                                        'seasonCount' => count($items)
                                    ],
                                    'results' => $batchResults,
                                    'totalStats' => $totalStats
                                ]);
                                exit;
                            } else {
                                // All done
                                echo json_encode([
                                    'success' => true,
                                    'batchComplete' => true,
                                    'progress' => [
                                        'processed' => count($shows),
                                        'total' => count($shows),
                                        'percentage' => 100,
                                        'isComplete' => true,
                                        'nextIndex' => null
                                    ],
                                    'results' => [
                                        'successful' => 0,
                                        'skipped' => 0,
                                        'failed' => 0,
                                        'errors' => []
                                    ],
                                    'totalStats' => $totalStats
                                ]);
                                exit;
                            }
                        } else {
                            // Non-batch processing or initial call - not recommended for large libraries
                            $allSeasons = [];
                            foreach ($shows as $show) {
                                $seasonsResult = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $show['ratingKey']);
                                if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
                                    $allSeasons = array_merge($allSeasons, $seasonsResult['data']);
                                }
                            }
                            $items = $allSeasons;
                        }
                    } else {
                        // Just get seasons for one show (original behavior)
                        if (empty($showKey)) {
                            throw new Exception('Show key is required for single-show seasons import');
                        }
                        
                        if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                            $startIndex = (int)$_POST['startIndex'];
                            $batchSize = $plex_config['import_batch_size'];
                            
                            // Get all seasons for this show
                            $result = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $showKey);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }
                            $allSeasons = $result['data'];
                            
                            // Process this batch
                            $currentBatch = array_slice($allSeasons, $startIndex, $batchSize);
                            $endIndex = $startIndex + count($currentBatch);
                            $isComplete = $endIndex >= count($allSeasons);
                            
                            // Process the batch
                            $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption);
                            
                            // Respond with batch results and progress
                            echo json_encode([
                                'success' => true,
                                'batchComplete' => true,
                                'progress' => [
                                    'processed' => $endIndex,
                                    'total' => count($allSeasons),
                                    'percentage' => round(($endIndex / count($allSeasons)) * 100),
                                    'isComplete' => $isComplete,
                                    'nextIndex' => $isComplete ? null : $endIndex
                                ],
                                'results' => $batchResults,
                                'totalStats' => [
                                    'successful' => $batchResults['successful'],
                                    'skipped' => $batchResults['skipped'],
                                    'failed' => $batchResults['failed']
                                ]
                            ]);
                            exit;
                        } else {
                            // Get all seasons for this show
                            $result = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $showKey);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }
                            $items = $result['data'];
                        }
                    }
                    break;
                
                case 'collections':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];
                        
                        // Get all collections using pagination
                        $result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allCollections = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allCollections, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allCollections);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                        
                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allCollections),
                                'percentage' => round(($endIndex / count($allCollections)) * 100),
                                'isComplete' => $isComplete,
                                'nextIndex' => $isComplete ? null : $endIndex
                            ],
                            'results' => $batchResults,
                            'totalStats' => [
                                'successful' => $batchResults['successful'],
                                'skipped' => $batchResults['skipped'],
                                'failed' => $batchResults['failed']
                            ]
                        ]);
                        exit;
                    } else {
                        // Process all collections at once
                        $result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                default:
                    throw new Exception('Invalid import type');
            }
            
            // This code will only execute for non-batch processing, which is not recommended for large libraries
            // Process all items
            $results = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
            
            echo json_encode([
                'success' => true,
                'complete' => true,
                'processed' => count($items),
                'results' => $results,
                'totalStats' => [
                    'successful' => $results['successful'],
                    'skipped' => $results['skipped'],
                    'failed' => $results['failed']
                ]
            ]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Default response if no action matched
    logDebug("No matching action found");
    echo json_encode(['success' => false, 'error' => 'Invalid action requested']);

} catch (Exception $e) {
    // Log the error
    logDebug("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
