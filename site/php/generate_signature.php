<?php
// api/generate_signature.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php'; // Подключаем наш скрипт для работы с БД

// Загружаем переменные окружения из .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // ВНИМАНИЕ: Для продакшена замените * на домен вашего сайта!
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Добавим проверку на 'user_email' и 'product_id'
if (!isset($data['merchant_id'], $data['amount'], $data['order_id'], $data['user_email'], $data['product_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters: merchant_id, amount, order_id, user_email, or product_id']);
    exit();
}

$merchantIdFromEnv = $_ENV['FREEKASSA_MERCHANT_ID'];
$secretWord1 = $_ENV['FREEKASSA_SECRET_WORD_1'];

if ($data['merchant_id'] !== $merchantIdFromEnv) {
    http_response_code(403);
    echo json_encode(['error' => 'Merchant ID mismatch']);
    exit();
}

$merchantId = $data['merchant_id'];
$amount = (float)$data['amount'];
$orderId = $data['order_id'];
$userEmail = $data['user_email'];
$productId = $data['product_id']; // Получаем ID продукта

// --- НОВАЯ ЛОГИКА: Сохранение заказа в БД с email и product_id ---
try {
    global $pdo;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    if ($stmt->fetchColumn() > 0) {
        error_log("Attempted to create duplicate order_id: {$orderId}. Updating existing record with email and product_id.");
        $stmtUpdate = $pdo->prepare("UPDATE orders SET user_email = ?, product_id = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?");
        $stmtUpdate->execute([$userEmail, $productId, $orderId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO orders (order_id, amount, status, user_email, product_id) VALUES (?, ?, 'pending', ?, ?)");
        $stmt->execute([$orderId, $amount, $userEmail, $productId]);
        error_log("Order {$orderId} with amount {$amount}, email {$userEmail}, product_id {$productId} inserted into DB.");
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error during order creation: ' . $e->getMessage()]);
    error_log("Database error in generate_signature.php: " . $e->getMessage());
    exit();
}

// Генерируем подпись FreeKassa (MD5)
$signature = md5($merchantId . ':' . $amount . ':' . $secretWord1 . ':' . $orderId);

echo json_encode(['signature' => $signature]);

?>