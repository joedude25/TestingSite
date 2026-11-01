<?php
session_start();
require_once 'auth_check.php';
require_once 'db_functions.php';

$userId = (int)$_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question_id'])) {
    $questionId = filter_var($_POST['delete_question_id'], FILTER_VALIDATE_INT);

    if ($questionId === false || $questionId < 1) {
        $error = 'Please choose a valid question.';
    } else {
        // Resolve the test type from the database; don't rely on a submitted test name.
        $lookup = $conn->prepare('SELECT test_type FROM questions WHERE id = ?');
        if (!$lookup) {
            $error = 'Could not look up that question.';
        } else {
            $lookup->bind_param('i', $questionId);
            $lookup->execute();
            $question = $lookup->get_result()->fetch_assoc();
            $lookup->close();

            if (!$question || !validateUserTest($userId, $question['test_type'])) {
                $error = 'That question was not found in one of your assigned tests.';
            } else {
                try {
                    $conn->begin_transaction();

                    // These tables don't have cascading foreign keys, so remove references explicitly.
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

                    $stmt = $conn->prepare('DELETE FROM questions WHERE id = ?');
                    if (!$stmt) {
                        throw new Exception($conn->error);
                    }
                    $stmt->bind_param('i', $questionId);
                    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                        throw new Exception($stmt->error ?: 'Question could not be deleted.');
                    }
                    $stmt->close();

                    $conn->commit();
                    $message = "Question #{$questionId} and its related records were deleted.";
                } catch (Throwable $exception) {
                    $conn->rollback();
                    error_log('Question deletion failed: ' . $exception->getMessage());
                    $error = 'Deletion failed. The database changes were rolled back.';
                }
            }
        }
    }
}

$questionsByTest = [];
$answerLookup = $conn->prepare('SELECT a.id, a.question_id, a.answer_text, a.is_correct, COALESCE(qas.selected_count, 0) AS selected_count FROM answers a JOIN questions q ON q.id = a.question_id LEFT JOIN question_answer_stats qas ON qas.answer_id = a.id AND qas.question_id = a.question_id AND qas.user_id = ? WHERE q.test_type = ? ORDER BY a.id');
$dueLookup = $conn->prepare('SELECT MAX(CASE WHEN correct = 1 THEN attempted_at END) AS last_correct, SUM(CASE WHEN correct = 1 THEN 1 ELSE 0 END) AS correct_attempts, MAX(attempted_at) AS last_attempt FROM question_attempts WHERE user_id = ? AND question_id = ?');
foreach (getUserAssignedTests($userId) as $testType) {
    $stmt = $conn->prepare('SELECT id, question_text, type, difficulty FROM questions WHERE test_type = ? ORDER BY id');
    if (!$stmt) {
        $error = 'Could not load questions.';
        break;
    }
    $stmt->bind_param('s', $testType);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($questions as &$question) {
        $questionId = (int)$question['id'];
        $answerLookup->bind_param('is', $userId, $testType);
        $answerLookup->execute();
        $answerResult = $answerLookup->get_result();
        $question['answers'] = [];
        while ($answer = $answerResult->fetch_assoc()) {
            if ((int)$answer['question_id'] === $questionId) $question['answers'][] = $answer;
        }
        $dueLookup->bind_param('ii', $userId, $questionId);
        $dueLookup->execute();
        $progress = $dueLookup->get_result()->fetch_assoc();
        $correctAttempts = (int)($progress['correct_attempts'] ?? 0);
        if (!$progress['last_attempt']) {
            $question['due_at'] = time();
        } else {
            $scheduleFrom = $progress['last_correct'] ?: $progress['last_attempt'];
            $question['due_at'] = calculateNextDue($scheduleFrom, (int)($question['difficulty'] ?? 1), $correctAttempts);
        }
    }
    unset($question);
    $questionsByTest[$testType] = $questions;
}
$answerLookup->close();
$dueLookup->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Questions</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { max-width: 900px; margin: 30px auto; padding: 0 16px; font-family: Arial, sans-serif; }
        .question-row { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; border-bottom: 1px solid #ddd; padding: 12px 0; }
        .question-text { margin: 0; overflow-wrap: anywhere; }
        .answers-toggle { grid-column: 1 / -1; }
        .answers-toggle summary { display: inline-block; padding: 7px 10px; border: 1px solid #aaa; border-radius: 4px; background: #f5f5f5; cursor: pointer; list-style: none; }
        .answers-toggle summary::-webkit-details-marker { display: none; }
        .answers-toggle summary::before { content: '＋ '; }
        .answers-toggle[open] summary::before { content: '− '; }
        .answers { margin-top: 8px; padding: 10px 14px; background: #f7f7f7; border-radius: 4px; }
        .answer { padding: 6px 8px; margin: 5px 0; border-radius: 4px; }
        .answer.correct { color: #155724; background: #d4edda; }
        .answer.incorrect { color: #721c24; background: #f8d7da; }
        .delete-button { color: #fff; background: #a52828; border: 0; border-radius: 4px; padding: 8px 12px; cursor: pointer; }
        .notice, .error { padding: 10px; border-radius: 4px; }
        .notice { background: #e7f5e8; }
        .error { background: #fde8e8; }
        @media (max-width: 600px) { .question-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>Manage Questions</h1>
    <p><a href="start.php">Dashboard</a> · <a href="upload.php">Upload questions</a></p>

    <?php if ($message): ?>
        <p class="notice" role="status"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="error" role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$questionsByTest): ?>
        <p>No assigned tests are available.</p>
    <?php endif; ?>

    <?php foreach ($questionsByTest as $testType => $questions): ?>
        <section>
            <h2><?= htmlspecialchars(strtoupper($testType)) ?> (<?= count($questions) ?>)</h2>
            <?php if (!$questions): ?>
                <p>No questions in this test.</p>
            <?php endif; ?>
            <?php foreach ($questions as $question): ?>
                <div class="question-row">
                    <p class="question-text">
                        <strong>#<?= (int)$question['id'] ?> · <?= htmlspecialchars($question['type'] ?: 'static') ?></strong><br>
                        <?= nl2br(htmlspecialchars($question['question_text'])) ?>
                        <br><small>Due: <?= $question['due_at'] ? date('M j, Y', $question['due_at']) : 'Unknown' ?></small>
                    </p>
                    <details class="answers-toggle">
                        <summary>Show answers (<?= count($question['answers']) ?>)</summary>
                        <div class="answers" id="answers-<?= (int)$question['id'] ?>">
                            <?php if (!$question['answers']): ?><p>No answers recorded.</p><?php endif; ?>
                            <?php foreach ($question['answers'] as $answer): ?>
                                <div class="answer <?= $answer['is_correct'] ? 'correct' : 'incorrect' ?>">
                                    <?= $answer['is_correct'] ? 'Correct' : 'Incorrect' ?> · Selected <?= (int)$answer['selected_count'] ?> times<br>
                                    <?= nl2br(htmlspecialchars($answer['answer_text'])) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <form method="post" onsubmit="return confirm('Delete question #<?= (int)$question['id'] ?> and all of its answers, explanations, and attempt history? This cannot be undone.');">
                        <input type="hidden" name="delete_question_id" value="<?= (int)$question['id'] ?>">
                        <button class="delete-button" type="submit">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</body>
</html>
