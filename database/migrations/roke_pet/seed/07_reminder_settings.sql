-- ============================================================
-- 07 — reminder_settings  (2 registros)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO reminder_settings
  (id, owner_id, enabled, email_notifications, reminder_days,
   vaccine_reminders, deworming_reminders, checkup_reminders,
   created_at, updated_at)
VALUES
  ('a70016e0-a678-47c4-bdc6-80128ce54151',
   '71fcf865-55df-4648-98f2-6f9b3279da07',
   1, 1, '[30,7,1]', 1, 1, 1,
   '2026-04-15 16:26:57', '2026-04-15 16:26:57'),

  ('ade55ec5-eeab-443c-aa8e-bad5e1a4b95a',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   1, 1, '[14,7,1]', 1, 1, 1,
   '2026-05-07 16:42:11', '2026-05-07 16:42:11');
