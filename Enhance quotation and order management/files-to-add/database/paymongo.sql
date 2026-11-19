CREATE TABLE IF NOT EXISTS paymongo_checkouts (
 payment_id INT NOT NULL PRIMARY KEY,
 return_token CHAR(64) NOT NULL UNIQUE,
 session_id VARCHAR(100) NULL UNIQUE,
 checkout_url TEXT NULL,
 flow_type VARCHAR(20) NOT NULL DEFAULT 'hosted',
 payment_intent_id VARCHAR(100) NULL,
 client_key VARCHAR(255) NULL,
 payment_method_id VARCHAR(100) NULL,
 redirect_url TEXT NULL,
 state VARCHAR(30) NOT NULL DEFAULT 'new',
 livemode TINYINT(1) NOT NULL,
 paid_payment_id VARCHAR(100) NULL UNIQUE,
 last_checked_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_paymongo_payment_intent (payment_intent_id),
 FOREIGN KEY (payment_id) REFERENCES checkout_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
