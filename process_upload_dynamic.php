<?php
require_once 'auth_check.php';
require_once 'db_functions.php';

// Collect inputs
$question_text = trim($_POST['question_text'] ?? '');
$type          = trim($_POST['dynamic_type'] ?? '');
$template      = trim($_POST['template'] ?? '');
$explanation   = trim($_POST['explanation'] ?? '');
$testType = trim($_POST['test_type'] ?? '');

// Basic validation
if ($question_text === '') die("Error: Question text is required.");
$allowedTypes = ['eirp', 'fsplDistance', 'ordering', 'draganddrop'];
if (!in_array($type, $allowedTypes, true)) die("Error: Choose a supported question type.");
if ($testType === '' || !validateUserTest((int)$_SESSION['user_id'], $testType)) die("Error: Choose one of your assigned tests.");

$conn->begin_transaction();
try {
    // Insert question
    $stmt = $conn->prepare("INSERT INTO questions (question_text, type, template, test_type) VALUES (?, ?, ?, ?)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("ssss", $question_text, $type, $template, $testType);
    $stmt->execute();
    $question_id = $conn->insert_id;
    $stmt->close();

    // Insert explanation if provided
    if ($explanation !== '') {
        $stmt = $conn->prepare("INSERT INTO explanations (question_id, explanation_text) VALUES (?, ?)");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param("is", $question_id, $explanation);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    echo "<h2>✅ Dynamic question uploaded (ID: $question_id)</h2>";
    echo "<p><strong>Question:</strong><br>" . htmlspecialchars($question_text) . "</p>";
    if ($template) echo "<p><strong>Template:</strong> " . htmlspecialchars($template) . "</p>";
    if ($explanation) echo "<p><strong>Explanation:</strong> " . htmlspecialchars($explanation) . "</p>";
    echo '<p><a href="upload_dynamic.php">⬅️ Add another dynamic question</a></p>';
    echo '<p><a href="upload.php">⬅️ Go to static upload page</a></p>';

} catch (Exception $e) {
    $conn->rollback();
    die("Error uploading question: " . $e->getMessage());
}
$conn->close();
