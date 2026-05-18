-- ============================================================
-- 01 — users
-- DB: hosting_platform (DB principal del hosting)
-- Ejecutar conectado a la DB principal, NO a roke_pet
--
-- Columnas relevantes de la tabla users:
--   uuid, email, email_verified_at, password,
--   first_name, last_name, role, status, created_at, updated_at
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT IGNORE INTO users
  (uuid, email, email_verified_at, password,
   first_name, last_name, role, status,
   created_at, updated_at)
VALUES
  -- Kevin aldair (kmartinez@ixaya.com)
  ('16b62fc4-95a4-4a93-b924-b418fe877944',
   'kmartinez@ixaya.com', '2026-04-14 22:42:44',
   '$2y$12$SUPABASE_MIGRATED_RESET_REQUIRED_xxxxxxxxxxxxxxxxxxx',
   'Kevin', 'aldair', 'client', 'active',
   '2026-04-14 22:42:44', '2026-04-14 22:42:44'),

  -- kmartinez2 (kmartinez2@ixaya.com) — cuenta auth sin perfil owner
  ('3d99fa0a-22c0-4718-856b-ef157d2da52c',
   'kmartinez2@ixaya.com', '2026-04-13 05:12:56',
   '$2y$12$SUPABASE_MIGRATED_RESET_REQUIRED_xxxxxxxxxxxxxxxxxxx',
   'Kevin', 'Martinez 2', 'client', 'active',
   '2026-04-13 05:12:56', '2026-04-13 05:12:56'),

  -- Kevin Martinez (ridivi.test@ixaya.com)
  ('71fcf865-55df-4648-98f2-6f9b3279da07',
   'ridivi.test@ixaya.com', '2026-04-15 02:18:30',
   '$2y$12$SUPABASE_MIGRATED_RESET_REQUIRED_xxxxxxxxxxxxxxxxxxx',
   'Kevin', 'Martinez', 'client', 'active',
   '2026-04-15 02:18:30', '2026-04-15 02:18:30'),

  -- Rocío Salazar Parra (data.rociosalazar@gmail.com)
  ('4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'data.rociosalazar@gmail.com', '2026-04-26 16:45:34',
   '$2y$12$SUPABASE_MIGRATED_RESET_REQUIRED_xxxxxxxxxxxxxxxxxxx',
   'Rocío', 'Salazar Parra', 'client', 'active',
   '2026-04-26 16:45:34', '2026-04-26 16:45:34'),

  -- Mareli (marelisalazar52@gmail.com)
  ('44486ea0-b0b0-4dca-9013-c247a9a03de6',
   'marelisalazar52@gmail.com', '2026-04-27 05:11:00',
   '$2y$12$SUPABASE_MIGRATED_RESET_REQUIRED_xxxxxxxxxxxxxxxxxxx',
   'Mareli', '', 'client', 'active',
   '2026-04-27 05:11:00', '2026-04-27 05:11:00');
