<?php
// db.php - Docker версия

$host = 'db';           // ИЗМЕНИТЬ: было 'localhost', стало 'db'
$dbname = 'appdb';      // ИЗМЕНИТЬ: было 'training_db', может нужно оставить
$username = 'root';
$password = 'rootpassword'; // ИЗМЕНИТЬ: было 'root', стало 'rootpassword'

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Не удалось подключиться к базе данных: " . $e->getMessage());
}
?>
