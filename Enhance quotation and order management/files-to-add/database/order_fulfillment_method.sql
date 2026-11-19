-- Brille Amour customer checkout: pickup / delivery fulfillment method
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS fulfillment_method ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup' AFTER pickup_time,
  ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL AFTER fulfillment_method;
