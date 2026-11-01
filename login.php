<?php
session_start();
require_once 'db_functions.php';

// If already logged in, redirect elsewhere
if (isset($_SESSION['user_id'])) {
    header("Location: start.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);

    if ($username === '') {
        $error = "Username is required.";
    } else {

        $stmt = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
        if (!$stmt) {
            $error = "Server error.";
        } else {

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

                if (!$user) {
            $error = "Invalid username.";
        } else {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];

            // Redirect to dashboard
            header("Location: start.php");
            exit;
        }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body>

<h2>Login</h2>

<?php if ($error): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="POST">
    <label>Username:</label><br>
    <input type="text" name="username" required><br><br>
    <button type="submit">Login</button>
</form>

</body>
</html>
