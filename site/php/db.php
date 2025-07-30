<?php
// db.php - Файл для подключения и инициализации базы данных

class Database {
    private $pdo;
    private $dbPath;

    public function __construct() {
        $this->dbPath = __DIR__ . '/database.sqlite';

        try {
            $this->pdo = new PDO("sqlite:{$this->dbPath}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->initializeDatabase();

        } catch (PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }

    private function initializeDatabase() {
        // Создаем таблицу orders, если она еще не существует
        // Добавлен столбец product_id
        $query = "
            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT UNIQUE NOT NULL,
                amount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending', -- pending, paid, cancelled, failed
                freekassa_transaction_id TEXT DEFAULT NULL,
                user_email TEXT DEFAULT NULL,
                product_id TEXT DEFAULT NULL, -- НОВОЕ ПОЛЕ для ID продукта
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ";
        $this->pdo->exec($query);

        $queryTrigger = "
            CREATE TRIGGER IF NOT EXISTS update_orders_updated_at
            AFTER UPDATE ON orders
            FOR EACH ROW
            BEGIN
                UPDATE orders SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
            END;
        ";
        $this->pdo->exec($queryTrigger);
    }

    public function getConnection() {
        return $this->pdo;
    }
}

$db = new Database();
$pdo = $db->getConnection();