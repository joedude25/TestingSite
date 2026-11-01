<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'auth_check.php'; // ensures $_SESSION['user_id'] exists



require_once 'db_functions.php';
if (file_exists('debug_helpers.php') && isset($_GET['debug'])) {
    require_once 'debug_helpers.php';
}




// --- Decode POST data ---
$data = json_decode(file_get_contents('php://input'), true);

// --- Validate test type from session ---
$userId = $_SESSION['user_id'] ?? null;
$testType = $_SESSION['test_type'] ?? null;

if (!$userId || !$testType) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing session data. Please log in and select a test first.'
    ]);
    exit;
}

if (!validateUserTest($userId, $testType)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or unauthorized test type.'
    ]);
    exit;
}

file_put_contents('debug_log.txt', print_r($data, true) . "\n\n", FILE_APPEND);


if (!$data || !isset($data['question_id'], $data['answer_id'])) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$questionId = intval($data['question_id']);
$answerId   = intval($data['answer_id']);
$answerIds  = isset($data['answer_ids']) ? array_map('intval', $data['answer_ids']) : [$answerId];

// --- Initialize safe defaults ---
$qType = $data['type'] ?? 'static';   // assume 'static' if not provided
$isCorrect = $data['correct'] ?? null;


// --- Determine if question is new ---
$isNew = isQuestionNew($questionId, $userId, $testType);

// --- Fetch explanation ---
global $conn;
$stmt = $conn->prepare("SELECT explanation_text FROM explanations WHERE question_id = ?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$result = $stmt->get_result();
$explanationRow = $result->fetch_assoc();
$stmt->close();
$explanationText = $explanationRow['explanation_text'] ?? '';

// --- Record attempt ---
$success = recordAnswerAttempt($userId, $questionId, $answerIds, $answerId, $isCorrect, $testType);

// --- Return JSON response ---
$response = [
    'success' => $success,
    'correct' => $isCorrect,
    'is_new' => $isNew,
    'explanation' => $explanationText
];

// If there was a DB error, include debug info
if (!$success) {
    $response['error'] = 'Database error, check server logs for details.';
}

echo json_encode($response);




