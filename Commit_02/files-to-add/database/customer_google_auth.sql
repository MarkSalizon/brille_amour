-- Brille Amour customer Google sign-in support.
ALTER TABLE customers ADD COLUMN IF NOT EXISTS google_sub VARCHAR(255) DEFAULT NULL AFTER email;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS google_avatar VARCHAR(500) DEFAULT NULL AFTER google_sub;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS google_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER google_avatar;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL AFTER google_verified;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_id INT(11) DEFAULT NULL AFTER customer_contact;

CREATE INDEX IF NOT EXISTS idx_customers_google_sub ON customers (google_sub);
CREATE INDEX IF NOT EXISTS idx_customers_email ON customers (email);
CREATE INDEX IF NOT EXISTS idx_orders_customer_id ON orders (customer_id);
