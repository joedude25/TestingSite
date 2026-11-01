<?php
// Show all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_functions.php'; // Your existing DB connection and calculateNextDue()

// Fetch all questions
$questions = [];
$query = "SELECT id, difficulty FROM questions ORDER BY id ASC";
$result = $conn->query($query);
if (!$result) {
    die("Query failed: " . $conn->error);
}
while ($row = $result->fetch_assoc()) {
    $questions[] = $row;
}

// Prepare statement to fetch attempts for each question (kept for reference)
$stmtAttempts = $conn->prepare("SELECT correct, attempted_at FROM question_attempts WHERE question_id = ? ORDER BY attempted_at ASC");
if (!$stmtAttempts) die("Prepare failed: " . $conn->error);

$questionStatus = [];
foreach ($questions as $q) {

    // --- Determine if question is new using question_answer_stats + answers ---
    $stmtNew = $conn->prepare("
        SELECT COUNT(*) AS unselected_correct
        FROM question_answer_stats qas
        JOIN answers a ON a.id = qas.answer_id
        WHERE qas.question_id = ? AND a.is_correct = 1 AND qas.selected_count = 0
    ");
    $stmtNew->bind_param("i", $q['id']);
    $stmtNew->execute();
    $resultNew = $stmtNew->get_result()->fetch_assoc();
    $isNew = $resultNew['unselected_correct'] > 0;
    $stmtNew->close();

    // --- Calculate last correct attempt using question_attempts (kept for compatibility) ---
    $stmtAttempts->bind_param("i", $q['id']);
    $stmtAttempts->execute();
    $res = $stmtAttempts->get_result();

    $lastCorrect = null;
    $correctAttempts = 0;
    $totalAttempts = 0;

    while ($attempt = $res->fetch_assoc()) {
        $totalAttempts++;
        if ($attempt['correct']) {
            $lastCorrect = $attempt['attempted_at'];
            $correctAttempts++;
        }
    }

    // Calculate next due date if not new
    $dueDate = null;
    if (!$isNew && $lastCorrect) {
        $timestamp = calculateNextDue($lastCorrect, $q['difficulty'], $correctAttempts);
        $dueDate = date('Y-m-d H:i:s', $timestamp);
    }

    $questionStatus[] = [
        'question_id' => $q['id'],
        'difficulty' => $q['difficulty'],
        'is_new' => $isNew,
        'last_correct_attempt' => $lastCorrect,
        'due_date' => $dueDate
    ];
}

$stmtAttempts->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Question Status Overview</title>
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        .new { background: #d1ffd1; }
    </style>
</head>
<body>
<h1>Question Status Overview</h1>
<table>
    <thead>
        <tr>
            <th>Question ID</th>
            <th>Difficulty</th>
            <th>Is New</th>
            <th>Last Correct Attempt</th>
            <th>Next Due Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($questionStatus as $row): ?>
            <tr class="<?= $row['is_new'] ? 'new' : '' ?>">
                <td><?= $row['question_id'] ?></td>
                <td><?= $row['difficulty'] ?></td>
                <td><?= $row['is_new'] ? 'Yes' : 'No' ?></td>
                <td><?= $row['last_correct_attempt'] ?? '-' ?></td>
                <td><?= $row['due_date'] ?? '-' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
