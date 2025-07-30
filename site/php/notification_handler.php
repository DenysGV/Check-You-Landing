<?php
// api/notification_handler.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';

// Загружаем переменные окружения из .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Подключаем PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Логирование для отладки
function log_message($message) {
    file_put_contents(__DIR__ . '/notification_log.txt', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

log_message('Received FreeKassa notification. Request method: ' . $_SERVER['REQUEST_METHOD']);
log_message('Request data: ' . print_r($_REQUEST, true));

$merchantId = $_REQUEST['MERCHANT_ID'] ?? null;
$amount = $_REQUEST['AMOUNT'] ?? null;
$intId = $_REQUEST['intid'] ?? null;
$orderId = $_REQUEST['ORDER_ID'] ?? null;
$remoteSignature = $_REQUEST['SIGN'] ?? null;
// Получаем наши кастомные параметры, переданные через FreeKassa
$productIdFromFk = $_REQUEST['us_product_id'] ?? null; // ID продукта, который мы передали в us_product_id

$secretWord2 = $_ENV['FREEKASSA_SECRET_WORD_2'];

if (!$merchantId || !$amount || !$intId || !$orderId || !$remoteSignature || !$productIdFromFk) {
    log_message('Missing required parameters in notification. Or us_product_id missing.');
    echo "NO";
    exit();
}

$localSignature = md5($merchantId . ':' . $amount . ':' . $secretWord2 . ':' . $orderId);

log_message("Local Signature: {$localSignature}, Remote Signature: {$remoteSignature}");

if ($localSignature === $remoteSignature) {
    log_message("Signature valid for Order ID: {$orderId}, Amount: {$amount}");

    try {
        global $pdo;

        $stmt = $pdo->prepare("SELECT id, status, amount, user_email, product_id FROM orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order) {
            // Проверяем, что ID продукта из уведомления совпадает с тем, что у нас в БД
            if ($order['product_id'] !== $productIdFromFk) {
                log_message("Product ID mismatch for order {$orderId}. Expected DB: {$order['product_id']}, Received FK: {$productIdFromFk}.");
                echo "NO"; // Отклоняем, если продукт не совпадает
                exit();
            }

            if ($order['status'] === 'paid') {
                log_message("Order {$orderId} already paid. Skipping update and email sending.");
                echo "YES";
            } elseif ((float)$order['amount'] !== (float)$amount) {
                log_message("Amount mismatch for order {$orderId}. Expected: {$order['amount']}, Received: {$amount}.");
                echo "NO";
            } else {
                // Обновляем статус заказа на 'paid'
                $updateStmt = $pdo->prepare("UPDATE orders SET status = 'paid', freekassa_transaction_id = ? WHERE id = ?");
                $updateStmt->execute([$intId, $order['id']]);
                log_message("Order {$orderId} successfully updated to paid. FreeKassa Transaction ID: {$intId}");

                // --- НОВАЯ ЛОГИКА: Отправка файла на почту ---
                $customerEmail = $order['user_email'];
                $productToDeliver = $order['product_id'];

                if ($customerEmail) {
                    $attachmentPath = '';
                    $emailSubject = 'Ваши инструкции от нашего магазина';
                    $emailBody = "Здравствуйте!<br><br>Благодарим вас за покупку. Ваши инструкции прикреплены к этому письму.";

                    // Определение пути к файлу по ID продукта
                    // Предполагаем, что файлы лежат в your_project_root/files/
                    // Например, your_project_root/files/WeDo2.pdf
                    $filesDir = __DIR__ . '/../files/'; // Папка files должна быть вне публичного доступа

                    switch ($productToDeliver) {
                        case 'WeDo2':
                            $attachmentPath = $filesDir . 'WeDo2_instructions.zip';
                            break;
                        case 'NXT':
                            $attachmentPath = $filesDir . 'NXT_instructions.zip';
                            break;
                        case 'SPIKE':
                            $attachmentPath = $filesDir . 'SPIKE_instructions.zip';
                            break;
                        case 'EV3':
                            $attachmentPath = $filesDir . 'EV3_instructions.zip';
                            break;
                        default:
                            log_message("Unknown product_id '{$productToDeliver}' for order {$orderId}. Cannot attach file.");
                            $attachmentPath = ''; // Неизвестный продукт, не прикрепляем
                            break;
                    }

                    if ($attachmentPath && file_exists($attachmentPath)) {
                        try {
                            $mail = new PHPMailer(true); // Включаем исключения
                            // Настройки SMTP
                            $mail->isSMTP();
                            $mail->Host = $_ENV['SMTP_HOST'];
                            $mail->SMTPAuth = true;
                            $mail->Username = $_ENV['SMTP_USERNAME'];
                            $mail->Password = $_ENV['SMTP_PASSWORD'];
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // или PHPMailer::ENCRYPTION_SMTPS для 465 порта
                            $mail->Port = (int)$_ENV['SMTP_PORT'];

                            // Отправитель
                            $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
                            // Получатель
                            $mail->addAddress($customerEmail);

                            // Контент
                            $mail->isHTML(true);
                            $mail->Subject = $emailSubject;
                            $mail->Body    = $emailBody;
                            $mail->AltBody = strip_tags($emailBody); // Для клиентов, которые не поддерживают HTML

                            // Прикрепляем файл
                            $mail->addAttachment($attachmentPath);

                            $mail->send();
                            log_message("Email with attachment sent to {$customerEmail} for order {$orderId}.");
                        } catch (Exception $e) {
                            log_message("Failed to send email for order {$orderId}. Mailer Error: {$mail->ErrorInfo}");
                        }
                    } else {
                        log_message("Attachment file not found at path: {$attachmentPath} for order {$orderId}.");
                    }
                } else {
                    log_message("User email not found for order {$orderId}. Cannot send instructions.");
                }

                echo "YES"; // Обязательный ответ FreeKassa после всех операций
            }
        } else {
            log_message("Order {$orderId} not found in database for notification.");
            echo "NO";
        }

    } catch (PDOException $e) {
        log_message("Database error in notification_handler.php: " . $e->getMessage());
        echo "NO";
    }

} else {
    log_message("Signature mismatch for Order ID: {$orderId}. Potential fraud attempt.");
    echo "NO";
}

?>