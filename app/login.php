<?php
// login.php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Неправильное имя пользователя или пароль.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Вход</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="auth-container">
        <h1>Вход</h1>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo $error; ?></p>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <input type="text" name="username" placeholder="Имя пользователя" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
        <a href="register.php">Создать аккаунт</a>
        <a href="index.php" class="back-button">На главное меню</a>
    </div>
</body>
</html>
