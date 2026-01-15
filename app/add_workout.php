<?php
// add_workout.php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $date = $_POST['date'];
    $workout_type = $_POST['workout_type'];
    $duration = $_POST['duration'];
    $notes = $_POST['notes'];

    // Обработка загрузки изображения
    $image = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $image_name = basename($_FILES['image']['name']);
        $image_path = 'uploads/' . $image_name;
        move_uploaded_file($_FILES['image']['tmp_name'], $image_path);
        $image = $image_path;
    }

    $stmt = $pdo->prepare("INSERT INTO workouts (user_id, date, workout_type, duration, notes, image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $date, $workout_type, $duration, $notes, $image]);

    header("Location: dashboard.php");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Добавить тренировку</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h1>Добавить тренировку</h1>
<form method="POST" enctype="multipart/form-data">
    <input type="date" name="date" required>
    <input type="text" name="workout_type" placeholder="Тип тренировки" required>
    <input type="number" name="duration" placeholder="Длительность (мин)" required>
    <textarea name="notes" placeholder="Заметки"></textarea>
    <input type="file" name="image" accept="image/*">
    <button type="submit">Добавить</button>
</form>
</body>
</html>
