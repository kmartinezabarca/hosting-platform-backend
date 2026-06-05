-- ============================================================
-- 02 — owners
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

INSERT IGNORE INTO owners
  (id, display_name, email, phone, address,
   emergency_contact, emergency_phone,
   public_email_visible, public_address_visible,
   created_at, updated_at)
VALUES
  ('16b62fc4-95a4-4a93-b924-b418fe877944',
   'Kevin aldair', 'kmartinez@ixaya.com', '7811095022',
   'San Jeronimo de juarez', '7811095022', '7811095022',
   0, 0, '2026-04-14 22:42:44', '2026-04-14 22:42:44'),

  ('71fcf865-55df-4648-98f2-6f9b3279da07',
   'Kevin Martinez', 'ridivi.test@ixaya.com', '7811095022',
   'San Jeronimo de Juarez', '', '',
   0, 0, '2026-04-15 02:18:30', '2026-04-15 02:18:30'),

  ('4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'Rocío Salazar Parra', 'data.rociosalazar@gmail.com', '5562297861',
   'San jerónimo de Juárez', 'Rocio', '5562297861',
   0, 0, '2026-04-26 16:45:34', '2026-04-26 16:45:34'),

  ('44486ea0-b0b0-4dca-9013-c247a9a03de6',
   'Mareli', 'marelisalazar52@gmail.com', '7443524382',
   'Acapulco de Juárez', '', '',
   0, 0, '2026-04-27 05:11:00', '2026-04-27 05:11:00');

SET foreign_key_checks = 1;
