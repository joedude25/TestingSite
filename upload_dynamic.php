<?php


    
    require_once 'auth_check.php'; // ensures $_SESSION['user_id'] exists
    require_once 'db_functions.php';
    
    $userId = $_SESSION['user_id'] ?? 0; 
    $dynamicTypes = [
        'eirp' => 'EIRP calculation',
        'fsplDistance' => 'FSPL distance',
        'ordering' => 'Ordering',
        'draganddrop' => 'Drag and drop'
    ];

?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Upload Dynamic Question</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    label { display: block; margin-top: 10px; }
    textarea, input, select { width: 400px; max-width: 100%; }
    button:disabled { background-color: #aaa; cursor: not-allowed; }
  </style>
</head>
<body>
    <?php 
  include 'header.php'; 
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

    $submitDisabled = empty($testTypes);
    
    ?>
  <h1>Upload Dynamic Question</h1>

  <form action="process_upload_dynamic.php" method="post" accept-charset="UTF-8">

    <label>Question Text:</label>
    <textarea name="question_text" rows="3" required></textarea>

    <label for="dynamic_type">Question type:</label>
    <select id="dynamic_type" name="dynamic_type" required>
      <option value="">-- Select type --</option>
      <?php foreach ($dynamicTypes as $value => $label): ?>
        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="template">Template (optional):</label>
    <textarea id="template" name="template" rows="3" placeholder="For drag and drop: Router:Forwards traffic; Switch:Connects devices || Forwards traffic; Connects devices; Divides traffic"></textarea>
    <small>For drag and drop, put term:correct match pairs before || and all draggable choices after it. Leave off || and the choices section if you don’t need distractors.</small>

    <label>Explanation (optional):</label>
    <textarea name="explanation" rows="3"></textarea>
    <label>Test Type:</label>
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
    <button type="submit" <?= $submitDisabled ? 'disabled' : '' ?>>Upload Dynamic Question</button>
  </form>

  <p><a href="upload.php">⬅️ Back to Static Upload</a></p>
</body>
</html>
