<?php
require_once 'db_functions.php'; // provides $conn

function fail_and_exit($msg, $mysqli = null) {
    error_log("process_upload error: $msg");
    if ($mysqli) error_log("MySQL error: " . $mysqli->error);
    echo "<p style='color:darkred;'>Error: " . htmlspecialchars($msg) . "</p>";
    echo '<p><a href="upload.php">⬅️ Return to Upload</a></p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<p>This endpoint accepts POST from upload.php</p>';
    echo '<p><a href="upload.php">Go to upload page</a></p>';
    exit;
}

// Input fields
$question_text = trim($_POST['question_text'] ?? '');
$topic         = trim($_POST['topic'] ?? '');
$difficulty    = intval($_POST['difficulty'] ?? 0);
$explanation   = trim($_POST['explanation'] ?? '');
$testType = trim($_POST['test_type'] ?? '');

// Type always static for this upload page
$type = "static";

// Validation
if ($question_text === '') {
    fail_and_exit('Question text is required.');
}
if ($testType === '') fail_and_exit('Test Type is required.');
if ($difficulty < 1 || $difficulty > 5) fail_and_exit('Difficulty must be between 1 and 5.');

// Answer arrays
$answers_texts    = $_POST['answers_text']    ?? [];
$answers_corrects = $_POST['answers_correct'] ?? [];

// Normalize answers
$answers = [];
for ($i = 0; $i < count($answers_texts); $i++) {
    $text = trim($answers_texts[$i]);
    if ($text === '') continue;
    $is_correct = (isset($answers_corrects[$i]) && $answers_corrects[$i] == '1') ? 1 : 0;
    $answers[] = ['text' => $text, 'is_correct' => $is_correct];
}

$conn->begin_transaction();

try {
    // Insert question (no template column anymore)
    $qStmt = $conn->prepare("INSERT INTO questions (question_text, topic, difficulty, type, test_type) VALUES (?, ?, ?, ?, ?)");
    
    if (!$qStmt) throw new Exception($conn->error);

    $qStmt->bind_param("ssiss", $question_text, $topic, $difficulty, $type, $testType);
    if (!$qStmt->execute()) throw new Exception($qStmt->error);

    $question_id = $conn->insert_id;
    $qStmt->close();

    // Insert answers
    $answersInserted = 0;
    if (!empty($answers)) {
        $aStmt = $conn->prepare("INSERT INTO answers (question_id, answer_text, is_correct) VALUES (?, ?, ?)");
        if (!$aStmt) throw new Exception($conn->error);

        foreach ($answers as $a) {
            $aStmt->bind_param("isi", $question_id, $a['text'], $a['is_correct']);
            if (!$aStmt->execute()) throw new Exception($aStmt->error);
            $answersInserted++;
        }
        $aStmt->close();
    }

    // Insert explanation if provided
    $explanationInserted = false;
    if ($explanation !== '') {
        $eStmt = $conn->prepare("INSERT INTO explanations (question_id, explanation_text) VALUES (?, ?)");
        if (!$eStmt) throw new Exception($conn->error);

        $eStmt->bind_param("is", $question_id, $explanation);
        if (!$eStmt->execute()) throw new Exception($eStmt->error);

        $eStmt->close();
        $explanationInserted = true;
    }

    $conn->commit();

    // Confirmation page
    $safe_qid = intval($question_id);
    echo "<h2>✅ Static Question Added (ID: {$safe_qid})</h2>";
    echo "<p><strong>Text:</strong><br>".nl2br(htmlspecialchars($question_text))."</p>";
    echo "<p>Topic: ".htmlspecialchars($topic)." | Difficulty: ".htmlspecialchars($difficulty)."</p>";

    echo "<p><strong>Answers:</strong> {$answersInserted}</p>";
    if ($answersInserted) {
        echo "<ul>";
        foreach ($answers as $a) {
            $prefix = $a['is_correct'] ? "✅ " : "";
            echo "<li>".$prefix.htmlspecialchars($a['text'])."</li>";
        }
        echo "</ul>";
    }

        echo "<p><strong>Explanation:</strong><br>";
    if ($explanationInserted) {
        echo nl2br(htmlspecialchars($explanation));
    } else {
        echo "<i>None provided</i>";
    }
    echo "</p>";

    echo '<p><a href="upload.php">⬅️ Add Another Static Question</a></p>';
    echo '<p><a href="quiz.php">▶️ Start Quiz</a></p>';

} catch (Exception $e) {
    $conn->rollback();
    fail_and_exit("Transaction failed: " . $e->getMessage(), $conn);
}

$conn->close();
?>

