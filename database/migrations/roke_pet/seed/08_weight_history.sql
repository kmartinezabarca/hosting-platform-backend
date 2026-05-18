-- ============================================================
-- 08 — weight_history  (1 registro)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

INSERT IGNORE INTO weight_history
  (id, pet_id, weight, recorded_at, notes, created_at)
VALUES
  ('1c17b4d4-1690-4bc1-972b-c7760969e6fc',
   'e942e89d-3844-42fd-8615-37775fcb54d6',
   10.00, '2026-04-15', '',
   '2026-04-15 06:26:19');

SET foreign_key_checks = 1;
