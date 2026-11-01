<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// For now, manually set user_id to 0
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 0;}

require_once 'db_functions.php';

// ------------------------------------------------------------
// Log an action for debugging
// ------------------------------------------------------------
function debugLog($action, $data = []) {
    $msg = "[" . date('Y-m-d H:i:s') . "] $action: " . json_encode($data);
    error_log($msg);
}

// ------------------------------------------------------------
// Fetch recently answered questions
// ------------------------------------------------------------
function getRecentQuestions($userId, $limit = 10) {
    $recent = getRecentlyAnsweredQuestions($userId, $limit);
    debugLog("getRecentQuestions", ['user_id' => $userId, 'count' => count($recent)]);
    return $recent;
}

// ------------------------------------------------------------
// Endpoint dispatcher
// ------------------------------------------------------------
$action = $_GET['action'] ?? null;
$response = [];

switch ($action) {

    case 'recent_questions':
        $response = getRecentQuestions($userId);
        break;

    case 'log':
        // Optional: manual logging from client-side
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) debugLog($data['action'] ?? 'manual_log', $data['data'] ?? []);
        $response = ['status' => 'logged'];
        break;

    default:
        $response = ['error' => 'Invalid debug action'];
        break;
}

echo json_encode($response);
