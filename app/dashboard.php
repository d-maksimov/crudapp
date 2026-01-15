<?php
// dashboard.php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Проверка наличия имени пользователя в сессии
if (!isset($_SESSION['username'])) {
    // Извлечение имени пользователя из базы данных
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $username = $stmt->fetchColumn();

    if ($username) {
        $_SESSION['username'] = $username;
    } else {
        $_SESSION['username'] = "Гость";
    }
}

// Получаем количество тренировок за сегодняшний день
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM workouts WHERE user_id = ? AND DATE(workout_date) = ?");
$stmt->execute([$user_id, $today]);
$today_workout_count = $stmt->fetch()['count'];

// Получаем общее количество тренировок пользователя за всё время
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM workouts WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_workout_count = $stmt->fetch()['total'];

// Устанавливаем ежедневную цель тренировок
$daily_goal = 2; // Например, цель - 2 тренировки в день

// Рассчитываем прогресс: если сегодняшняя цель достигнута, показываем 100%, иначе рассчитываем процент
$progress = min(100, ($today_workout_count / $daily_goal) * 100);

// Подготовка данных для графика за последние 5 дней
$workout_data = [];
$labels = [];

// Получаем даты последних 5 дней, начиная с текущего
for ($i = 4; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = $date;
    $workout_data[$date] = 0; // По умолчанию для каждого дня устанавливаем 0 тренировок
}

// Запрос для получения данных по тренировкам за последние 5 дней
$stmt = $pdo->prepare("
    SELECT DATE(workout_date) as date, COUNT(*) as count 
    FROM workouts 
    WHERE user_id = ? AND workout_date >= DATE_SUB(CURDATE(), INTERVAL 5 DAY) 
    GROUP BY DATE(workout_date)
");
$stmt->execute([$user_id]);
$workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Обновляем данные для дней, когда тренировки были выполнены
foreach ($workouts as $workout) {
    $workout_data[$workout['date']] = $workout['count'];
}

// Преобразуем данные для графика
$workout_counts = array_values($workout_data);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мои тренировки</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <h1>Привет, <?php echo htmlspecialchars($_SESSION['username']); ?>! Ваши тренировки</h1>
        <nav>
            <a href="index.php">Главная</a> | <a href="logout.php">Выйти</a>
        </nav>
    </header>

    <!-- Прогресс пользователя -->
    <section class="user-progress">
        <h2>Ваш прогресс на сегодня</h2>
        <div class="progress-bar">
            <div class="progress" style="width: <?php echo $progress; ?>%;"></div>
        </div>
        <p>Сегодня выполнено тренировок: <?php echo $today_workout_count; ?> из <?php echo $daily_goal; ?></p>
        <p>Общее количество тренировок: <?php echo $total_workout_count; ?></p>
    </section>

    <!-- График тренировок -->
    <section class="chart-section">
        <h2>Статистика за последние дни</h2>
        <canvas id="workoutChart"></canvas>
    </section>

    <!-- Меню тренировок -->
    <section class="training-menu">
        <h2>Выберите тренировку</h2>
        <div class="training-cards">
            <div class="card">
                <img src="images/cardio.jpg" alt="Кардио">
                <h3>Кардио</h3>
                <p>Тренировка для сердечно-сосудистой системы.</p>
                <a href="cardio.php" class="button">Начать</a>
            </div>
            <div class="card">
                <img src="images/strength.jpg" alt="Силовая">
                <h3>Силовая тренировка</h3>
                <p>Развивайте силу и мышечную массу.</p>
                <a href="strength.php" class="button">Начать</a>
            </div>
            <div class="card">
                <img src="images/yoga.jpg" alt="Йога">
                <h3>Йога</h3>
                <p>Укрепите тело и успокойте разум.</p>
                <a href="yoga.php" class="button">Начать</a>
            </div>
        </div>
    </section>

    <script>
        // График тренировки с использованием Chart.js
        const ctx = document.getElementById('workoutChart').getContext('2d');
        const workoutChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Количество тренировок',
                    data: <?php echo json_encode($workout_counts); ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 2,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Number.isInteger(value) ? value : null;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
