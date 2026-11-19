-- Brille Amour upgrade: order workflow + staff task assignment
-- Safe to run on an existing database before opening the admin/staff pages.

ALTER TABLE orders
 ADD COLUMN IF NOT EXISTS task_title VARCHAR(160) NULL AFTER assigned_to,
 ADD COLUMN IF NOT EXISTS task_instructions TEXT NULL AFTER task_title,
 ADD COLUMN IF NOT EXISTS task_deadline DATETIME NULL AFTER task_instructions,
 ADD COLUMN IF NOT EXISTS task_status ENUM('not_started','in_progress','done','on_hold') NOT NULL DEFAULT 'not_started' AFTER task_deadline,
 ADD COLUMN IF NOT EXISTS assigned_by INT NULL AFTER task_status,
 ADD COLUMN IF NOT EXISTS assigned_at DATETIME NULL AFTER assigned_by;

ALTER TABLE orders
 ADD INDEX IF NOT EXISTS idx_orders_task_status (task_status),
 ADD INDEX IF NOT EXISTS idx_orders_task_deadline (task_deadline);
