<?php
// index.php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Добро пожаловать в веб-сервис тренировок</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1>Добро пожаловать в веб-сервис "Тренировки"</h1>
        <p>Сделай шаг навстречу лучшей версии себя!</p>
        <nav>
            <a href="login.php">Вход</a> | <a href="register.php">Регистрация</a>
        </nav>
    </header>

    <!-- Секция с фотографиями -->
    <section class="photos">
        <h2>Тренируйтесь с удовольствием!</h2>
        <div class="photo-gallery">
            <img src="images/photo1.jpg" alt="Тренировка на природе">
            <img src="images/photo2.jpg" alt="Йога на рассвете">
            <img src="images/photo3.jpg" alt="Бег по пляжу">
        </div>
    </section>

<!-- Секция обратной связи -->
<section class="feedback">
    <h2>Свяжитесь с нами</h2>
    <p>Есть вопросы или предложения? Мы всегда рады помочь!</p>
    <div class="contact-links">
        <a href="https://t.me/potz_s_komi" target="_blank" title="Напишите нам в Telegram">
            <img src="images/tg.png" alt="Telegram" style="width: 150px;">
        </a>
        <a href="https://wa.me/qr/XYUCQNREG2Y6D1" target="_blank" title="Напишите нам в WhatsApp">
            <img src="images/wh.png" alt="WhatsApp" style="width: 130px;">
        </a>
    </div>
</section>

<style>
    .contact-links {
        display: flex;
        gap: 20px; /* Расстояние между иконками */
        justify-content: center; /* Выравнивание по центру */
        align-items: center; /* Вертикальное выравнивание */
        margin-top: 10px; /* Отступ сверху */
    }

    .contact-links img {
        
        height: auto;
    }
</style>


</body>
</html>
