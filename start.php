<?php
session_start();
require_once 'auth_check.php';  // ensures user is logged in
require_once 'db_functions.php'; // for DB connection

$userId = $_SESSION['user_id'];

// Handle form submission: store selected quiz in session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_test'])) {
    $_SESSION['test_type'] = $_POST['selected_test'];
    // Redirect to quiz page
    header("Location: quiz.php");
    exit;
}

// Fetch quizzes assigned to the current user
$stmt = $conn->prepare("SELECT DISTINCT test_type FROM user_quizzes WHERE user_id = ? ORDER BY test_type ASC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$assignedQuizzes = [];
while ($row = $result->fetch_assoc()) {
    $assignedQuizzes[] = $row['test_type'];
}
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; }
        h1 { text-align: center; }
        form { display: flex; flex-direction: column; align-items: center; margin-bottom: 20px; }
        select, button { font-size: 1.1rem; padding: 5px; margin-bottom: 10px; }
        .manage-link { display: block; width: fit-content; margin: 0 auto; padding: 8px 12px; border: 1px solid #555; border-radius: 4px; color: #111; text-decoration: none; }
    </style>
</head>
<body>
    <p>Logged in as: <?= htmlspecialchars($_SESSION['role']) ?> (User ID: <?= $_SESSION['user_id'] ?>)</p>
    <h1>Dashboard</h1>

    <!-- Start Quiz -->
    <h2>Start Quiz</h2>
    <?php if (!empty($assignedQuizzes)): ?>
        <form method="post">
            <select name="selected_test" required>
                <option value="">-- Choose a quiz --</option>
                <?php foreach ($assignedQuizzes as $quiz): ?>
                    <option value="<?= htmlspecialchars($quiz) ?>"><?= strtoupper($quiz) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Start Quiz</button>
        </form>
    <?php else: ?>
        <p>No quizzes assigned to you yet.</p>
    <?php endif; ?>

    <!-- Upload Questions -->
    <h2>Upload Questions</h2>
    <?php if (!empty($assignedQuizzes)): ?>
        <form method="get" action="upload.php">
            <button type="submit">Upload Questions</button>
        </form>
    <?php else: ?>
        <p>You cannot upload questions — no test assigned.</p>
    <?php endif; ?>

    <p><a class="manage-link" href="manage_questions.php">Manage Questions</a></p>

</body>
</html>
