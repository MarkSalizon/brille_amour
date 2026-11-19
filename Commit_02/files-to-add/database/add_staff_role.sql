-- Adds the production role column used by the staff Production Board.
-- Import this once in phpMyAdmin (database: brille_amour).
-- The app also self-heals via ensureStaffRoleColumn() in includes/staff_roles.php,
-- so this file is mainly for a clean, explicit migration.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `staff_role`
  ENUM('production','quality_check') DEFAULT NULL AFTER `role`;

-- Optional: give the three demo staff a role each so the board is usable
-- straight away. Adjust the usernames to match your own accounts.
-- UPDATE `users` SET `staff_role` = 'production'    WHERE `username` = 'staff_production'  AND `role` = 'staff';
-- UPDATE `users` SET `staff_role` = 'quality_check' WHERE `username` = 'staff_quality'     AND `role` = 'staff';
