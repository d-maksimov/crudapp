<?php
// send_feedback.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = htmlspecialchars($_POST['name']);
    $email = htmlspecialchars($_POST['email']);
    $message = htmlspecialchars($_POST['message']);

    // Простая отправка сообщения на email
    $to = "$maxdi04@icloud.com"; // Замените на свой email
    $subject = "Сообщение от $name";
    $body = "Имя: $name\nEmail: $email\n\nСообщение:\n$message";
    $headers = "From: $email";

    if (mail($to, $subject, $body, $headers)) {
        echo "Спасибо за сообщение! Мы свяжемся с вами в ближайшее время.";
    } else {
        echo "Ошибка при отправке сообщения. Пожалуйста, попробуйте снова.";
    }
} else {
    header("Location: index.php");
    exit();
}
?>
