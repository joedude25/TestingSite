<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once 'auth_check.php';
require_once 'db_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$questionId = filter_var($payload['question_id'] ?? null, FILTER_VALIDATE_INT);
$userId = (int)($_SESSION['user_id'] ?? 0);
$testType = (string)($_SESSION['test_type'] ?? '');

if (!$questionId || $questionId < 1 || $testType === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A selected test and valid question are required.']);
    exit;
}

if (!validateUserTest($userId, $testType)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This test is not assigned to your account.']);
    exit;
}

$lookup = $conn->prepare('SELECT test_type FROM questions WHERE id = ?');
if (!$lookup) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not look up the question.']);
    exit;
}
$lookup->bind_param('i', $questionId);
$lookup->execute();
$question = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$question || $question['test_type'] !== $testType) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Question not found in the current test.']);
    exit;
}

try {
    $conn->begin_transaction();

    // Related tables have no cascading foreign keys, so clear each reference explicitly.
    foreach (['question_answer_stats', 'question_attempts', 'answers', 'explanations'] as $table) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE question_id = ?");
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param('i', $questionId);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $stmt->close();
    }

    $stmt = $conn->prepare('DELETE FROM questions WHERE id = ? AND test_type = ?');
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param('is', $questionId, $testType);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new Exception($stmt->error ?: 'Question could not be deleted.');
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'question_id' => $questionId]);
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Quiz question deletion failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Deletion failed; no records were removed.']);
}
