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
 
    error_reporting(E_ALL);
	ini_set('display_errors', 0);

	session_start();
	
	// Helper function to get environment variable with fallback
	function getEnvWithFallback($key, $default) {
		$value = getenv($key);
		return $value !== false ? $value : $default;
	}

	// Helper function to get integer environment variable with fallback
	function getIntEnvWithFallback($key, $default) {
		$value = getenv($key);
		return $value !== false ? intval($value) : $default;
	}
	
	$site_title = getEnvWithFallback('SITE_TITLE', 'Posteria');
	
	require_once './posters/config.php';
	
	$config = [
		'directories' => [
			'movies' => 'posters/movies/',
			'tv-shows' => 'posters/tv-shows/',
			'tv-seasons' => 'posters/tv-seasons/',
			'collections' => 'posters/collections/'
		],
		'imagesPerPage' => getIntEnvWithFallback('IMAGES_PER_PAGE', 24),
		'allowedExtensions' => ['jpg', 'jpeg', 'png', 'webp'],
		'siteUrl' => (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/',
		'maxFileSize' => getIntEnvWithFallback('MAX_FILE_SIZE', 5 * 1024 * 1024) // 5MB default
	];

	$loginError = '';

	// Get current directory filter from URL parameter
	$currentDirectory = isset($_GET['directory']) ? trim($_GET['directory']) : '';
	if (!empty($currentDirectory) && !isset($config['directories'][$currentDirectory])) {
		$currentDirectory = '';
	}


	function sendJsonResponse($success, $error = null) {
		header('Content-Type: application/json');
		echo json_encode([
		    'success' => $success,
		    'error' => $error
		]);
		exit;
	}
	
	function getImageFiles($config, $currentDirectory = '') {
		$files = [];
		
		if (empty($currentDirectory)) {
		    // Get files from all directories
		    foreach ($config['directories'] as $dirKey => $dirPath) {
		        if (is_dir($dirPath)) {
		            if ($handle = opendir($dirPath)) {
		                while (($file = readdir($handle)) !== false) {
		                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		                    if (in_array($extension, $config['allowedExtensions'])) {
		                        $files[] = [
		                            'filename' => $file,
		                            'directory' => $dirKey,
		                            'fullpath' => $dirPath . $file
		                        ];
		                    }
		                }
		                closedir($handle);
		            }
		        }
		    }
		} else {
		    // Get files from specific directory
		    $dirPath = $config['directories'][$currentDirectory];
		    if (is_dir($dirPath)) {
		        if ($handle = opendir($dirPath)) {
		            while (($file = readdir($handle)) !== false) {
		                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		                if (in_array($extension, $config['allowedExtensions'])) {
		                    $files[] = [
		                        'filename' => $file,
		                        'directory' => $currentDirectory,
		                        'fullpath' => $dirPath . $file
		                    ];
		                }
		            }
		            closedir($handle);
		        }
		    }
		}
		
		// Sort files alphabetically
		usort($files, function($a, $b) {
		    return strnatcasecmp($a['filename'], $b['filename']);
		});
		
		return $files;
	}

	function fuzzySearch($pattern, $str) {
		$pattern = strtolower($pattern);
		$str = strtolower($str);
		$patternLength = strlen($pattern);
		$strLength = strlen($str);
		
		if ($patternLength > $strLength) {
		    return false;
		}
		
		if ($patternLength === $strLength) {
		    return $pattern === $str;
		}
		
		$previousIndex = -1;
		for ($i = 0; $i < $patternLength; $i++) {
		    $currentChar = $pattern[$i];
		    $index = strpos($str, $currentChar, $previousIndex + 1);
		    
		    if ($index === false) {
		        return false;
		    }
		    
		    $previousIndex = $index;
		}
		
		return true;
	}

	function filterImages($images, $searchQuery) {
		if (empty($searchQuery)) {
		    return $images;
		}
		
		$filteredImages = [];
		foreach ($images as $image) {
		    $filename = pathinfo($image['filename'], PATHINFO_FILENAME);
		    if (fuzzySearch($searchQuery, $filename)) {
		        $filteredImages[] = $image;
		    }
		}
		
		return $filteredImages;
	}

	function isLoggedIn() {
		return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
	}

	function isValidFilename($filename) {
		// Check for slashes and backslashes
		if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
		    return false;
		}
		return true;
	}

	function formatDirectoryName($dirKey) {
		$text = str_replace('-', ' ', $dirKey);
		
		// Handle TV-related text
		if (stripos($text, 'tv') === 0) {
		    $text = 'TV ' . ucwords(substr($text, 3));
		} else {
		    $text = ucwords($text);
		}
		
		return $text;
	}

	function generateUniqueFilename($originalName, $directory) {
		$info = pathinfo($originalName);
		$ext = strtolower($info['extension']);
		$filename = $info['filename'];
		
		$newFilename = $filename;
		$counter = 1;
		
		while (file_exists($directory . $newFilename . '.' . $ext)) {
		    $newFilename = $filename . '_' . $counter;
		    $counter++;
		}
		
		return $newFilename . '.' . $ext;
	}

	function isAllowedFileType($filename, $allowedExtensions) {
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		return in_array($ext, $allowedExtensions);
	}

	// Generate pagination links
	function generatePaginationLinks($currentPage, $totalPages, $searchQuery, $currentDirectory) {
		$links = '';
		$params = [];
		if (!empty($searchQuery)) $params['search'] = $searchQuery;
		if (!empty($currentDirectory)) $params['directory'] = $currentDirectory;
		$queryString = http_build_query($params);
		$baseUrl = '?' . ($queryString ? $queryString . '&' : '');
		
		// Previous page link
		if ($currentPage > 1) {
		    $links .= "<a href=\"" . $baseUrl . "page=" . ($currentPage - 1) . "\" class=\"pagination-link\">&laquo;</a> ";
		} else {
		    $links .= "<span class=\"pagination-link disabled\">&laquo;</span> ";
		}
		
		// Page number links
		$startPage = max(1, $currentPage - 2);
		$endPage = min($totalPages, $currentPage + 2);
		
		if ($startPage > 1) {
		    $links .= "<a href=\"" . $baseUrl . "page=1\" class=\"pagination-link\">1</a> ";
		    if ($startPage > 2) {
		        $links .= "<span class=\"pagination-ellipsis\">...</span> ";
		    }
		}
		
		for ($i = $startPage; $i <= $endPage; $i++) {
		    if ($i == $currentPage) {
		        $links .= "<span class=\"pagination-link current\">{$i}</span> ";
		    } else {
		        $links .= "<a href=\"" . $baseUrl . "page={$i}\" class=\"pagination-link\">{$i}</a> ";
		    }
		}
		
		if ($endPage < $totalPages) {
		    if ($endPage < $totalPages - 1) {
		        $links .= "<span class=\"pagination-ellipsis\">...</span> ";
		    }
		    $links .= "<a href=\"" . $baseUrl . "page={$totalPages}\" class=\"pagination-link\">{$totalPages}</a> ";
		}
		
		// Next page link
		if ($currentPage < $totalPages) {
		    $links .= "<a href=\"" . $baseUrl . "page=" . ($currentPage + 1) . "\" class=\"pagination-link\">&raquo;</a>";
		} else {
		    $links .= "<span class=\"pagination-link disabled\">&raquo;</span>";
		}
		
		return $links;
	}

	// Handle login
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login' && 
		isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
		header('Content-Type: application/json');
		
		if ($_POST['username'] === $auth_config['username'] && $_POST['password'] === $auth_config['password']) {
		    $_SESSION['logged_in'] = true;
		    $_SESSION['login_time'] = time();
		    echo json_encode(['success' => true]);
		} else {
		    echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
		}
		exit;
	}

	// Regular form login (fallback)
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
		if ($_POST['username'] === $auth_config['username'] && $_POST['password'] === $auth_config['password']) {
		    $_SESSION['logged_in'] = true;
		    $_SESSION['login_time'] = time();
		    header('Location: ' . $_SERVER['PHP_SELF']);
		    exit;
		}
	}

	// Handle logout
	if (isset($_GET['action']) && $_GET['action'] === 'logout') {
		session_destroy();
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	// Check session expiration
	if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $auth_config['session_duration']) {
		session_destroy();
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	// Handle file move
	if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move') {
		header('Content-Type: application/json');
		
		$filename = isset($_POST['filename']) ? $_POST['filename'] : '';
		$sourceDirectory = isset($_POST['source_directory']) ? $_POST['source_directory'] : '';
		$targetDirectory = isset($_POST['target_directory']) ? $_POST['target_directory'] : '';
		
		if (empty($filename) || empty($sourceDirectory) || empty($targetDirectory) || 
		    !isset($config['directories'][$sourceDirectory]) || !isset($config['directories'][$targetDirectory])) {
		    echo json_encode(['success' => false, 'error' => 'Invalid request']);
		    exit;
		}
		
		$sourcePath = $config['directories'][$sourceDirectory] . $filename;
		$targetPath = $config['directories'][$targetDirectory] . $filename;
		
		// Security checks
		if (!isValidFilename($filename) || !file_exists($sourcePath)) {
		    echo json_encode(['success' => false, 'error' => 'Invalid file']);
		    exit;
		}
		
		// Check if a file with the same name exists in target directory
		if (file_exists($targetPath)) {
		    echo json_encode(['success' => false, 'error' => 'A file with this name already exists in the target directory']);
		    exit;
		}
		
		if (rename($sourcePath, $targetPath)) {
		    echo json_encode(['success' => true]);
		} else {
		    echo json_encode(['success' => false, 'error' => 'Failed to move file']);
		}
		exit;
	}

	// Handle file upload
	if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
		header('Content-Type: application/json');
		
		// Handle file upload from disk
		if ($_POST['upload_type'] === 'file' && isset($_FILES['image'])) {
		    $file = $_FILES['image'];
		    $directory = isset($_POST['directory']) ? $_POST['directory'] : 'movies';
		    
		    // Validate directory exists
		    if (!isset($config['directories'][$directory])) {
		        sendJsonResponse(false, 'Invalid directory');
		    }
		    
		    // Check for upload errors
		    if ($file['error'] !== UPLOAD_ERR_OK) {
		        $error_message = match($file['error']) {
		            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive',
		            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
		            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
		            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
		            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
		            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
		            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
		            default => 'Unknown upload error'
		        };
		        sendJsonResponse(false, $error_message);
		    }
		    
		    // Validate file size
		    if ($file['size'] > $config['maxFileSize']) {
		        sendJsonResponse(false, 'File too large. Maximum size is ' . ($config['maxFileSize'] / 1024 / 1024) . 'MB');
		    }
		    
		    // Validate file type
		    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		    if (!in_array($ext, $config['allowedExtensions'])) {
		        sendJsonResponse(false, 'Invalid file type. Allowed types: ' . implode(', ', $config['allowedExtensions']));
		    }
		    
		    // Generate unique filename
		    $filename = generateUniqueFilename($file['name'], $config['directories'][$directory]);
		    $filepath = $config['directories'][$directory] . $filename;
		    
		    // Ensure directory exists and is writable
		    $targetDir = dirname($filepath);
		    if (!is_dir($targetDir)) {
		        if (!mkdir($targetDir, 0755, true)) {
		            sendJsonResponse(false, 'Failed to create directory structure');
		        }
		    }
		    
		    if (!is_writable($targetDir)) {
		        sendJsonResponse(false, 'Directory is not writable');
		    }
		    
		    // Move uploaded file
		    if (move_uploaded_file($file['tmp_name'], $filepath)) {
		        // Set proper permissions
		        chmod($filepath, 0644);
		        sendJsonResponse(true);
		    } else {
		        sendJsonResponse(false, 'Failed to save file. Please check directory permissions.');
		    }
		}
		
	// Handle URL upload
	if ($_POST['upload_type'] === 'url' && isset($_POST['image_url'])) {
		$url = $_POST['image_url'];
		$directory = isset($_POST['directory']) ? $_POST['directory'] : 'movies';
		
		// Validate URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
		    sendJsonResponse(false, 'Invalid URL format');
		}
		
		// Validate directory exists
		if (!isset($config['directories'][$directory])) {
		    sendJsonResponse(false, 'Invalid directory');
		}
		
		// Get file info and decode URL-encoded spaces in the basename
		$fileInfo = pathinfo(urldecode($url));
		$decodedBasename = $fileInfo['basename'];
		$ext = strtolower($fileInfo['extension']);
		
		// Validate file type
		if (!in_array($ext, $config['allowedExtensions'])) {
		    sendJsonResponse(false, 'Invalid file type. Allowed types: ' . implode(', ', $config['allowedExtensions']));
		}
		
		// Generate unique filename using the decoded basename
		$filename = generateUniqueFilename($decodedBasename, $config['directories'][$directory]);
		$filepath = $config['directories'][$directory] . $filename;
		
		// Ensure directory exists and is writable
		$targetDir = dirname($filepath);
		if (!is_dir($targetDir)) {
		    if (!mkdir($targetDir, 0755, true)) {
		        sendJsonResponse(false, 'Failed to create directory structure');
		    }
		}
		
		if (!is_writable($targetDir)) {
		    sendJsonResponse(false, 'Directory is not writable');
		}
		
		// Initialize curl
		$ch = curl_init();
		curl_setopt_array($ch, [
		    CURLOPT_URL => $url,
		    CURLOPT_RETURNTRANSFER => true,
		    CURLOPT_FOLLOWLOCATION => true,
		    CURLOPT_SSL_VERIFYPEER => false,
		    CURLOPT_SSL_VERIFYHOST => false,
		    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
		    CURLOPT_TIMEOUT => 30
		]);
		
		$fileContent = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		$contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
		curl_close($ch);

		// Check for curl errors
		if ($fileContent === false) {
		    sendJsonResponse(false, 'Download failed: ' . $error);
		}

		// Check HTTP response code
		if ($httpCode !== 200) {
		    sendJsonResponse(false, 'HTTP error: ' . $httpCode);
		}

		// Check file size
		$downloadedSize = strlen($fileContent);
		if ($downloadedSize > $config['maxFileSize']) {
		    sendJsonResponse(false, 'File exceeds maximum allowed size of ' . ($config['maxFileSize'] / 1024 / 1024) . 'MB');
		}

		// Verify the downloaded content is an image
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->buffer($fileContent);
		if (!str_starts_with($mimeType, 'image/')) {
		    sendJsonResponse(false, 'Downloaded content is not an image');
		}

		// Save the file
		if (file_put_contents($filepath, $fileContent)) {
		    chmod($filepath, 0644);
		    sendJsonResponse(true);
		} else {
		    sendJsonResponse(false, 'Failed to save file');
		}
	}
	}

	// Handle file delete
	if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
		header('Content-Type: application/json');
		
		$filename = isset($_POST['filename']) ? $_POST['filename'] : '';
		$directory = isset($_POST['directory']) ? $_POST['directory'] : '';
		
		if (empty($filename) || empty($directory) || !isset($config['directories'][$directory])) {
		    echo json_encode(['success' => false, 'error' => 'Invalid request']);
		    exit;
		}
		
		$filepath = $config['directories'][$directory] . $filename;
		
		// Security check: Ensure the file is within allowed directory
		if (!isValidFilename($filename) || !file_exists($filepath)) {
		    echo json_encode(['success' => false, 'error' => 'Invalid file']);
		    exit;
		}
		
		if (unlink($filepath)) {
		    echo json_encode(['success' => true]);
		} else {
		    echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
		}
		exit;
	}

	// Handle file rename
	if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename') {
		header('Content-Type: application/json');
		
		$oldFilename = isset($_POST['old_filename']) ? $_POST['old_filename'] : '';
		$newFilename = isset($_POST['new_filename']) ? $_POST['new_filename'] : '';
		$directory = isset($_POST['directory']) ? $_POST['directory'] : '';
		
		if (empty($oldFilename) || empty($newFilename) || empty($directory) || !isset($config['directories'][$directory])) {
		    echo json_encode(['success' => false, 'error' => 'Invalid request']);
		    exit;
		}
		
		// Add extension from old filename if not provided in new filename
		if (!pathinfo($newFilename, PATHINFO_EXTENSION)) {
		    $newFilename .= '.' . pathinfo($oldFilename, PATHINFO_EXTENSION);
		}
		
		$oldFilepath = $config['directories'][$directory] . $oldFilename;
		$newFilepath = $config['directories'][$directory] . $newFilename;
		
		// Check if a file with the new name already exists
		if (file_exists($newFilepath) && $oldFilename !== $newFilename) {
		    echo json_encode(['success' => false, 'error' => 'A file with this name already exists']);
		    exit;
		}
		
		// Security checks
		if (!isValidFilename($oldFilename) || !isValidFilename($newFilename) || !file_exists($oldFilepath)) {
		    echo json_encode(['success' => false, 'error' => 'Invalid file']);
		    exit;
		}
		
		if (rename($oldFilepath, $newFilepath)) {
		    echo json_encode(['success' => true]);
		} else {
		    echo json_encode(['success' => false, 'error' => 'Failed to rename file']);
		}
		exit;
	}

	// Handle download request
	if (isset($_GET['download']) && !empty($_GET['download'])) {
		$requestedFile = $_GET['download'];
		$directory = isset($_GET['dir']) ? $_GET['dir'] : '';
		
		if (!empty($directory) && isset($config['directories'][$directory])) {
		    $filePath = $config['directories'][$directory] . $requestedFile;
		    
		    // Validate the file exists and is allowed
		    $extension = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
		    if (file_exists($filePath) && in_array($extension, $config['allowedExtensions'])) {
		        // Set headers for download
		        header('Content-Description: File Transfer');
		        header('Content-Type: application/octet-stream');
		        header('Content-Disposition: attachment; filename="' . basename($requestedFile) . '"');
		        header('Expires: 0');
		        header('Cache-Control: must-revalidate');
		        header('Pragma: public');
		        header('Content-Length: ' . filesize($filePath));
		        
		        // Output file and exit
		        readfile($filePath);
		        exit;
		    }
		}
	}

	// Get all image files
	$allImages = getImageFiles($config, $currentDirectory);

	// Get search query
	$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

	// Filter images based on search query
	$filteredImages = filterImages($allImages, $searchQuery);

	// Calculate pagination
	$totalImages = count($filteredImages);
	$totalPages = ceil($totalImages / $config['imagesPerPage']);
	$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
	$page = max(1, $page); // Ensure page is at least 1
	$page = min($page, max(1, $totalPages)); // Ensure page doesn't exceed total pages

	// Get images for current page
	$startIndex = ($page - 1) * $config['imagesPerPage'];
	$pageImages = array_slice($filteredImages, $startIndex, $config['imagesPerPage']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?></title>
    <meta property="og:title" content="Posteria" />
    <meta property="og:type" content="website" />
    <meta property="og:description" content="Posteria" />
    <meta property="og:url" content="<?php echo htmlspecialchars($config['siteUrl']); ?>" />
    <meta property="og:image" content="<?php echo htmlspecialchars($config['siteUrl']); ?>/assets/web-app-manifest-512x512.png" />
    <link rel="icon" type="image/png" href="/assets/favicon-96x96.png" sizes="96x96" />
	<link rel="icon" type="image/svg+xml" href="./assets/favicon.svg" />
	<link rel="shortcut icon" href="./assets/favicon.ico" />
	<link rel="apple-touch-icon" sizes="180x180" href="./assets/apple-touch-icon.png" />
	<meta name="apple-mobile-web-app-title" content="Posteria" />
	<link rel="manifest" href="./assets/site.webmanifest" />
    <style>
		/* Theme Variables */
		:root {
			--bg-primary: #1f1f1f;
			--bg-secondary: #282828;
			--bg-tertiary: #333333;
			--text-primary: #ffffff;
			--text-secondary: #999999;
			--accent-primary: #e5a00d;
			--accent-hover: #f5b025;
			--border-color: #3b3b3b;
			--card-bg: #282828;
			--card-hover: #333333;
			--success-color: #2ed573;
			--danger-color: #ff4757;
			--action-bg: rgba(0, 0, 0, 0.85);
			--shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.4);
			--shadow-md: 0 6px 12px rgba(0, 0, 0, 0.5);
		}

		/* Base Styles */
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		html {
			scrollbar-gutter: stable;
			overflow-y: scroll;
			scrollbar-color: var(--bg-tertiary) var(--bg-primary);
			scrollbar-width: thin;
		}

		/* Custom Scrollbar */
		::-webkit-scrollbar {
			width: 12px;
			height: 12px;
			background-color: var(--bg-primary);
		}

		::-webkit-scrollbar-track {
			background: var(--bg-primary);
			border-radius: 8px;
			border: 2px solid var(--bg-secondary);
		}

		::-webkit-scrollbar-thumb {
			background: var(--bg-tertiary);
			border-radius: 8px;
			border: 3px solid var(--bg-primary);
			min-height: 40px;
		}

		::-webkit-scrollbar-thumb:hover {
			background: var(--accent-primary);
			border-width: 2px;
		}

		::-webkit-scrollbar-corner {
			background: var(--bg-primary);
		}

		body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			line-height: 1.6;
			color: var(--text-primary);
			background-color: var(--bg-primary);
			padding: 20px;
			background-image: linear-gradient(to bottom, #1a1a1a, #1f1f1f);
			min-height: 100vh;

		.container {
			max-width: 1200px;
			margin: 0 auto;
			padding-bottom: 10px;
			height: 100%;
		}

		/* Header Styles */
		header {
			text-align: center;
			margin-bottom: 40px;
			padding-top: 20px;
		}

		.header-content {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
		}

		h1 {
			font-weight: 600;
			letter-spacing: -0.025em;
			color: var(--text-primary);
			font-size: 3rem;
			margin-bottom: 15px;
		}

		.site-name {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			display: flex;
			align-items: center;

		}

		.site-name svg {
			flex-shrink: 0; /* Prevents SVG from shrinking */
			height: 80px;
		}

		/* Auth Actions */
		.auth-actions {
			display: flex;
			gap: 12px;
			align-items: center;
		}

		/* Button Styles */
		.login-trigger-button,
		.upload-trigger-button,
		.logout-button {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 12px 20px;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.2s;
			text-decoration: none;
			border: none;
		}

		.login-trigger-button,
		.upload-trigger-button {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
		}

		.login-trigger-button:hover,
		.upload-trigger-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		.logout-button {
			background: var(--bg-tertiary);  /* #333333 */
			color: var(--text-secondary);    /* #999999 */
			height: 44px;
			border: 1px solid var(--border-color);  /* #3b3b3b */
		}

		.logout-button:hover {
			background: #3d3d3d;
			color: var(--text-primary);     /* #ffffff */
			border-color: var(--text-secondary);  /* #999999 */
			transform: translateY(-2px);
		}

		/* Icon Styles */
		.login-icon,
		.upload-icon,
		.logout-icon {
			width: 20px;
			height: 20px;
		}

		/* Search Styles */
		.search-container {
			margin-bottom: 40px;
			text-align: center;
			position: relative;
		}

		.search-form {
			display: inline-flex;
			width: 100%;
			max-width: 500px;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: var(--shadow-md);
			border: 1px solid var(--border-color);
		}

		.search-input {
			flex-grow: 1;
			padding: 14px 20px;
			background-color: var(--bg-secondary);
			color: var(--text-primary);
			font-size: 16px;
			border: none;
		}

		.search-input:focus {
			outline: none;
			background-color: var(--bg-tertiary);
		}

		.search-button {
			padding: 14px 24px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			cursor: pointer;
			font-size: 16px;
			font-weight: 600;
		}

		.search-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
		}

		/* Filter Styles */
		.filter-container {
			margin: 20px 0 30px;
			text-align: center;
		}

		.filter-buttons {
			display: inline-flex;
			gap: 10px;
			background: var(--bg-secondary);
			padding: 5px;
			border-radius: 8px;
			border: 1px solid var(--border-color);
		}

		.filter-button {
			padding: 8px 16px;
			border: none;
			border-radius: 6px;
			background: transparent;
			color: var(--text-primary);
			cursor: pointer;
			transition: all 0.2s;
			font-weight: 500;
			text-decoration: none;
		}

		.filter-button:hover {
			background: var(--bg-tertiary);
		}

		.filter-button.active {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			font-weight: 600;
		}

		/* Gallery Stats */
		.gallery-stats {
			text-align: center;
			margin-bottom: 30px;
			color: var(--text-secondary);
			font-size: 14px;
		}

		.gallery-stats a {
			color: var(--accent-primary);
			text-decoration: none;
			font-weight: 500;
			margin-left: 8px;
		}

		.gallery-stats a:hover {
			color: var(--accent-hover);
			text-decoration: underline;
		}

		/* Gallery Grid */
		.gallery {
			display: grid;
			grid-template-columns: repeat(4, minmax(200px, 1fr));
			gap: 25px;
			margin-bottom: 40px;
			width: 100%;
		}

		.gallery-item {
			background: var(--card-bg);
			border-radius: 12px;
			overflow: hidden;
			box-shadow: var(--shadow-sm);
			transition: all 0.3s ease;
			position: relative;
			border: 1px solid var(--border-color);
		}

		.gallery-item:hover {
			transform: translateY(-5px);
			box-shadow: var(--shadow-md);
			border-color: var(--accent-primary);
		}

		@media (hover: none) {
			.gallery-item:hover {
				transform: none;
				box-shadow: none;
				border: none;
				border-color: unset;
			}
			
			.gallery-image-container:hover .gallery-image {
				transform: none;
			}
			.overlay-action-button:hover {
				background: linear-gradient(45deg, #f5b025, #ffa953);
				transform: none;
			}
		}

		/* Gallery Image Styles */
		.gallery-image-container {
			position: relative;
			overflow: hidden;
			border-radius: 12px 12px 0 0;
			display: flex;
			align-items: center;
			justify-content: center;
			aspect-ratio: 2/3;  /* Standard movie poster ratio */
			width: 100%;
			background: var(--bg-tertiary);
		}

		.gallery-image {
			width: 100%;
			height: 100%;
			object-fit: cover; /* Changed from cover to contain */
			display: block;
			transition: transform 0.5s ease, opacity 0.3s ease;
			opacity: 0;
			background: var(--bg-tertiary);
			max-height: 100%;
			max-width: 100%;
		}

		.gallery-image.loaded {
			opacity: 1;
		}

		.gallery-image-container:hover .gallery-image {
			transform: scale(1.05);
		}

		.gallery-image-placeholder {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: var(--bg-tertiary);
			display: flex;
			align-items: center;
			justify-content: center;
			transition: opacity 0.3s ease;
		}

		.gallery-image-placeholder.hidden {
			opacity: 0;
		}

		/* Loading Spinner */
		.loading-spinner {
			width: 40px;
			height: 40px;
			border-radius: 50%;
			border: 4px solid var(--text-secondary);
			border-top-color: var(--accent-primary);
			animation: spin 1s infinite linear;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		/* Image Caption */
		.gallery-caption {
			padding: 16px;
			text-align: center;
			word-break: break-word;
			font-weight: 500;
			color: var(--text-primary);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			position: relative;
		}

		/* Directory Badge */
		.directory-badge {
			position: absolute;
			top: 10px;
			left: 10px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			padding: 4px 8px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
			opacity: 0.9;
			z-index: 0;
		}

		/* Image Actions */
		.image-overlay-actions {
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			display: flex;

			justify-content: center;
			align-items: center;
			background: var(--action-bg);
			opacity: 0;
			transition: opacity 0.3s ease;
			gap: 15px;
			flex-direction: column;
		}

		/* Desktop hover behavior */
		@media (hover: hover) {
			.gallery-item:hover .image-overlay-actions {
				opacity: 1;
			}
		}

		/* Mobile touch behavior */
		.gallery-item.touch-active .image-overlay-actions {
			opacity: 1;
		}

		.overlay-action-button {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			padding: 10px 16px;
			border-radius: 6px;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 14px;
			font-weight: 600;
			transition: all 0.2s;
			box-shadow: 0 2px 5px rgba(0,0,0,0.2);
			text-decoration: none;
			width: 70%;
			margin: 0 auto;
			border: none;
		}

		.overlay-action-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		.image-action-icon {
			margin-right: 8px;
			width: 16px;
			height: 16px;
		}

		/* Modal Styles */
		.modal {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
			z-index: 1000;
			opacity: 0;
			transition: opacity 0.3s ease;
		}

		.modal.show {
			opacity: 1;
		}

		.modal-content {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.9);
			background: var(--bg-secondary);
			padding: 24px;
			border-radius: 12px;
			width: 90%;
			max-width: 400px;
			transition: transform 0.3s ease;
			border: 1px solid var(--border-color);
			box-shadow: var(--shadow-md);
		}

		.modal.show .modal-content {
			transform: translate(-50%, -50%) scale(1);
		}

		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 20px 0;
			padding-top: 0px;
		}

		.modal-header h3 {
			margin: 0;
			font-size: 1.25rem;
			color: var(--text-primary);
		}

		.modal-close-btn {
			background: none;
			border: none;
			color: var(--text-secondary);
			font-size: 24px;
			cursor: pointer;
			padding: 0;
			line-height: 1;
		}

		.modal-close-btn:hover {
			color: var(--text-primary);
		}

		/* Modal Action Buttons */
		.modal-actions {
			display: flex;
			gap: 12px;
			justify-content: flex-end;
			margin-top: 20px;
		}

		.modal-button {
			padding: 12px 24px;
			border-radius: 6px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			font-size: 14px;
			border: none;
		}

		.modal-button.cancel {
			background: var(--bg-secondary);
			color: var(--text-primary);
			border: 1px solid var(--border-color);
		}

		.modal-button.cancel:hover {
			background: var(--bg-tertiary);
			border-color: var(--accent-primary);
			transform: translateY(-1px);
		}

		.modal-button.delete {
			background: #8B0000;
			color: var(--text-primary);         /* #ffffff */
			border: 1px solid #a83232;
		}

		.modal-button.delete:hover {
			background: #a31c1c;
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
			border-color: #c73e3e;
		}

		.delete-btn {
			background: #8B0000;
			color: var(--text-primary);         /* #ffffff */
			border: 1px solid #a83232;
		}

		.delete-btn:hover {
			background: #a31c1c;
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
			border-color: #c73e3e;
		}

		.modal-button.rename,
		.modal-button.move {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
		}

		.modal-button.rename:hover,
		.modal-button.move:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
		}

		/* Login Form Styles */
		.login-container {
			max-width: 400px;
			margin: 40px auto;
			padding: 24px;
			background: var(--card-bg);
			border-radius: 12px;
			box-shadow: var(--shadow-md);
		}

		.login-form {
			display: flex;
			flex-direction: column;
			gap: 16px;
		}

		.login-input {
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			width: 100%;
			height: 40px;
		}

		/* Style for select elements using login-input class */
		select.login-input {
			padding-right: 36px; /* More space for the arrow */
			appearance: none; /* Remove default arrow */
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23999999' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
			background-repeat: no-repeat;
			background-position: right 8px center;
			background-size: 16px;
			cursor: pointer;
		}

		.login-button {
			width: 100%;
			padding: 12px 20px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			border-radius: 6px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.2s ease;
			margin-top: 8px;
		}

		.login-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
		}

		/* Error Messages */
		.login-error,
		.rename-error {
			color: var(--danger-color);
			background: rgba(239, 68, 68, 0.1);
			border: 1px solid var(--danger-color);
			padding: 12px;
			border-radius: 6px;
			margin-bottom: 16px;
			display: none;
		}

		/* Upload Form Styles */
		.upload-modal .modal-content {
			max-width: 600px;
			padding: 0;
		}

		.upload-modal .modal-header {
			padding: 20px 24px;
		}

		.upload-tabs {
			display: flex;
			gap: 10px;
			padding: 20px 24px 0;
			margin-bottom: 0;
		}

		.upload-content {
			padding: 20px 24px;
		}

		.upload-tab-btn {
			padding: 10px 20px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			color: var(--text-primary);
			border-radius: 6px;
			cursor: pointer;
			transition: all 0.3s ease;
		}

		.upload-tab-btn.active {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border-color: transparent;
			font-weight: 600;
		}

		.upload-form {
			display: none;
			margin-bottom: 0;
		}

		.upload-form.active {
			display: block;
		}

		.upload-input-group {
			display: flex;
			gap: 10px;
			margin-bottom: 10px;
			justify-content: right;
		}

		/* Custom File Input */
		.custom-file-input {
			position: relative;
			display: inline-block;
			flex: 1;
		}

		.custom-file-input input[type="file"] {
			position: absolute;
			left: -9999px;
			opacity: 0;
			width: 0.1px;
			height: 0.1px;
		}

		.custom-file-input label {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			font-weight: 500;
			cursor: pointer;
			transition: all 0.2s ease;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			height: 40px;
		}

		.custom-file-input label:hover {
			background: var(--bg-tertiary);
			border-color: var(--accent-primary);
		}

		.file-name {
			margin-left: 8px;
			font-weight: normal;
			color: var(--text-secondary);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		/* Upload Button */
		.upload-button {
			padding: 10px 20px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			border-radius: 6px;
			cursor: pointer;
			transition: all 0.2s ease;
			white-space: nowrap;
			min-width: 120px;
			font-weight: 600;
		}

		.upload-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-1px);
		}

		/* Upload Messages */
		.upload-message {
			margin: 20px 24px 0;
			padding: 12px 16px;
			border-radius: 6px;
			font-weight: 500;
		}

		.upload-message.success {
			background: rgba(46, 213, 115, 0.1);
			border: 1px solid var(--success-color);
			color: var(--success-color);
		}

		.upload-message.error {
			background: rgba(239, 68, 68, 0.1);
			border: 1px solid var(--danger-color);
			color: var(--danger-color);
		}

		/* Tooltip Styles */
		.gallery-caption.has-tooltip {
			cursor: help;
		}

		.gallery-caption.has-tooltip::after {
			content: attr(data-tooltip);
			visibility: hidden;
			opacity: 0;
			position: absolute;
			bottom: 125%;
			left: 50%;
			transform: translateX(-50%);
			background: var(--bg-tertiary);
			color: var(--text-primary);
			padding: 8px 12px;
			border-radius: 6px;
			font-size: 14px;
			white-space: nowrap;
			z-index: 1000;
			box-shadow: var(--shadow-md);
			border: 1px solid var(--border-color);
			transition: opacity 0.2s ease-in-out;
			pointer-events: none;
		}

		.gallery-caption.has-tooltip::before {
			content: '';
			visibility: hidden;
			opacity: 0;
			position: absolute;
			bottom: 125%;
			left: 50%;
			transform: translateX(-50%);
			border: 6px solid transparent;
			border-top-color: var(--bg-tertiary);
			z-index: 1000;
			transition: opacity 0.2s ease-in-out;
			pointer-events: none;
		}

		.gallery-caption.has-tooltip:hover::after,
		.gallery-caption.has-tooltip:hover::before {
			visibility: visible;
			opacity: 1;
		}

		.copy-notification {
			position: fixed;
			bottom: 25px;
			right: 25px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			padding: 12px 24px;
			border-radius: 8px;
			box-shadow: var(--shadow-md);
			display: none;
			z-index: 1000;
			font-weight: 600;
			opacity: 0;
			transform: translateY(20px);
			transition: opacity 0.3s ease, transform 0.3s ease;
		}

		.copy-notification.show {
			opacity: 1;
			transform: translateY(0);
		}

		/* No Results */
		.no-results {
			text-align: center;
			padding: 40px 20px;
			background: var(--bg-secondary);
			border-radius: 12px;
			margin: 20px 0;
			width: 100%;
			border: 1px solid var(--border-color);
		}

		.no-results h2 {
			color: var(--text-primary);
			font-size: 1.5rem;
			margin-bottom: 16px;
			font-weight: 600;
		}

		.no-results p {
			color: var(--text-secondary);
			margin-bottom: 12px;
		}

		.no-results a {
			color: var(--accent-primary);
			text-decoration: none;
			font-weight: 500;
		}

		.no-results a:hover {
			color: var(--accent-hover);
			text-decoration: underline;
		}

		/* Pagination */
		.pagination {
			text-align: center;
			margin: 40px 0 20px;
			display: flex;
			justify-content: center;
			flex-wrap: wrap;
			gap: 8px;
		}

		.pagination-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 10px 16px;
			background-color: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			text-decoration: none;
			font-weight: 500;
			min-width: 40px;
			transition: all 0.2s;
		}

		.pagination-link.current {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border-color: transparent;
			font-weight: 600;
		}

		.pagination-link:hover:not(.current):not(.disabled) {
			background-color: var(--bg-tertiary);
			border-color: var(--accent-primary);
			transform: translateY(-1px);
		}

		.pagination-link.disabled {
			color: var(--text-secondary);
			cursor: not-allowed;
			opacity: 0.5;
		}

		.pagination-ellipsis {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 8px;
			color: var(--text-secondary);
		}

		/* Responsive Styles */
		@media (max-width: 1024px) {
			.gallery {
				grid-template-columns: repeat(3, 1fr);
				gap: 20px;
			}
		}

		@media (max-width: 768px) {

			.filter-button {
			padding: 3px 10px;
			}

			.filter-buttons {
				font-size: .8rem;
				flex-wrap: nowrap;
				gap: 0;
			}
			body {
				padding: 15px;
			}
			
			.gallery {
				grid-template-columns: repeat(2, 1fr);
				gap: 15px;
			}
			
			h1 {
				font-size: 2rem;
			}
			
			.site-name svg {
				height: 50px;
			}
			
			.search-input, 
			.search-button {
				padding: 12px 16px;
			}

			.filter-buttons {
				flex-wrap: wrap;
				justify-content: center;
			}
			
			.modal-content {
				width: 95%;
				padding: 20px;
			}
			
			.image-overlay-actions {
				gap: 10px;
			}
			
			.overlay-action-button {
				width: 85%;
				font-size: 13px;
			}
		}

		@media (max-width: 480px) {

			.filter-button {
			padding: 3px 10px;
			}

			.filter-buttons {
				font-size: .76rem;
				flex-wrap: nowrap;
				gap: 0;
			}

			.gallery {
				grid-template-columns: repeat(2, 1fr);
			}
			
			.gallery-caption {
				font-size: .9rem;
			}
			
			h1 {
				font-size: 1.75rem;
			}
			
			.site-name svg {
				height: 50px;
			}

			.header-content {

				gap: 15px;
			}

			.auth-actions {

				justify-content: center;
			}
			
			.modal-content {
				width: 98%;
				padding: 16px;
			}
			
			.gallery-caption.has-tooltip::after {
				width: max-content;
				max-width: 200px;
				white-space: normal;
				text-align: center;
			}
			
			.pagination-link {
				padding: 8px 12px;
				min-width: 36px;
				font-size: 14px;
			}
		}
    </style>
</head>

<body>
    <div class="container">
    
	<!-- Login Modal -->
        <div id="loginModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Login</h3>
                    <button type="button" class="modal-close-btn">×</button>
                </div>
                <div class="modal-body">
                    <div class="login-error"></div>
                    <form class="login-form">
                        <input type="hidden" name="action" value="login">
                        <input type="text" name="username" placeholder="Username" required class="login-input">
                        <input type="password" name="password" placeholder="Password" required class="login-input">
                        <button type="submit" class="login-button">Login</button>
                    </form>
                </div>
            </div>
        </div>

		<!-- Upload Modal -->
		<div id="uploadModal" class="modal upload-modal">
			<div class="modal-content">
				<div class="modal-header">
				    <h3>Upload Poster</h3>
				    <button type="button" class="modal-close-btn">×</button>
				</div>
				
				<div class="upload-tabs">
				    <button class="upload-tab-btn active" data-tab="file">Upload from Disk</button>
				    <button class="upload-tab-btn" data-tab="url">Upload from URL</button>
				</div>
				
				<div class="upload-content">
				    <!-- Common error message div for both forms -->
				    <div class="upload-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;"></div>

				    <form id="fileUploadForm" class="upload-form active" method="POST" enctype="multipart/form-data">
				        <input type="hidden" name="action" value="upload">
				        <input type="hidden" name="upload_type" value="file">
				        <div class="upload-input-group">
				            <div class="custom-file-input">
				                <input type="file" name="image" id="fileInput" accept="<?php echo '.'.implode(',.', $config['allowedExtensions']); ?>">
				                <label for="fileInput">
				                    <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				                        <polyline points="17 8 12 3 7 8"></polyline>
				                        <line x1="12" y1="3" x2="12" y2="15"></line>
				                    </svg>
				                    Choose Poster
				                    <span class="file-name"></span>
				                </label>
				            </div>
				        </div>
				        <div class="directory-select" style="margin: 12px 0;">
				            <select name="directory" class="login-input">
				                <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
				                    <option value="<?php echo htmlspecialchars($dirKey); ?>">
				                        <?php echo formatDirectoryName($dirKey); ?>
				                    </option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				        <div class="upload-input-group">
				            <button type="submit" class="upload-button">Upload</button>
				        </div>
				        <div class="upload-help">
				            Maximum file size: <?php echo $config['maxFileSize'] / 1024 / 1024; ?>MB<br>
				            Allowed types: <?php echo implode(', ', $config['allowedExtensions']); ?>
				        </div>
				    </form>

				    <form id="urlUploadForm" class="upload-form" method="POST">
				        <input type="hidden" name="action" value="upload">
				        <input type="hidden" name="upload_type" value="url">
				        <div class="upload-input-group">
				            <input type="url" name="image_url" class="login-input" placeholder="Enter poster URL..." required>
				        </div>
				        <div class="directory-select" style="margin: 12px 0;">
				            <select name="directory" class="login-input">
				                <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
				                    <option value="<?php echo htmlspecialchars($dirKey); ?>">
				                        <?php echo formatDirectoryName($dirKey); ?>
				                    </option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				        <div class="upload-input-group">
				            <button type="submit" class="upload-button">Upload</button>
				        </div>
				        <div class="upload-help">
				            Maximum file size: <?php echo $config['maxFileSize'] / 1024 / 1024; ?>MB<br>
				            Allowed types: <?php echo implode(', ', $config['allowedExtensions']); ?>
				        </div>
				    </form>
				</div>
			</div>
		</div>
		
		<header>
			<div class="header-content">
				<a href="./" style="text-decoration: none;">
					<h1 class="site-name">
						<svg xmlns="http://www.w3.org/2000/svg" height="80" viewBox="0 0 200 200">
						  <!-- Main poster group -->
						  <g transform="translate(40, 35)">
							<!-- Back poster -->
							<rect x="0" y="0" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.4"/>
							
							<!-- Middle poster -->
							<rect x="20" y="15" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.7"/>
							
							<!-- Front poster -->
							<rect x="40" y="30" width="70" height="100" rx="6" fill="#E5A00D"/>
							
							<!-- Play button -->
							<circle cx="75" cy="80" r="25" fill="white"/>
							<path d="M65 65 L95 80 L65 95 Z" fill="#E5A00D"/>
						  </g>
						</svg>
						<?php echo htmlspecialchars($site_title); ?>
					</h1>
				</a>
				<?php if (isLoggedIn()): ?>
				    <div class="auth-actions">
				        <button id="showUploadModal" class="upload-trigger-button">
				            <svg class="upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				                <polyline points="17 8 12 3 7 8"></polyline>
				                <line x1="12" y1="3" x2="12" y2="15"></line>
				            </svg>
				            Upload
				        </button>
				        <a href="?action=logout" class="logout-button" title="Logout">
				            <svg class="logout-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
				                <polyline points="16 17 21 12 16 7"></polyline>
				                <line x1="21" y1="12" x2="9" y2="12"></line>
				            </svg>
				            
				        </a>
				    </div>
		        <?php else: ?>
		            <button id="showLoginModal" class="login-trigger-button">
		                <svg class="login-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
		                    <polyline points="10 17 15 12 10 7"></polyline>
		                    <line x1="15" y1="12" x2="3" y2="12"></line>
		                </svg>
		                Login
		            </button>
		        <?php endif; ?>
			</div>
		</header>
		    
		<div class="search-container">
		    <form class="search-form" method="GET" action="">
		        <?php if (!empty($currentDirectory)): ?>
		            <input type="hidden" name="directory" value="<?php echo htmlspecialchars($currentDirectory); ?>">
		        <?php endif; ?>
		        <input type="text" name="search" class="search-input" placeholder="Search posters..." value="<?php echo htmlspecialchars($searchQuery); ?>" autocomplete="off">
		        <button type="submit" class="search-button">Search</button>
		    </form>
		</div>

		<div class="filter-container">
		    <div class="filter-buttons">
		        <a href="?" class="filter-button <?php echo empty($currentDirectory) ? 'active' : ''; ?>">All</a>
		        <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
					<a href="?directory=<?php echo urlencode($dirKey); ?>" class="filter-button <?php echo $currentDirectory === $dirKey ? 'active' : ''; ?>">
						<?php echo formatDirectoryName($dirKey); ?>
					</a>
		        <?php endforeach; ?>
		    </div>
		</div>

		<div class="gallery-stats">
		    <?php if (!empty($searchQuery)): ?>
		        Showing <?php echo count($filteredImages); ?> of <?php echo count($allImages); ?> images
		        <a href="?<?php echo !empty($currentDirectory) ? 'directory=' . urlencode($currentDirectory) : ''; ?>">Clear search</a>
		    <?php else: ?>
		        Total images: <?php echo count($allImages); ?>
		    <?php endif; ?>
		</div>
		
			<?php if (empty($pageImages)): ?>
				<div class="no-results">
					<h2>No posters found</h2>
					<?php if (!empty($searchQuery)): ?>
						<p>No posters match your search query "<?php echo htmlspecialchars($searchQuery); ?>".</p>
					<?php else: ?>
						<p>No posters match your filter type.</p>
					<?php endif; ?>
					<p><a href="?">View all posters</a></p>
				</div>
			<?php else: ?>
				<div class="gallery">
				    <?php foreach ($pageImages as $image): ?>
				        <div class="gallery-item">
				            <div class="gallery-image-container">
				                <?php if (empty($currentDirectory)): ?>
									<div class="directory-badge">
										<?php echo formatDirectoryName($image['directory']); ?>
									</div>
				                <?php endif; ?>
				                <div class="gallery-image-placeholder">
				                    <div class="loading-spinner"></div>
				                </div>
				                <img 
				                    src="" 
				                    alt="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>"
				                    class="gallery-image"
				                    loading="lazy"
				                    data-src="<?php echo htmlspecialchars($image['fullpath']); ?>"
				                >
				                <div class="image-overlay-actions">
				                    <button class="overlay-action-button copy-url-btn" data-url="<?php echo htmlspecialchars($config['siteUrl'] . $image['fullpath']); ?>">
				                        <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
				                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
				                        </svg>
				                        Copy URL
				                    </button>
				                    <a href="?download=<?php echo urlencode($image['filename']); ?>&dir=<?php echo urlencode($image['directory']); ?>" class="overlay-action-button download-btn">
				                        <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				                            <polyline points="7 10 12 15 17 10"></polyline>
				                            <line x1="12" y1="15" x2="12" y2="3"></line>
				                        </svg>
				                        Download
				                    </a>
				                    <?php if (isLoggedIn()): ?>
										<button class="overlay-action-button move-btn" 
											data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
											data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
										<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
											<polyline points="10 17 15 12 10 7"></polyline>
											<line x1="15" y1="12" x2="3" y2="12"></line>
										</svg>
											Move
										</button>
				                        <button class="overlay-action-button rename-btn" 
				                             data-filename="<?php echo htmlspecialchars($image['filename']); ?>" 
				                             data-dirname="<?php echo htmlspecialchars($image['directory']); ?>"
				                             data-basename="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>">
				                            <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>
				                            </svg>
				                            Rename
				                        </button>
				                        <button class="overlay-action-button delete-btn" 
				                             data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
				                             data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
				                        	<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                                <polyline points="3 6 5 6 21 6"></polyline>
				                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
				                            </svg>
				                            Delete
				                        </button>
				                    <?php endif; ?>
				                </div>
				            </div>
							<div class="gallery-caption" data-full-text="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>">
								<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>
							</div>
				     	</div>
				    <?php endforeach; ?>
				</div>
				    
				<?php if (!empty($pageImages) && $totalPages > 1): ?>
				        <div class="pagination">
				            <?php echo generatePaginationLinks($page, $totalPages, $searchQuery, $currentDirectory); ?>
				        </div>
				<?php endif; ?>
		<?php endif; ?>
		    
				<div id="copyNotification" class="copy-notification">URL copied to clipboard!</div>
			</div>
		<!-- Delete Modal -->
		<div id="deleteModal" class="modal">
		    <div class="modal-content">
		        <div class="modal-header">
		            <h3>Confirm Deletion</h3>
		            <button type="button" class="modal-close-btn">×</button>
		        </div>
		        <p>Are you sure you want to delete this poster? This action cannot be undone.</p>
		        <form id="deleteForm" method="POST">
		            <input type="hidden" name="action" value="delete">
		            <input type="hidden" name="filename" id="deleteFilename">
		            <input type="hidden" name="directory" id="deleteDirectory">
		            <div class="modal-actions">
		                <button type="button" class="modal-button cancel" id="cancelDelete">Cancel</button>
		                <button type="submit" class="modal-button delete">Delete</button>
		            </div>
		        </form>
		    </div>
		</div>

		<!-- Rename Modal -->
		<div id="renameModal" class="modal">
			<div class="modal-content">
				<div class="modal-header">
				    <h3>Rename Poster</h3>
				    <button type="button" class="modal-close-btn">×</button>
				</div>
				<form id="renameForm" method="POST">
				    <input type="hidden" name="action" value="rename">
				    <input type="hidden" name="old_filename" id="oldFilename">
				    <input type="hidden" name="directory" id="renameDirectory">
				    <div class="rename-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;"></div>
				    <div style="margin-bottom: 20px;">
				        <input type="text" name="new_filename" id="newFilename" class="login-input" placeholder="Enter new filename" required>
				    </div>
				    <div class="modal-actions">
				        <button type="button" class="modal-button cancel" id="cancelRename">Cancel</button>
				        <button type="submit" class="modal-button rename">Rename</button>
				    </div>
				</form>
			</div>
		</div>
		
		<!-- Move Modal -->
		<div id="moveModal" class="modal">
		<div class="modal-content">
		    <div class="modal-header">
		        <h3>Move Poster</h3>
		        <button type="button" class="modal-close-btn">×</button>
		    </div>
		    <form id="moveForm" method="POST">
		        <input type="hidden" name="action" value="move">
		        <input type="hidden" name="filename" id="moveFilename">
		        <input type="hidden" name="source_directory" id="moveSourceDirectory">
		        <div style="margin-bottom: 20px;">
		            <label for="moveTargetDirectory" style="display: block; margin-bottom: 8px; color: var(--text-primary);">Select Target Directory:</label>
				<select name="target_directory" id="moveTargetDirectory" class="login-input">
					<?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
						<option value="<?php echo htmlspecialchars($dirKey); ?>">
							<?php echo formatDirectoryName($dirKey); ?>
						</option>
					<?php endforeach; ?>
				</select>
		        </div>
		        <div class="modal-actions">
		            <button type="button" class="modal-button cancel" id="cancelMove">Cancel</button>
		            <button type="submit" class="modal-button move">Move</button>
		        </div>
		    </form>
		</div>
	</div>
    <script>
		document.addEventListener('DOMContentLoaded', function() {
			// Check authentication status
			const isLoggedIn = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;
			
			// Modal elements
			const uploadModal = document.getElementById('uploadModal');
			const showUploadButton = document.getElementById('showUploadModal');
			const closeUploadButton = uploadModal?.querySelector('.modal-close-btn');
			const uploadTabs = document.querySelectorAll('.upload-tab-btn');
			const uploadForms = document.querySelectorAll('.upload-form');
			const deleteModal = document.getElementById('deleteModal');
			const renameModal = document.getElementById('renameModal');
			const moveModal = document.getElementById('moveModal');
			
			// Form elements
			const deleteForm = document.getElementById('deleteForm');
			const renameForm = document.getElementById('renameForm');
			const moveForm = document.getElementById('moveForm');
			
			// Input elements
			const deleteFilenameInput = document.getElementById('deleteFilename');
			const deleteDirectoryInput = document.getElementById('deleteDirectory');
			const oldFilenameInput = document.getElementById('oldFilename');
			const renameDirectoryInput = document.getElementById('renameDirectory');
			const newFilenameInput = document.getElementById('newFilename');
			const moveFilenameInput = document.getElementById('moveFilename');
			const moveSourceDirectoryInput = document.getElementById('moveSourceDirectory');
			const moveTargetDirectorySelect = document.getElementById('moveTargetDirectory');
			
			// Notification elements
			const copyNotification = document.getElementById('copyNotification');

			// Login Modal elements
			const loginModal = document.getElementById('loginModal');
			const loginForm = document.querySelector('.login-form');
			const loginError = document.querySelector('.login-error');
			const showLoginButton = document.getElementById('showLoginModal');
			const closeLoginButton = loginModal?.querySelector('.modal-close-btn');

			// File input handling
			const fileInput = document.getElementById('fileInput');
			if (fileInput) {
				fileInput.addEventListener('change', function(e) {
					const fileName = e.target.files[0]?.name || 'No file chosen';
					const fileNameElement = this.parentElement.querySelector('.file-name');
					if (fileNameElement) {
						fileNameElement.textContent = fileName;
					}
				});
			}

			// Modal Functions
			function showModal(modal) {
				if (modal) {
					modal.style.display = 'block';
					modal.offsetHeight; // Force reflow
					setTimeout(() => {
						modal.classList.add('show');
					}, 10);
				}
			}

			function hideModal(modal, form = null) {
				if (modal) {
					modal.classList.remove('show');
					setTimeout(() => {
						modal.style.display = 'none';
						if (form) form.reset();
					}, 300);
				}
			}

			// Login Modal Functions
			if (showLoginButton && loginModal) {
				function showLoginModal() {
					showModal(loginModal);
					loginError.style.display = 'none';
				}

				function hideLoginModal() {
					hideModal(loginModal, loginForm);
					loginError.style.display = 'none';
				}

				showLoginButton.addEventListener('click', showLoginModal);
				closeLoginButton?.addEventListener('click', hideLoginModal);
				
				loginModal.addEventListener('click', (e) => {
					if (e.target === loginModal) hideLoginModal();
				});
			}

			// Upload Modal Functions
			if (showUploadButton && uploadModal) {
				function showUploadModal() {
					showModal(uploadModal);
				}

				function hideUploadModal() {
					hideModal(uploadModal);
				}

				showUploadButton.addEventListener('click', showUploadModal);
				closeUploadButton?.addEventListener('click', hideUploadModal);
				
				uploadModal.addEventListener('click', (e) => {
					if (e.target === uploadModal) hideUploadModal();
				});

				// Upload tabs functionality
				uploadTabs.forEach(button => {
					button.addEventListener('click', () => {
						const tabName = button.getAttribute('data-tab');
						
						// Update active tab button
						uploadTabs.forEach(btn => btn.classList.remove('active'));
						button.classList.add('active');
						
						// Show active form
						uploadForms.forEach(form => {
						    if (form.id === tabName + 'UploadForm') {
						        form.classList.add('active');
						    } else {
						        form.classList.remove('active');
						    }
						});
					});
				});
			}

			// Login Form Handler
			if (loginForm) {
				loginForm.addEventListener('submit', async function(e) {
					e.preventDefault();
					const formData = new FormData(loginForm);
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData,
						    headers: {
						        'X-Requested-With': 'XMLHttpRequest'
						    }
						});
						
						const data = await response.json();
						
						if (data.success) {
						    window.location.reload();
						} else {
						    loginError.textContent = data.error;
						    loginError.style.display = 'block';
						}
					} catch (error) {
						console.error('Login error:', error);
						loginError.textContent = 'An error occurred during login';
						loginError.style.display = 'block';
					}
				});
			}
			
			// Form Handlers
			if (deleteForm) {
				deleteForm.addEventListener('submit', async function(e) {
					e.preventDefault();
					const formData = new FormData(deleteForm);
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData
						});
						
						const data = await response.json();
						
						if (data.success) {
						    hideModal(deleteModal);
						    window.location.reload();
						} else {
						    alert(data.error || 'Failed to delete file');
						}
					} catch (error) {
						console.error('Delete error:', error);
						alert('An error occurred while deleting the file');
					}
				});
			}

			if (renameForm) {
				const renameError = document.querySelector('.rename-error');
				
				function showRenameError(message) {
					renameError.textContent = message;
					renameError.style.display = 'block';
				}
				
				function hideRenameError() {
					renameError.style.display = 'none';
					renameError.textContent = '';
				}
				
				function validateFilename(filename) {
					// Check for periods in the filename (excluding extension)
					const periodCount = filename.split('.').length - 1;
					if (periodCount > 0) {
						showRenameError('Filename cannot contain periods');
						return false;
					}
					
					// Check for slashes and backslashes in the filename
					if (filename.includes('/') || filename.includes('\\')) {
						showRenameError('Filename cannot contain slashes');
						return false;
					}
					return true;
				}
				
				renameForm.addEventListener('submit', async function(e) {
					e.preventDefault();
					hideRenameError();
					
					const newFilename = document.getElementById('newFilename').value;
					if (!validateFilename(newFilename)) {
						return;
					}
					
					const formData = new FormData(renameForm);
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData
						});
						
						const data = await response.json();
						
						if (data.success) {
						    hideModal(renameModal);
						    window.location.reload();
						} else {
						    showRenameError(data.error || 'Failed to rename file');
						}
					} catch (error) {
						console.error('Rename error:', error);
						showRenameError('An error occurred while renaming the file');
					}
				});

				// When closing the modal, clear any error messages
				document.getElementById('cancelRename')?.addEventListener('click', () => {
					hideRenameError();
					hideModal(renameModal, renameForm);
				});
				
				renameModal?.querySelector('.modal-close-btn')?.addEventListener('click', () => {
					hideRenameError();
					hideModal(renameModal, renameForm);
				});
				
				// Real-time validation as user types
				document.getElementById('newFilename').addEventListener('input', function(e) {
					const filename = e.target.value;
					validateFilename(filename);
				});
			}

			if (moveForm) {
				moveForm.addEventListener('submit', async function(e) {
					e.preventDefault();
					const formData = new FormData(moveForm);
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData
						});
						
						const data = await response.json();
						
						if (data.success) {
						    hideModal(moveModal);
						    window.location.reload();
						} else {
						    alert(data.error || 'Failed to move file');
						}
					} catch (error) {
						console.error('Move error:', error);
						alert('An error occurred while moving the file');
					}
				});
			}

			// Event Handler Functions
			function deleteHandler(e) {
				e.preventDefault();
				const filename = this.getAttribute('data-filename');
				const dirname = this.getAttribute('data-dirname');
				deleteFilenameInput.value = filename;
				deleteDirectoryInput.value = dirname;
				showModal(deleteModal);
			}

			function renameHandler(e) {
				e.preventDefault();
				const filename = this.getAttribute('data-filename');
				const dirname = this.getAttribute('data-dirname');
				const basename = this.getAttribute('data-basename');
				oldFilenameInput.value = filename;
				renameDirectoryInput.value = dirname;
				newFilenameInput.value = basename;
				newFilenameInput.select();
				showModal(renameModal);
			}

			function moveHandler(e) {
				e.preventDefault();
				const filename = this.getAttribute('data-filename');
				const dirname = this.getAttribute('data-dirname');
				moveFilenameInput.value = filename;
				moveSourceDirectoryInput.value = dirname;
				
				// Remove the current directory from target options
				Array.from(moveTargetDirectorySelect.options).forEach(option => {
					option.disabled = option.value === dirname;
				});
				
				// Select the first enabled option
				const firstEnabledOption = Array.from(moveTargetDirectorySelect.options).find(option => !option.disabled);
				if (firstEnabledOption) {
					firstEnabledOption.selected = true;
				}
				
				showModal(moveModal);
			}

			function copyUrlHandler() {
				// Get the URL and encode spaces
				const url = this.getAttribute('data-url');
				const encodedUrl = url.replace(/ /g, '%20');
				
				try {
					navigator.clipboard.writeText(encodedUrl).then(() => {
						showNotification();
					});
				} catch (err) {
					console.error('Failed to copy: ', err);
					
					// Fallback for browsers that don't support clipboard API
					const textarea = document.createElement('textarea');
					textarea.value = encodedUrl;
					textarea.style.position = 'fixed';
					document.body.appendChild(textarea);
					textarea.select();
					
					try {
						document.execCommand('copy');
						showNotification();
					} catch (e) {
						console.error('Fallback copy failed:', e);
						alert('Copy failed. Please select and copy the URL manually.');
					}
					
					document.body.removeChild(textarea);
				}
			}

			// Update file upload form handler
			if (document.getElementById('fileUploadForm')) {
				document.getElementById('fileUploadForm').addEventListener('submit', async function(e) {
					e.preventDefault();
					hideUploadError();
					
					const formData = new FormData(this);
					const fileInput = this.querySelector('input[type="file"]');
					
					// Validate file type
					if (fileInput.files.length > 0) {
						const file = fileInput.files[0];
						const ext = file.name.split('.').pop().toLowerCase();
						const allowedExtensions = <?php echo json_encode($config['allowedExtensions']); ?>;
						
						if (!allowedExtensions.includes(ext)) {
						    showUploadError('Invalid file type. Allowed types: ' + allowedExtensions.join(', '));
						    return;
						}
					}
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData
						});
						
						const data = await response.json();
						
						if (data.success) {
						    // Only hide modal and reload on success
						    hideModal(uploadModal);
						    window.location.reload();
						} else {
						    // Keep modal open and show error
						    showUploadError(data.error || 'Upload failed');
						    
						    // Reset file input on failure while keeping modal open
						    fileInput.value = '';
						    const fileNameElement = fileInput.parentElement.querySelector('.file-name');
						    if (fileNameElement) {
						        fileNameElement.textContent = '';
						    }
						}
					} catch (error) {
						console.error('Upload error:', error);
						showUploadError('An error occurred during upload');
						
						// Reset file input on error while keeping modal open
						fileInput.value = '';
						const fileNameElement = fileInput.parentElement.querySelector('.file-name');
						if (fileNameElement) {
						    fileNameElement.textContent = '';
						}
					}
				});
			}

			// Update URL upload form handler
			if (document.getElementById('urlUploadForm')) {
				document.getElementById('urlUploadForm').addEventListener('submit', async function(e) {
					e.preventDefault();
					hideUploadError();
					
					const formData = new FormData(this);
					const imageUrl = formData.get('image_url');
					const urlInput = this.querySelector('input[name="image_url"]');
					
					// Basic URL validation
					try {
						const url = new URL(imageUrl);
						const ext = url.pathname.split('.').pop().toLowerCase();
						const allowedExtensions = <?php echo json_encode($config['allowedExtensions']); ?>;
						
						if (!allowedExtensions.includes(ext)) {
						    showUploadError('Invalid file type. Allowed types: ' + allowedExtensions.join(', '));
						    return;
						}
					} catch (error) {
						showUploadError('Invalid URL format');
						return;
					}
					
					try {
						const response = await fetch(window.location.href, {
						    method: 'POST',
						    body: formData
						});
						
						const data = await response.json();
						
						if (data.success) {
						    // Only hide modal and reload on success
						    hideModal(uploadModal);
						    window.location.reload();
						} else {
						    // Keep modal open and show error
						    showUploadError(data.error || 'Upload failed');
						    
						    // Clear URL input on failure while keeping modal open
						    urlInput.value = '';
						}
					} catch (error) {
						console.error('Upload error:', error);
						showUploadError('An error occurred during upload');
						
						// Clear URL input on error while keeping modal open
						urlInput.value = '';
					}
				});
			}
			
			function initializeTruncation() {
				const captions = document.querySelectorAll('.gallery-caption');
				
				captions.forEach(caption => {
					const text = caption.textContent.trim();
					caption.textContent = text;
					
					const containerWidth = caption.clientWidth;
					
					const measurer = document.createElement('div');
					measurer.style.visibility = 'hidden';
					measurer.style.position = 'absolute';
					measurer.style.whiteSpace = 'nowrap';
					measurer.style.fontSize = window.getComputedStyle(caption).fontSize;
					measurer.style.fontFamily = window.getComputedStyle(caption).fontFamily;
					measurer.style.fontWeight = window.getComputedStyle(caption).fontWeight;
					measurer.style.padding = window.getComputedStyle(caption).padding;
					measurer.textContent = text;
					document.body.appendChild(measurer);

					const textWidth = measurer.offsetWidth;
					document.body.removeChild(measurer);

					if (textWidth > containerWidth * 0.9) {
						let truncated = text;
						let currentWidth = textWidth;
						
						let start = 0;
						let end = text.length;
						
						while (start < end) {
							const mid = Math.floor((start + end + 1) / 2);
							const testText = text.slice(0, mid) + '...';
							measurer.textContent = testText;
							document.body.appendChild(measurer);
							currentWidth = measurer.offsetWidth;
							document.body.removeChild(measurer);
							
							if (currentWidth <= containerWidth * 0.9) {
								start = mid;
							} else {
								end = mid - 1;
							}
						}
						
						truncated = text.slice(0, start) + '...';
						caption.textContent = truncated;
						caption.title = text; // Use native title for tooltip
						caption.style.cursor = 'help';
					}
				});
			}

			// Add this to both your DOMContentLoaded and after image loading
			window.addEventListener('DOMContentLoaded', () => {
				initializeTruncation();
			});

			// Also call it after each image loads
			document.querySelectorAll('.gallery-image').forEach(img => {
				img.addEventListener('load', () => {
					initializeTruncation();
				});
			});

			// Debounced resize handler
			window.addEventListener('resize', debounce(initializeTruncation, 250));

			// Initialize all button handlers
			function initializeButtons() {
				// Copy buttons
				document.querySelectorAll('.copy-url-btn').forEach(button => {
					button.removeEventListener('click', copyUrlHandler);
					button.addEventListener('click', copyUrlHandler);
				});

				// Delete buttons
				document.querySelectorAll('.delete-btn').forEach(button => {
					button.removeEventListener('click', deleteHandler);
					button.addEventListener('click', deleteHandler);
				});

				// Rename buttons
				document.querySelectorAll('.rename-btn').forEach(button => {
					button.removeEventListener('click', renameHandler);
					button.addEventListener('click', renameHandler);
				});

				// Move buttons
				document.querySelectorAll('.move-btn').forEach(button => {
					button.removeEventListener('click', moveHandler);
					button.addEventListener('click', moveHandler);
				});

				// Add move button to overlay actions if it doesn't exist
				document.querySelectorAll('.image-overlay-actions').forEach(actions => {
					if (!actions.querySelector('.move-btn')) {
						const moveBtn = document.createElement('button');
						moveBtn.className = 'overlay-action-button move-btn';
						moveBtn.setAttribute('data-filename', actions.querySelector('.delete-btn').getAttribute('data-filename'));
						moveBtn.setAttribute('data-dirname', actions.querySelector('.delete-btn').getAttribute('data-dirname'));
						moveBtn.innerHTML = `
							<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							    <path d="M5 9l7-7 7 7"/>
							    <path d="M19 15v4a2 2 0 01-2 2H7a2 2 0 01-2-2v-4"/>
							    <line x1="12" y1="3" x2="12" y2="15"/>
							</svg>
							Move
						`;
						actions.insertBefore(moveBtn, actions.querySelector('.rename-btn'));
					}
				});
			}

			// Notification function
			function showNotification() {
				copyNotification.style.display = 'block';
				copyNotification.classList.add('show');
				
				setTimeout(() => {
					copyNotification.classList.remove('show');
					setTimeout(() => {
						copyNotification.style.display = 'none';
					}, 300);
				}, 2000);
			}

			// Search functionality
			function debounce(func, wait) {
				let timeout;
				return function() {
					const context = this, args = arguments;
					clearTimeout(timeout);
					timeout = setTimeout(function() {
						func.apply(context, args);
					}, wait);
				};
			}

			// Get the search form and input
			const searchForm = document.querySelector('.search-form');
			const searchInput = document.querySelector('.search-input');

			// Set autocomplete off to prevent browser behavior
			if (searchInput) {
				searchInput.setAttribute('autocomplete', 'off');
			}

			// Handle form submission (search button click or Enter key)
			if (searchForm) {
				searchForm.addEventListener('submit', function(e) {
					e.preventDefault();
					
					const searchValue = searchForm.querySelector('.search-input').value;
					const currentUrl = new URL(window.location.href);
					
					if (searchValue) {
						currentUrl.searchParams.set('search', searchValue);
					} else {
						currentUrl.searchParams.delete('search');
					}
					
					// Maintain directory filter if exists
					const currentDirectory = currentUrl.searchParams.get('directory');
					if (currentDirectory) {
						currentUrl.searchParams.set('directory', currentDirectory);
					}
					
					// Update URL
					window.history.pushState({}, '', currentUrl.toString());
					
					// Perform search
					fetch(currentUrl.toString())
						.then(response => response.text())
						.then(html => {
							const parser = new DOMParser();
							const newDoc = parser.parseFromString(html, 'text/html');
							
							// Update stats
							document.querySelector('.gallery-stats').innerHTML = 
								newDoc.querySelector('.gallery-stats').innerHTML;
							
							// Update pagination
							const paginationContainer = document.querySelector('.pagination');
							const newPagination = newDoc.querySelector('.pagination');
							
							if (paginationContainer) {
								if (newPagination) {
									paginationContainer.style.display = 'flex';
									paginationContainer.innerHTML = newPagination.innerHTML;
								} else {
									paginationContainer.style.display = 'none';
								}
							}
							
							// Get the gallery container
							const galleryContainer = document.querySelector('.gallery');
							
							// Check if there are results
							const newGallery = newDoc.querySelector('.gallery');
							const noResults = newDoc.querySelector('.no-results');
							
							if (newGallery) {
								// Show gallery with results
								if (galleryContainer) {
									galleryContainer.style.display = 'grid';
									galleryContainer.innerHTML = newGallery.innerHTML;
								}
								// Remove any existing no-results message
								const existingNoResults = document.querySelector('.no-results');
								if (existingNoResults) {
									existingNoResults.remove();
								}
							} else if (noResults) {
								// Hide gallery
								if (galleryContainer) {
									galleryContainer.style.display = 'none';
								}
								// Remove any existing no-results message
								const existingNoResults = document.querySelector('.no-results');
								if (existingNoResults) {
									existingNoResults.remove();
								}
								// Insert new no-results message after gallery stats
								const galleryStats = document.querySelector('.gallery-stats');
								galleryStats.insertAdjacentHTML('afterend', noResults.outerHTML);
							}
							
							// Blur the search input to hide keyboard on mobile
							if (searchInput) {
								searchInput.blur();
							}
							
							// Reinitialize observers and buttons
							initializeGalleryFeatures();
							initializeButtons();
						});
				});
			}

			// Handle browser back/forward
			window.addEventListener('popstate', function() {
				location.reload();
			});
			
			const uploadError = document.querySelector('.upload-error');
			
			function showUploadError(message) {
				if (uploadError) {
					uploadError.textContent = message;
					uploadError.style.display = 'block';
				}
			}
			
			function hideUploadError() {
				if (uploadError) {
					uploadError.style.display = 'none';
					uploadError.textContent = '';
				}
			}
			
			let isScrolling = false;
			let scrollTimeout;
			let overlayActivationTime = 0;
			const OVERLAY_ACTIVATION_DELAY = 100; // Time in ms to wait before allowing button interactions

			// Function to handle scroll events
			function handleScroll() {
				isScrolling = true;
				
				// Hide all overlay actions while scrolling
				document.querySelectorAll('.gallery-item').forEach(item => {
					item.classList.remove('touch-active');
				});
				
				// Clear the existing timeout
				clearTimeout(scrollTimeout);
				
				// Set a new timeout to mark scrolling as finished
				scrollTimeout = setTimeout(() => {
					isScrolling = false;
				}, 100);
			}

			// Lazy loading with Intersection Observer
			const observerOptions = {
				root: null,
				rootMargin: '50px',
				threshold: 0.1
			};
			
			const handleIntersection = (entries, observer) => {
				entries.forEach(entry => {
					if (entry.isIntersecting) {
						const img = entry.target;
						const placeholder = img.previousElementSibling;
						
						if (!img.classList.contains('loaded')) {
							img.src = img.dataset.src;
							
							img.onload = () => {
							    img.classList.add('loaded');
							    placeholder.classList.add('hidden');
							};
							
							img.onerror = () => {
							    placeholder.innerHTML = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
							};
						}
						
						observer.unobserve(img);
					}
				});
			};
			
			const observer = new IntersectionObserver(handleIntersection, observerOptions);
			
			// Initialize gallery features
			function initializeGalleryFeatures() {
				// Initialize lazy loading
				document.querySelectorAll('.gallery-image').forEach(img => {
					observer.observe(img);
				});

				// Add intercepting event listener for buttons
				document.querySelectorAll('.overlay-action-button').forEach(button => {
					button.addEventListener('touchstart', function(e) {
						// If overlay was just activated, prevent button interaction
						if (Date.now() - overlayActivationTime < OVERLAY_ACTIVATION_DELAY) {
							e.preventDefault();
							e.stopPropagation();
						}
					}, { capture: true }); // Use capture phase to intercept before regular handlers
				});

				// Handle touch interactions for gallery items
				document.querySelectorAll('.gallery-item').forEach(item => {
					let touchStartY = 0;
					let touchStartX = 0;
					let touchTimeStart = 0;

					item.addEventListener('touchstart', function(e) {
						if (isScrolling) return;
						
						if (!this.classList.contains('touch-active')) {
							touchStartY = e.touches[0].clientY;
							touchStartX = e.touches[0].clientX;
							touchTimeStart = Date.now();
						}
					}, { passive: true });

					item.addEventListener('touchend', function(e) {
						if (isScrolling) return;
						
						// Handle initial tap to show overlay
						if (!this.classList.contains('touch-active')) {
							const touchEndY = e.changedTouches[0].clientY;
							const touchEndX = e.changedTouches[0].clientX;
							const touchTime = Date.now() - touchTimeStart;
							
							const dy = Math.abs(touchEndY - touchStartY);
							const dx = Math.abs(touchEndX - touchStartX);
							
							if (touchTime < 300 && dy < 10 && dx < 10) {
								e.preventDefault();
								e.stopPropagation();
								
								// Remove active class from all other items
								document.querySelectorAll('.gallery-item').forEach(otherItem => {
								    if (otherItem !== this) {
								        otherItem.classList.remove('touch-active');
								    }
								});
								
								// Show this overlay and record the time
								this.classList.add('touch-active');
								overlayActivationTime = Date.now();
							}
						}
					});
				});

				// Close active overlay when touching outside
				document.addEventListener('touchstart', function(e) {
					if (!isScrolling && 
						!e.target.closest('.gallery-item') && 
						!e.target.closest('.overlay-action-button')) {
						document.querySelectorAll('.gallery-item').forEach(item => {
							item.classList.remove('touch-active');
						});
					}
				}, { passive: true });
			}

			// Add scroll event listener
			window.addEventListener('scroll', handleScroll, { passive: true });

			// Clear error when switching tabs
			document.querySelectorAll('.upload-tab-btn').forEach(button => {
				button.addEventListener('click', () => {
					hideUploadError();
				});
			});

			// Clear error when closing modal
			document.querySelector('#uploadModal .modal-close-btn')?.addEventListener('click', () => {
				hideUploadError();
			});

			// Handle escape key for modals
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') {
					if (uploadModal?.classList.contains('show')) hideUploadModal();
					if (deleteModal?.classList.contains('show')) hideModal(deleteModal, deleteForm);
					if (renameModal?.classList.contains('show')) hideModal(renameModal, renameForm);
					if (moveModal?.classList.contains('show')) hideModal(moveModal, moveForm);
					if (loginModal?.classList.contains('show')) hideLoginModal();
				}
			});

		// Fix for delete modal buttons
		if (deleteModal) {
			// Fix close button
			const deleteCloseBtn = deleteModal.querySelector('.modal-close-btn');
			if (deleteCloseBtn) {
				deleteCloseBtn.addEventListener('click', () => {
				    hideModal(deleteModal, deleteForm);
				});
			}

			// Fix cancel button
			const cancelDeleteBtn = document.getElementById('cancelDelete');
			if (cancelDeleteBtn) {
				cancelDeleteBtn.addEventListener('click', () => {
				    hideModal(deleteModal, deleteForm);
				});
			}

			// Close when clicking outside the modal
			deleteModal.addEventListener('click', (e) => {
				if (e.target === deleteModal) {
				    hideModal(deleteModal, deleteForm);
				}
			});
		}

		// Fix for move modal buttons
		if (moveModal) {
			// Fix close button
			const moveCloseBtn = moveModal.querySelector('.modal-close-btn');
			if (moveCloseBtn) {
				moveCloseBtn.addEventListener('click', () => {
				    hideModal(moveModal, moveForm);
				});
			}

			// Fix cancel button
			const cancelMoveBtn = document.getElementById('cancelMove');
			if (cancelMoveBtn) {
				cancelMoveBtn.addEventListener('click', () => {
				    hideModal(moveModal, moveForm);
				});
			}

			// Close when clicking outside the modal
			moveModal.addEventListener('click', (e) => {
				if (e.target === moveModal) {
				    hideModal(moveModal, moveForm);
				}
			});
		}
			
			// Initial setup
			initializeGalleryFeatures();
			initializeButtons();
		});
	</script>
</body>
</html>
