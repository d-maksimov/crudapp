<?php
// delete_workout.php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$workout_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("DELETE FROM workouts WHERE id = ? AND user_id = ?");
$stmt->execute([$workout_id, $user_id]);

header("Location: dashboard.php");
?>
