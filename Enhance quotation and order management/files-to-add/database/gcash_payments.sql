CREATE TABLE IF NOT EXISTS checkout_payments (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 reference_number VARCHAR(30) NOT NULL UNIQUE,
 payment_method VARCHAR(80) NOT NULL DEFAULT 'GCash',
 payment_method_other VARCHAR(100) NULL,
 proof_image VARCHAR(255) NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 review_note VARCHAR(500) NOT NULL DEFAULT '',
 reviewed_by INT NULL,
 reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS checkout_payment_orders (
 payment_id INT NOT NULL,
 order_id INT NOT NULL UNIQUE,
 amount DECIMAL(10,2) NOT NULL,
 PRIMARY KEY (payment_id, order_id),
 FOREIGN KEY (payment_id) REFERENCES checkout_payments(id),
 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
