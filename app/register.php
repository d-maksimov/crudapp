<?php
// register.php
session_start();
require 'db.php';

// Обработка данных, отправленных через форму
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Проверка на пустое имя пользователя и пароль
    if (empty($username) || empty($password)) {
        echo "Имя пользователя и пароль не могут быть пустыми.";
        exit();
    }

    // Проверка, существует ли уже такой пользователь
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $userExists = $stmt->fetchColumn();

    if ($userExists) {
        echo "Пользователь с таким именем уже существует.";
        exit();
    }

    // Хешируем пароль
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Вставляем нового пользователя в базу данных
    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->execute([$username, $passwordHash]);

    // Сохраняем ID пользователя в сессии
    $_SESSION['user_id'] = $pdo->lastInsertId();
    $_SESSION['username'] = $username;  // Сохраняем имя пользователя в сессии для дальнейшего использования

    // Перенаправляем на страницу dashboard.php
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="auth-container">
        <h1>Регистрация</h1>
        <form method="POST" action="register.php">
            <input type="text" name="username" placeholder="Имя пользователя" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Зарегистрироваться</button>
        </form>
        <a href="login.php">Уже есть аккаунт? Войти</a>
        <a href="index.php" class="back-button">На главное меню</a>
    </div>
</body>
</html>

