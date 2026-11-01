<?php
// ------------------------------------------------------------
// db_functions.php  (FINAL – with spaced repetition + tracking)
// ------------------------------------------------------------

// Include credentials
$privatePath = dirname(dirname(__DIR__)) . '/JoeSite/header.php';
if (!file_exists($privatePath)) {
    error_log("Private file missing: $privatePath");
    die(json_encode(['error' => 'Server config error']));
}
require_once $privatePath;

// DB connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    die(json_encode(['error' => 'Database connection failed']));
}

// ------------------------------------------------------------
// Helper: log function calls for debugging
// ------------------------------------------------------------
function logAction($action, $data = []) {
    $msg = "[" . date('Y-m-d H:i:s') . "] $action: " . json_encode($data);
    error_log($msg);
}

// ------------------------------------------------------------
// Fetch question by ID
// ------------------------------------------------------------
function getQuestionById($questionId, $testType) {
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM questions WHERE id = ? AND test_type = ?");
    $stmt->bind_param("is", $questionId, $testType);
    $stmt->execute();
    $result = $stmt->get_result();
    $question = $result->fetch_assoc();
    $stmt->close();

    logAction("getQuestionById", ['question_id' => $questionId, 'test_type' => $testType, 'found' => !!$question]);
    return $question;
}

// ------------------------------------------------------------
// Get all answers for a question (with spaced repetition logic)
// ------------------------------------------------------------
function getAnswersForQuestion($questionId, $userId, $testType) {
    if (!$userId) {
        throw new Exception("getAnswersForQuestion requires a valid user ID.");
    }

    global $conn;

    $stmt = $conn->prepare("
        SELECT a.id, a.answer_text, a.is_correct, 
               COALESCE(qas.shown_count,0) AS shown_count,
               COALESCE(qas.selected_count,0) AS selected_count
        FROM answers a
        INNER JOIN questions q ON a.question_id = q.id AND q.test_type = ?
        LEFT JOIN question_answer_stats qas
            ON a.id = qas.answer_id AND qas.user_id = ? AND qas.question_id = ?
        WHERE a.question_id = ?
    ");
    $stmt->bind_param("siii", $testType, $userId, $questionId, $questionId);
    $stmt->execute();
    $result = $stmt->get_result();

    $answers = [];
    while ($row = $result->fetch_assoc()) {
        $answers[] = $row;
    }
    $stmt->close();

    if (empty($answers)) return [];

    // --- Spaced repetition logic remains unchanged ---
    $correct = array_filter($answers, fn($a) => $a['is_correct']);
    $incorrect = array_filter($answers, fn($a) => !$a['is_correct']);

    $candidates = array_filter($correct, fn($a) => $a['selected_count'] == 0);
    if (empty($candidates)) {
        $selectedCorrect = $correct[array_rand($correct)];
    } else {
        usort($candidates, fn($a,$b) => $a['shown_count'] <=> $b['shown_count']);
        $minShown = $candidates[0]['shown_count'];
        $minCandidates = array_filter($candidates, fn($a) => $a['shown_count'] == $minShown);
        $selectedCorrect = $minCandidates[array_rand($minCandidates)];
    }

    usort($incorrect, fn($a,$b) => $a['shown_count'] <=> $b['shown_count']);
    if (count($incorrect) > 3) $incorrect = array_slice($incorrect, 0, 3);

    $allAnswers = array_merge([$selectedCorrect], $incorrect);
    shuffle($allAnswers);

    foreach ($allAnswers as &$a) {
        $a['text'] = $a['answer_text'];
        $a['correct'] = $a['is_correct'];
        unset($a['answer_text'], $a['is_correct']);
    }

    return $allAnswers;
}



// ------------------------------------------------------------
// Get correct answers count
// ------------------------------------------------------------
function getNumCorrectAnswers($questionId) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM answers WHERE question_id = ? AND is_correct = 1");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $count = (int)$row['cnt'];
    logAction("getNumCorrectAnswers", ['question_id' => $questionId, 'count' => $count]);
    return $count;
}

function recordAnswerAttempt($userId, $questionId, $answerIds, $selectedAnswerId, $isCorrect, $testType) {
    global $conn;

    // --- Validate test using DB assignment ---
    if (empty($testType) || !validateUserTest($userId, $testType)) {
        logAction("recordAnswerAttempt_error", [
            'error' => 'Invalid or unauthorized test type',
            'user_id' => $userId,
            'question_id' => $questionId,
            'provided_testType' => $testType
        ]);
        return false;
    }

    $allSuccess = true;

    // --- Update answer stats ---
    foreach ($answerIds as $answerId) {

        $selectedCount = ($answerId == $selectedAnswerId) ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO question_answer_stats 
                (user_id, question_id, answer_id, shown_count, selected_count, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                shown_count = shown_count + 1,
                selected_count = selected_count + VALUES(selected_count),
                updated_at = NOW()
        ");

        if (!$stmt) {
            $allSuccess = false;
            logAction("recordAnswerAttempt_error", [
                'query_error' => $conn->error,
                'user_id' => $userId,
                'question_id' => $questionId,
                'answer_id' => $answerId,
                'test_type' => $testType
            ]);
            continue;
        }

        $stmt->bind_param("iiii", $userId, $questionId, $answerId, $selectedCount);

        if (!$stmt->execute()) {
            $allSuccess = false;
            logAction("recordAnswerAttempt_error", [
                'stmt_error' => $stmt->error,
                'user_id' => $userId,
                'question_id' => $questionId,
                'answer_id' => $answerId,
                'test_type' => $testType
            ]);
        }

        $stmt->close();
    }

    // --- Insert attempt record ---
    $stmt2 = $conn->prepare("
        INSERT INTO question_attempts
            (user_id, question_id, correct, attempted_at)
        VALUES (?, ?, ?, NOW())
    ");

    if (!$stmt2) {

        $allSuccess = false;

        logAction("recordAnswerAttempt_error", [
            'query_error' => $conn->error,
            'user_id' => $userId,
            'question_id' => $questionId,
            'test_type' => $testType
        ]);

    } else {

        $correctFlag = $isCorrect ? 1 : 0;

        if (!$stmt2->bind_param("iii", $userId, $questionId, $correctFlag) ||
            !$stmt2->execute()) {

            $allSuccess = false;

            logAction("recordAnswerAttempt_error", [
                'stmt_error' => $stmt2->error,
                'user_id' => $userId,
                'question_id' => $questionId,
                'test_type' => $testType
            ]);
        }

        $stmt2->close();
    }

    logAction("recordAnswerAttempt", [
        'user_id' => $userId,
        'question_id' => $questionId,
        'answer_ids_shown' => $answerIds,
        'selected_answer_id' => $selectedAnswerId,
        'is_correct' => $isCorrect,
        'test_type' => $testType,
        'db_success' => $allSuccess
    ]);

    return $allSuccess;
}


// ------------------------------------------------------------
// Determine if a question is "new" (not all correct answers seen)
// ------------------------------------------------------------
function isQuestionNew($questionId, $userId, $testType) {
    if (!$userId) {
        throw new Exception("isQuestionNew requires a valid user ID.");
    }

    global $conn;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS unreviewed_correct
        FROM answers a
        INNER JOIN questions q 
            ON a.question_id = q.id AND q.test_type = ?
        LEFT JOIN question_answer_stats qas
            ON a.id = qas.answer_id
            AND qas.user_id = ?
            AND qas.question_id = ?
        WHERE a.question_id = ?
          AND a.is_correct = 1
          AND (qas.id IS NULL OR qas.shown_count = 0)
    ");
    $stmt->bind_param("siii", $testType, $userId, $questionId, $questionId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($result['unreviewed_correct'] > 0);
}


// ------------------------------------------------------------
// Adjust difficulty and schedule next review
// ------------------------------------------------------------
function updateQuestionProgress($questionId, $wasCorrect, $manualAdjust = null) {
    global $conn;

    $stmt = $conn->prepare("SELECT difficulty FROM questions WHERE id = ?");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $difficulty = (int)$res['difficulty'];
    $stmt->close();

    if ($manualAdjust !== null) {
        $difficulty = max(1, min(5, $difficulty + $manualAdjust));
    } elseif ($wasCorrect) {
        $difficulty = max(1, $difficulty - 1);
    } else {
        $difficulty = min(5, $difficulty + 1);
    }

    $stmt2 = $conn->prepare("UPDATE questions SET difficulty = ?, updated_at = NOW() WHERE id = ?");
    $stmt2->bind_param("ii", $difficulty, $questionId);
    $stmt2->execute();
    $stmt2->close();

    logAction("updateQuestionProgress", [
        'question_id' => $questionId,
        'was_correct' => $wasCorrect,
        'manual_adjust' => $manualAdjust,
        'new_difficulty' => $difficulty
    ]);
}

// ------------------------------------------------------------
// Get recently answered questions (for testing/debug)
// ------------------------------------------------------------
function getRecentlyAnsweredQuestions($userId, $limit = 10) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT q.id, q.question_text, qa.correct, qa.attempted_at
        FROM question_attempts qa
        JOIN questions q ON qa.question_id = q.id
        WHERE qa.user_id = ?
        ORDER BY qa.attempted_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    $stmt->close();

    logAction("getRecentlyAnsweredQuestions", ['user_id' => $userId, 'count' => count($data)]);
    return $data;
}

// ------------------------------------------------------------
// A quick function to query questions for the difficulty of a question
// ------------------------------------------------------------
function getQuestionDifficulty($questionId) {
    global $conn;

    $stmt = $conn->prepare("SELECT difficulty FROM questions WHERE id = ?");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['difficulty'] : null;
}


// ------------------------------------------------------------
// Get due questions for a specific user
// ------------------------------------------------------------
function getDueQuestions($userId, $testType) {
    global $conn;

    // Fetch all questions with attempt stats
    $stmt = $conn->prepare("
        SELECT 
            q.id AS question_id,
            q.difficulty,
            MAX(CASE WHEN qa.correct = 1 THEN qa.attempted_at END) AS last_correct,
            MAX(qa.attempted_at) AS last_attempt,
            SUM(CASE WHEN qa.correct = 1 THEN 1 ELSE 0 END) AS correct_attempts
        FROM questions q
        LEFT JOIN question_attempts qa 
            ON qa.question_id = q.id AND qa.user_id = ?
        WHERE q.test_type = ?
        GROUP BY q.id
        ORDER BY 
            (MAX(qa.attempted_at) IS NULL) DESC,
              q.id ASC

    ");
    $stmt->bind_param("is", $userId, $testType);
    $stmt->execute();
    $result = $stmt->get_result();

    $due = [];
    while ($row = $result->fetch_assoc()) {
        $questionId = (int)$row['question_id'];
        $difficulty = (int)($row['difficulty'] ?? 1);
        $lastCorrect = $row['last_correct'];
        $correctAttempts = (int)($row['correct_attempts'] ?? 0);

        // Never-attempted questions remain due on every quiz start.
        if ($row['last_attempt'] === null) {
            $due[] = $questionId;
            continue;
        }

        // After the first attempt, schedule from the most recent correct answer,
        // or from the most recent attempt until the user answers correctly.
        $scheduleFrom = $lastCorrect ?? $row['last_attempt'];
        $nextDueTimestamp = calculateNextDue($scheduleFrom, $difficulty, $correctAttempts);

        // If next due date has passed, the question is due
        if (time() >= $nextDueTimestamp) {
            $due[] = $questionId;
        }
    }

    $stmt->close();

    return $due;
}

// ------------------------------------------------------------
// Query the explanation table
// ------------------------------------------------------------

function getExplanation($questionId) {
    global $conn;
    $stmt = $conn->prepare("SELECT explanation_text FROM explanations WHERE question_id = ? LIMIT 1");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['explanation_text'] ?? '';
}

// ------------------------------------------------------------
// Calculate the next due timestamp based on spaced repetition
// ------------------------------------------------------------
function calculateNextDue($lastCorrect, $difficulty, $correctAttempts) {
    // Difficulty is 1 (easiest) through 5 (hardest). Higher difficulty
    // shortens the interval; consecutive correct answers lengthen it.
    $difficulty = max(1, min(5, (int)$difficulty));
    $correctAttempts = max(0, (int)$correctAttempts);
    $intervalDays = pow(2, max(0, $correctAttempts - 1)) / $difficulty;
    $intervalDays = min($intervalDays, 90);

    return strtotime($lastCorrect) + (int)round($intervalDays * 86400);
}

// ------------------------------------------------------------
// main.js calls update_question_difficulty.php which calls this function
// ------------------------------------------------------------
function updateQuestionDifficulty(int $questionId, int $newDifficulty): bool {
    global $conn;
    if ($questionId < 1 || $newDifficulty < 1 || $newDifficulty > 5) return false;
    $stmt = $conn->prepare("UPDATE questions SET difficulty = ? WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $newDifficulty, $questionId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}



// ------------------------------------------------------------
// Close DB
// ------------------------------------------------------------
function closeDb() {
    global $conn;
    if ($conn) $conn->close();
    logAction("closeDb");
}

function getUserAssignedTests($userId) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT DISTINCT test_type 
        FROM user_quizzes 
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $tests = [];
    while ($row = $result->fetch_assoc()) {
        $tests[] = $row['test_type'];
    }

    $stmt->close();
    return $tests;
}

function validateUserTest($userId, $testType) {

    $tests = getUserAssignedTests($userId);

    return in_array($testType, $tests);
}
?>
