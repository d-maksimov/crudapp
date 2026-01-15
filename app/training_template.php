<?php
session_start();
require 'db.php';

// Проверка, что пользователь вошел в систему
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    echo "Ошибка: пользователь не авторизован.";
    exit();
}


$workout_type = isset($_SESSION['workout_type']) ? $_SESSION['workout_type'] : '';

// Обработка нажатия на кнопку "Завершить тренировку"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['finish_workout'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO workouts (user_id, workout_type, workout_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $workout_type]);
        echo "<p>Тренировка \"$workout_type\" успешно добавлена!</p>";
    } catch (PDOException $e) {
        echo "Ошибка: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($workout_type); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1>Тренировка: <?php echo htmlspecialchars($workout_type); ?></h1>
        <nav>
            <a href="dashboard.php">Назад к тренировкам</a>
        </nav>
    </header>

    <section class="workout-content">
        <h2>Описание тренировки</h2>
        <p>Тренировка <?php echo htmlspecialchars($workout_type); ?> помогает улучшить физическую форму и укрепить здоровье.</p>

        <img src="images/<?php echo strtolower($workout_type); ?>1.png" alt="<?php echo htmlspecialchars($workout_type); ?>" class="workout-image">

        <h3>Таймер тренировки</h3>
        <div id="timer">
            <span id="minutes">00</span>:<span id="seconds">00</span>
        </div>
        <button onclick="startTimer()">Запустить таймер</button>
        <button onclick="stopTimer()">Остановить таймер</button>

        <form method="POST">
            <!-- Добавляем скрытое поле для кнопки завершения -->
            <input type="hidden" name="finish_workout" value="1">
            <button type="submit">Завершить тренировку</button>
        </form>

        <!-- Кнопка для возврата на страницу профиля -->
        <div class="buttons">
            <a href="dashboard.php"><button>Выход в профиль</button></a>
            <a href="index.php"><button> Главное меню</button></a>
        </div>
    </section>

    <script>
        let timer;
        let secondsRemaining =1800; // Устанавливаем 30 минут для примера

        function startTimer() {
            if (timer) clearInterval(timer); // Если таймер уже запущен, сбрасываем его
            timer = setInterval(() => {
                if (secondsRemaining > 0) {
                    secondsRemaining--;
                    displayTime();
                } else {
                    clearInterval(timer);
                    alert("Время тренировки истекло!");
                }
            }, 1000);
        }

        function stopTimer() {
            clearInterval(timer);
        }

        function displayTime() {
            const minutes = Math.floor(secondsRemaining / 60);
            const seconds = secondsRemaining % 60;
            document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
            document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
        }
    </script>
</body>
</html>
