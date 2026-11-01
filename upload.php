<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Upload Static Questions</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
    }
    .answer-row {
      margin-bottom: 5px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .remove-btn {
      cursor: pointer;
      background-color: #d9534f;
      color: #fff;
      border: none;
      padding: 3px 7px;
      border-radius: 4px;
    }
    .remove-btn:hover {
      background-color: #c9302c;
    }
    .add-btn {
      background-color: #5cb85c;
      color: white;
      border: none;
      padding: 5px 10px;
      border-radius: 4px;
      cursor: pointer;
    }
    .add-btn:hover {
      background-color: #4cae4c;
    }
  </style>
</head>
<body>

<?php 
session_start();
include 'header.php'; 
// Redirect to login if not logged in
require_once 'auth_check.php';
require_once 'db_functions.php';
$userId = $_SESSION['user_id'] ?? 0; // ensure user_id

// Fetch distinct test types for this user
$testTypes = [];
$stmt = $conn->prepare("SELECT DISTINCT test_type FROM user_quizzes WHERE user_id = ? ORDER BY test_type ASC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $testTypes[] = $row['test_type'];
}
$stmt->close();


?>



<h1>Upload Static Questions</h1>



<form action="process_upload.php" method="post" id="upload-form" accept-charset="UTF-8">

  <input type="hidden" name="type" value="static">

  <label><strong>Question Text:</strong></label><br>
  <textarea name="question_text" rows="3" cols="60" required></textarea><br><br>

  <label><strong>Topic:</strong></label><br>
  <input type="text" name="topic"><br><br>

  <label><strong>Difficulty (1-10):</strong></label><br>
  <input type="number" name="difficulty" min="1" max="5" value="1" required><br><br>

  <label><strong>Explanation:</strong></label><br>
  <textarea name="explanation" rows="3" cols="60"></textarea><br><br>

  <label><strong>Answers:</strong></label>
  <div id="answers-container">
    <!-- Initial answer row -->
    <div class="answer-row">
      <input type="text" name="answers_text[]" placeholder="Answer text" required>
      <select name="answers_correct[]">
        <option value="0">Incorrect</option>
        <option value="1">Correct</option>
      </select>
      <button type="button" class="remove-btn">✖</button>
    </div>
  </div>
  <br>
  <label><strong>Test Type:</strong></label><br>
  <?php if (empty($testTypes)): ?>
      <p style="color:red;">You don’t have any test types assigned. Please contact an admin.</p>
  <?php else: ?>
      <select name="test_type" required>
          <option value="">-- Select Test --</option>
          <?php foreach ($testTypes as $type): ?>
              <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(strtoupper($type)) ?></option>
          <?php endforeach; ?>
      </select>
  <?php endif; ?>
  <br><br>

  <button type="button" id="add-answer-btn" class="add-btn">➕ Add Answer</button><br><br>

  <?php $submitDisabled = empty($testTypes); ?>
  <button type="submit" <?= $submitDisabled ? 'disabled' : '' ?>>Upload Question</button>
</form>

<p><a href="upload_dynamic.php">Upload Dynamic Questions</a></p>

<script>
  const container = document.getElementById('answers-container');
  const addBtn = document.getElementById('add-answer-btn');

  // Add new answer row
  addBtn.addEventListener('click', () => {
    const div = document.createElement('div');
    div.className = 'answer-row';
    div.innerHTML = `
      <input type="text" name="answers_text[]" placeholder="Answer text" required>
      <select name="answers_correct[]">
        <option value="0">Incorrect</option>
        <option value="1">Correct</option>
      </select>
      <button type="button" class="remove-btn">✖</button>
    `;
    container.appendChild(div);
  });

  // Remove answer row
  container.addEventListener('click', (e) => {
    if (e.target.classList.contains('remove-btn')) {
      const rows = container.querySelectorAll('.answer-row');
      if (rows.length > 1) {
        e.target.closest('.answer-row').remove();
      } else {
        alert("At least one answer is required.");
      }
    }
  });
</script>

</body>
</html>
