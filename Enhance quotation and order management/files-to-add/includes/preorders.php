<?php
/** Custom quote requests are separate from paid orders and inventory reservations. */
function ensurePreorderSchema(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;
    $column=$conn->query("SHOW COLUMNS FROM products LIKE 'order_mode'");
    if ($column && !$column->num_rows) $conn->query("ALTER TABLE products ADD COLUMN order_mode ENUM('regular','preorder') NOT NULL DEFAULT 'regular'");
    $conn->query("CREATE TABLE IF NOT EXISTS preorder_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL, quantity INT NOT NULL, requested_size VARCHAR(120) NOT NULL DEFAULT '',
        details TEXT NOT NULL, reference_image VARCHAR(255) NULL,
        status ENUM('requested','discussing','quoted','accepted','confirmed','payment_started','cancelled') NOT NULL DEFAULT 'requested',
        quoted_total DECIMAL(10,2) NULL, quoted_details TEXT NULL, estimated_date DATE NULL,
        order_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (customer_id), INDEX(status), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS preorder_messages (
        id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, sender_role ENUM('customer','admin') NOT NULL,
        message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(request_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Only existing catalog products receive default modes. Newly added products are controlled by Admin.
    $conn->query("CREATE TABLE IF NOT EXISTS preorder_migrations (version VARCHAR(64) PRIMARY KEY)");
    $version='initial_preorder_modes_20260925';
    $stmt=$conn->prepare('SELECT version FROM preorder_migrations WHERE version=?');
    $stmt->bind_param('s',$version); $stmt->execute(); $exists=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$exists) {
        $conn->query("UPDATE products SET order_mode='preorder' WHERE LOWER(TRIM(name)) IN ('sintra board','invitations','acrylic keychains','ref magnets','button pins','bookmarks')");
        $stmt=$conn->prepare('INSERT IGNORE INTO preorder_migrations(version) VALUES(?)');
        $stmt->bind_param('s',$version); $stmt->execute(); $stmt->close();
    }
    // Retire the preorder workflow without deleting historical requests.
    $conn->query("UPDATE products SET order_mode='regular' WHERE order_mode='preorder'");
    $ready=true;
}
function preE($s):string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function preorderToken(): string { return bin2hex(random_bytes(16)); }
