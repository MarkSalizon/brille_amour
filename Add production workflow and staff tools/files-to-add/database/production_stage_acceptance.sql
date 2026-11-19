-- Optional manual migration. The application creates this table on first use.
CREATE TABLE IF NOT EXISTS production_stage_acceptance (
  order_id INT NOT NULL,
  stage VARCHAR(32) NOT NULL,
  decision ENUM('accepted','declined') NOT NULL,
  staff_id INT NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id, stage),
  INDEX idx_stage_decision (stage, decision),
  INDEX idx_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
