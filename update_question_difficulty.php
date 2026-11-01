<?php
// Show all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['question_id']) || !isset($input['difficulty'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing question_id or difficulty']);
    exit;
}

$questionId = (int)$input['question_id'];
$newDifficulty = (int)$input['difficulty'];

// Call the function from db_functions.php
try {
    $success = updateQuestionDifficulty($questionId, $newDifficulty);
    if ($success) {
        echo json_encode(['status' => 'ok', 'question_id' => $questionId, 'difficulty' => $newDifficulty]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update difficulty']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
