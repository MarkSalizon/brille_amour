-- Brille Amour upgrade: flexible payments + pickup date capacity
-- Safe to run on an existing database.

CREATE TABLE IF NOT EXISTS pickup_capacity (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pickup_date DATE NOT NULL UNIQUE,
 max_orders INT NOT NULL DEFAULT 20,
 is_closed TINYINT(1) NOT NULL DEFAULT 0,
 note VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_pickup_capacity_date (pickup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE checkout_payments
 ADD COLUMN IF NOT EXISTS payment_method VARCHAR(80) NOT NULL DEFAULT 'GCash' AFTER reference_number,
 ADD COLUMN IF NOT EXISTS payment_method_other VARCHAR(100) NULL AFTER payment_method;

INSERT INTO settings (setting_key, setting_value)
VALUES ('pickup_daily_capacity', '20')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
