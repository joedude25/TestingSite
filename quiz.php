<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Redirect to login if not logged in
require_once 'auth_check.php';

require_once 'db_functions.php';
if (file_exists('debug_helpers.php') && isset($_GET['debug'])) {
    require_once 'debug_helpers.php';
}

// -----------------------------------
// Step 1: Determine the test type
// -----------------------------------

$userId = $_SESSION['user_id'];
$testType = $_SESSION['test_type'] ?? null;

if (!$testType) {
    die("No test selected. Please start a quiz from the dashboard.");
}

if (!validateUserTest($userId, $testType)) {
    die("Invalid or unauthorized test type.");
}




// -----------------------------------
// Step 2: Fetch due questions for this user
// (Using your existing function but passing $testType)
// -----------------------------------
$dueQuestions = getDueQuestions($userId, $testType);
$quizData = [];

foreach ($dueQuestions as $questionId) {
    $question = getQuestionById($questionId, $testType);
    $answers  = getAnswersForQuestion($questionId, $_SESSION['user_id'], $testType);
    $isNew    = isQuestionNew($questionId, $_SESSION['user_id'], $testType);

    if (!$question || (empty($answers) && empty($question['template']))) continue;

    // Fetch explanation from the test-specific table
   
    $stmt = $conn->prepare("SELECT explanation_text FROM explanations WHERE question_id = ?");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $explanation = $res->fetch_assoc()['explanation_text'] ?? '';
    $stmt->close();

    $type = $question['type'] ?? 'static';

    $quizData[] = [
        'question_id' => $questionId,
        'question_text' => $question['question_text'],
        'answers' => $answers,
        'is_new' => $isNew,
        'explanation' => $explanation,
        'type' => $type,
        'difficulty' => $question['difficulty'],
        'template' => $question['template'] ?? null
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Adaptive Quiz - <?= htmlspecialchars(strtoupper($testType)) ?></title>
<link rel="stylesheet" href="style.css">
<style>
    #quiz-container { max-width: 600px; margin: 40px auto; }
    ul { list-style: none; padding-left: 0; }
    li { margin-bottom: 8px; }
    .correct { background-color: #c8e6c9; }
    .selected { background-color: #ffcdd2; }
    button { margin: 5px; }
</style>
</head>
<body>
<div id="quiz-container">
    <div id="question-counter"></div>
    <h2 id="question-text">Loading question...</h2>
    <ul id="options"></ul>
    <div id="feedback"></div>
    <div id="explanation" style="margin-top:10px;"></div>
    <div id="action-buttons"></div>
</div>

<script>
const userId = <?= json_encode($_SESSION['user_id']) ?>;
const testType = <?= json_encode($testType) ?>;
const quizData = <?= json_encode($quizData) ?>;
</script>
<script src="main.js"></script>
</body>
</html>
