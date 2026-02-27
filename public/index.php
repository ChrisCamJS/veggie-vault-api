<?php
// public/index.php
header("Access-Control-Allow-Origin: *"); // Or load from env
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

// Load Config & Router
require_once __DIR__ . '/../src/Database.php';
$router = require_once __DIR__ . '/../config/routes.php';

// Dispatch
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Dynamically strip the base subdirectory path from the URI
$scriptName = dirname($_SERVER['SCRIPT_NAME']); 
if (strpos($uri, $scriptName) === 0) {
    $uri = substr($uri, strlen($scriptName));
}

// Ensure the resulting URI always starts with a forward slash
if (empty($uri) || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

$router->dispatch($uri, $_SERVER['REQUEST_METHOD']);