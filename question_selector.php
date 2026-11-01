<?php
header('Content-Type: application/json');
session_start();

require_once 'auth_check.php'; // ensures $_SESSION['user_id'] exists
require_once 'db_functions.php';
require_once 'debug_helpers.php';

// -----------------------------------
// Validate test type from session
// -----------------------------------

$userId = $_SESSION['user_id'] ?? null;
$testType = $_SESSION['test_type'] ?? null;

if (!$userId || !$testType) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing session data. Please log in and select a test.'
    ]);
    exit;
}

if (!validateUserTest($userId, $testType)) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Invalid or unauthorized test type.'
    ]);
    exit;
}



// Read incoming POST payload
$payload = json_decode(file_get_contents('php://input'), true);
$answerId = $payload['answer_id'] ?? null;
$currentQuestionId = $payload['question_id'] ?? null;

// If answer submitted, record it using the multi-answer signature
if ($currentQuestionId && $answerId) {
    $currentQuestionId = intval($currentQuestionId);
    $answerId = intval($answerId);

    // 1) Get the list of answer IDs that were shown for this question (we'll use the same selection logic as for display)
    //    We'll query the answers table directly and pick one correct + up to 3 incorrect (randomized).
    $stmtA = $conn->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
    $stmtA->bind_param("i", $currentQuestionId);
    $stmtA->execute();
    $resA = $stmtA->get_result();
    $allAnswers = [];
    while ($r = $resA->fetch_assoc()) {
        $allAnswers[] = $r;
    }
    $stmtA->close();

    // Build the array of shown answer IDs. We'll follow same logic: 1 correct (if exists) + up to 3 incorrect, random.
    $correct = array_values(array_filter($allAnswers, fn($a) => (int)$a['is_correct'] === 1));
    $incorrect = array_values(array_filter($allAnswers, fn($a) => (int)$a['is_correct'] === 0));

    // Pick a single correct answer (if more than one exists, take first; ideally DB has only 1 correct)
    $selectedCorrect = $correct ? [ $correct[0] ] : [];

    // Randomize incorrect and pick up to 3
    if (count($incorrect) > 1) shuffle($incorrect);
    $incorrect = array_slice($incorrect, 0, 3);

    $shownAnswers = array_merge($selectedCorrect, $incorrect);

    // Normalize to IDs
    $answerIds = array_map(fn($a) => (int)$a['id'], $shownAnswers);

    // 2) Determine correctness of the submitted answer_id.
    //    We'll query the answers table for that specific answer_id to avoid relying on client data.
    $stmtC = $conn->prepare("SELECT is_correct FROM answers WHERE id = ? LIMIT 1");
    $stmtC->bind_param("i", $answerId);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    $rowC = $resC->fetch_assoc();
    $stmtC->close();

    $isCorrect = !empty($rowC) && ((int)$rowC['is_correct'] === 1);

    // 3) Call the multi-answer record function (userId, questionId, answerIds, selectedAnswerId, isCorrect)
    recordAnswerAttempt($userId, $currentQuestionId, $answerIds, $answerId, $isCorrect, $testType);

    debugLog("Answer recorded (selector)", [
        'user_id' => $userId,
        'question_id' => $currentQuestionId,
        'shown_answer_ids' => $answerIds,
        'selected_answer_id' => $answerId,
        'is_correct' => $isCorrect
    ]);
}

// Select next due question using spaced repetition
$dueQuestions = getDueQuestions($userId, $testType);
if (empty($dueQuestions)) {
    echo json_encode(['done' => true]);
    exit;
}

// Pick the first due question
$nextQuestionId = $dueQuestions[0];
$question = getQuestionById($nextQuestionId, $testType);

// Fetch full answers (id, answer_text, is_correct) so server can pick exactly 1 correct + up to 3 incorrect
$stmtB = $conn->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
$stmtB->bind_param("i", $nextQuestionId);
$stmtB->execute();
$resB = $stmtB->get_result();
$optionsAll = [];
while ($r = $resB->fetch_assoc()) {
    // normalize keys to match existing front-end expectations (answer_text used in older main.js)
    $optionsAll[] = [
        'id' => (int)$r['id'],
        'answer_text' => $r['answer_text'],
        'is_correct' => (int)$r['is_correct'] === 1 ? true : false
    ];
}
$stmtB->close();

// Pick exactly one correct answer and up to 3 incorrect, randomized
$correctOptions = array_values(array_filter($optionsAll, fn($a) => $a['is_correct']));
$incorrectOptions = array_values(array_filter($optionsAll, fn($a) => !$a['is_correct']));

if (count($incorrectOptions) > 1) shuffle($incorrectOptions);
$incorrectOptions = array_slice($incorrectOptions, 0, 3);

// Ensure exactly one correct (if none found, we'll still show incorrects)
$answersToShow = [];
if (!empty($correctOptions)) {
    // Use first correct if there are multiple (DB ideally has one correct)
    $answersToShow[] = $correctOptions[0];
}
$answersToShow = array_merge($answersToShow, $incorrectOptions);

// If there's no correct answer found (edge case), just limit to up to 4 incorrect
if (empty($answersToShow)) {
    $answersToShow = array_slice($optionsAll, 0, 4);
}

// Shuffle final options so correct isn't always first
shuffle($answersToShow);

debugLog("Next question selected", [
    'user_id' => $userId,
    'question_id' => $nextQuestionId,
    'options_count' => count($answersToShow)
]);

echo json_encode([
    'done' => false,
    'question_id' => $nextQuestionId,
    'text' => $question['question_text'],
    'difficulty' => $question['difficulty'] ?? 1,
    'options' => $answersToShow
]);
