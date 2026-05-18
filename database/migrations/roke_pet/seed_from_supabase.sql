-- ============================================================
-- SEED: Migración de datos de Supabase → MySQL (roke_pet)
-- Actualizado: 2026-05-18  |  Fuente: matmksqmknwzyvoqvrnw
--
-- ╔══════════════════════════════════════════════════════════╗
-- ║  EJECUTAR EN DOS PASOS SEPARADOS                        ║
-- ╠══════════════════════════════════════════════════════════╣
-- ║  PASO 1 — PARTE 1 (líneas ~30-75):                     ║
-- ║    mysql -u root -p <nombre_db_principal>               ║
-- ║    (usuario con acceso a la DB principal del hosting)   ║
-- ║                                                          ║
-- ║  PASO 2 — PARTE 2 (líneas ~80-fin):                    ║
-- ║    mysql -u roke_pet_prod -p roke_pet                   ║
-- ║    (usuario con acceso solo a la DB roke_pet)           ║
-- ╚══════════════════════════════════════════════════════════╝
--
-- NOTA SOBRE CONTRASEÑAS:
--   Los usuarios se crean con contraseña placeholder inválida.
--   Deben usar "Olvidé mi contraseña" o Google OAuth para acceder.
-- ============================================================

-- ============================================================
-- PARTE 1: DB principal — tabla users
-- Ejecutar como: mysql -u root -p <nombre_db_principal>
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

-- ============================================================
-- FIN PARTE 1
-- ============================================================

-- ============================================================
-- PARTE 2: DB roke_pet
-- Ejecutar como: mysql -u roke_pet_prod -p roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
USE roke_pet;

-- ── owners ──────────────────────────────────────────────────────
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

-- ── pets ────────────────────────────────────────────────────────
-- Columnas JSON (9): allergies, allergies_en, allergy_profiles,
--   conditions, conditions_en, active_treatments, active_treatments_en,
--   current_medications, current_medications_en
-- Columnas texto: special_care, special_care_en,
--   primary_vet_name, primary_vet_phone, primary_vet_clinic
INSERT IGNORE INTO pets
  (id, owner_id, slug, name, species, breed, breed_en,
   gender, birth_date, color, color_en, eye_color, eye_color_en,
   weight, sterilized, microchip_id, nfc_id, photo_url,
   story, story_en,
   traits, traits_en,
   allergies, allergies_en, allergy_profiles,
   conditions, conditions_en, active_treatments, active_treatments_en,
   current_medications, current_medications_en,
   special_care, special_care_en,
   primary_vet_name, primary_vet_phone, primary_vet_clinic,
   scanned_count, last_scan_location, public_profile_enabled,
   created_at, updated_at)
VALUES
  -- Deacon — Kevin aldair
  ('6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f',
   '16b62fc4-95a4-4a93-b924-b418fe877944',
   'deasdsa-i6j81', 'Deacon', 'cat',
   'Comun Europeo', 'Comun Europeo', 'male', '2024-04-14',
   'Taby', 'Taby', 'Verdes', 'Verdes',
   6.00, 1, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/16b62fc4-95a4-4a93-b924-b418fe877944/6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f.jpg?t=1776213410979',
   '', '',
   '["Juguetón","tranquilo","curioso"]', '["Juguetón","tranquilo","curioso"]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', '', '', '',
   11,
   '{"lat":56.4489566666667,"lng":9.95118666666667,"address":"Randers, Central Denmark Region","timestamp":"2026-04-20T17:31:17.118535+00:00"}',
   1, '2026-04-14 22:42:44', '2026-04-14 22:42:44'),

  -- Deacon — Kevin Martinez
  ('e942e89d-3844-42fd-8615-37775fcb54d6',
   '71fcf865-55df-4648-98f2-6f9b3279da07',
   'deacon-ft0tj', 'Deacon', 'cat',
   'Europe', 'Europe', 'male', NULL,
   '', '', '', '',
   0.00, 0, NULL, NULL, NULL,
   '', '',
   '[]', '[]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', '', '', '',
   2,
   '{"lat":56.448956666666675,"lng":9.951186666666667,"address":"Randers, Central Denmark Region","timestamp":"2026-04-17T17:00:44.626Z"}',
   1, '2026-04-15 02:58:42', '2026-04-15 02:58:42'),

  -- Michifus — Rocío
  ('bbed8435-21c5-45e2-bd2e-d4afe151b971',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'michifus-6i8qk', 'Michifus', 'cat',
   'Siames', 'Siames', 'female', '2022-07-24',
   'Cafe y blanco', 'Cafe y blanco', 'Azul', 'Azul',
   3.00, 1, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/4de155f0-36f1-40a8-9cbe-1d1e5dde0779/bbed8435-21c5-45e2-bd2e-d4afe151b971.jpg?t=1777222123763',
   'Gatita siames, con 3 patitas blancas', 'Gatita siames, con 3 patitas blancas',
   '["Curiosa"]', '["Curiosa"]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', 'Huellitas felices', '', '',
   0, NULL, 1, '2026-04-26 16:46:29', '2026-04-26 16:46:29'),

  -- Tommy — Rocío
  ('77a29b20-66c1-45bc-a003-355e97eda08f',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'tommy-hr2h4', 'Tommy', 'cat',
   'Tuxedo', 'Tuxedo', 'male', '2026-01-19',
   'Blanco y negro', 'Blanco y negro', 'Verde', 'Verde',
   1.00, 0, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/4de155f0-36f1-40a8-9cbe-1d1e5dde0779/77a29b20-66c1-45bc-a003-355e97eda08f.jpg?t=1777222490557',
   'Gatito rescatado', 'Gatito rescatado',
   '["Juguetón"]', '["Juguetón"]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', '', '', '',
   0, NULL, 1, '2026-04-26 16:54:29', '2026-04-26 16:54:29'),

  -- Maki — Rocío
  ('2e72498f-cbca-4882-a734-ffd1215bdb02',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'maki-aak1k', 'Maki', 'cat',
   'Mestiza', 'Mestiza', 'female', '2024-12-09',
   'Blanco', 'Blanco', 'Azul', 'Azul',
   3.00, 1, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/4de155f0-36f1-40a8-9cbe-1d1e5dde0779/2e72498f-cbca-4882-a734-ffd1215bdb02.jpg?t=1777222845885',
   'Gatita blanca, rescatada, un parto', 'Gatita blanca, rescatada, un parto',
   '["Loca","Nerviosa"]', '["Loca","Nerviosa"]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', '', '', '',
   0, NULL, 1, '2026-04-26 16:59:08', '2026-04-26 16:59:08'),

  -- Pedro — Mareli
  ('52ee2d7a-41fd-49f2-bcf4-830da8a8cefd',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   'pedro-82q20', 'Pedro', 'cat',
   '', '', 'male', NULL,
   '', '', '', '',
   0.00, 0, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/44486ea0-b0b0-4dca-9013-c247a9a03de6/52ee2d7a-41fd-49f2-bcf4-830da8a8cefd.jpg?t=1777266966377',
   '', '',
   '[]', '[]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', '', '', '',
   0, NULL, 1, '2026-04-27 05:11:57', '2026-04-27 05:11:57'),

  -- Deacon — Rocío
  ('000c4f8a-9470-4486-b8e3-6e43e5eff165',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'deacon-oqiqe', 'Deacon', 'cat',
   'Común europeo', 'Común europeo', 'male', '2024-07-03',
   'Tabi', 'Tabi', 'Verdes', 'Verdes',
   6.00, 1, NULL, NULL,
   'https://matmksqmknwzyvoqvrnw.supabase.co/storage/v1/object/public/pet-photos/4de155f0-36f1-40a8-9cbe-1d1e5dde0779/000c4f8a-9470-4486-b8e3-6e43e5eff165.jpg?t=1778949128267',
   '', '',
   '["Tímido","tranquilo","adorable."]', '["Tímido","tranquilo","adorable."]',
   '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]',
   '', '', 'Brenda Cecilia Leyna', '742106150', 'Huellita Feliz',
   0, NULL, 1, '2026-05-16 16:31:43', '2026-05-16 16:31:43');

-- ── vaccines ─────────────────────────────────────────────────────
-- Supabase: applied_date → MySQL: date
-- updated_at = created_at (Supabase no tiene updated_at en esta tabla)
INSERT IGNORE INTO vaccines
  (id, pet_id, name, name_en, date, next_due,
   applied_by, batch_number, status, created_at, updated_at)
VALUES
  -- Deacon (Kevin aldair) — Tripe Felina
  ('d867dadc-4526-43ac-a687-0016514b087d',
   '6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f',
   'Tripe Felina', 'Tripe Felina',
   '2025-04-14', '2026-04-14', '', '', 'applied',
   '2026-04-15 00:38:36', '2026-04-15 00:38:36'),

  -- Deacon (Kevin aldair) — Rabia
  ('baf45614-ee51-4e89-986f-2d50af5878a5',
   '6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f',
   'Rabia', 'Rabia',
   '2025-04-14', '2026-04-14', '', '', 'applied',
   '2026-04-15 00:39:16', '2026-04-15 00:39:16'),

  -- Michifus — Rabia
  ('76b28112-4fad-43d3-9635-9db4ed9418e3',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'Rabia', 'Rabia',
   '2025-05-26', '2026-05-26', '', '', 'applied',
   '2026-05-07 04:04:25', '2026-05-07 04:04:25'),

  -- Maki — Rabia (pending)
  ('0e41bf03-d90a-4b2c-9ab4-423bfea03690',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Rabia', 'Rabia',
   '2026-05-15', NULL, '', '', 'pending',
   '2026-05-07 04:09:55', '2026-05-07 04:09:55'),

  -- Tommy — Rabia (pending)
  ('35fc8a82-e284-4892-a6c0-18c1cb373584',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   'Rabia', 'Rabia',
   '2026-05-30', NULL, '', '', 'pending',
   '2026-05-07 04:13:13', '2026-05-07 04:13:13'),

  -- Tommy — Leucemia felina (pending)
  ('8f07b184-8f7c-4fcf-81e6-2be11a95da72',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   'Leucemia felina', 'Leucemia felina',
   '2026-05-30', NULL, '', '', 'pending',
   '2026-05-07 04:13:54', '2026-05-07 04:13:54'),

  -- Michifus — Leucemia
  ('2d9f97cc-32a8-4006-a103-e5308bef77dc',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'Leucemia', 'Leucemia',
   '2025-05-05', '2026-05-05', '', '', 'applied',
   '2026-05-07 16:41:40', '2026-05-07 16:41:40'),

  -- Deacon (Rocío) — Triple felina con rabia
  ('86ce4dee-0463-4344-881a-5cf3aa76b4bf',
   '000c4f8a-9470-4486-b8e3-6e43e5eff165',
   'Triple felina con rabia', 'Triple felina con rabia',
   '2025-05-03', '2026-05-03', '', '', 'applied',
   '2026-05-16 16:36:14', '2026-05-16 16:36:14'),

  -- Michifus — TRIPLE FELINA (Dra. Zarco) ← nuevo
  ('1f928622-7552-4928-a24d-3fae9a9f78f9',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'Triple Felina', '',
   NULL, '2027-05-16', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 18:25:46', '2026-05-16 18:25:46'),

  -- Deacon (Rocío) — Triple felina (Dra. Zarco) ← nuevo
  ('ceb6ba4e-4bfd-48c6-b5e5-bdc57b4d3d22',
   '000c4f8a-9470-4486-b8e3-6e43e5eff165',
   'Triple felina', '',
   NULL, '2027-05-16', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 18:29:23', '2026-05-16 18:29:23'),

  -- Maki — Triple Felina (Dra. Zarco) ← nuevo
  ('33c11cf3-f40f-4eb0-b0f6-2b030a007b88',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Triple Felina', '',
   NULL, '2026-06-06', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 20:03:02', '2026-05-16 20:03:02'),

  -- Maki — Triple felina (pending) ← nuevo
  ('9569ac42-e35d-4bb8-9154-f11b2e1c1a46',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Triple felina', 'Triple felina',
   '2026-06-06', NULL, '', '', 'pending',
   '2026-05-16 22:36:35', '2026-05-16 22:36:35'),

  -- Maki — Desparasitación ← nuevo
  ('08a12236-631b-4b3a-b84d-4cdc3adc663b',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Desparasitación', 'Desparasitación',
   '2026-05-11', '2026-06-11', '', '', 'applied',
   '2026-05-17 19:18:32', '2026-05-17 19:18:32');

-- ── medical_records ───────────────────────────────────────────────
-- Supabase: record_date → MySQL: date
-- updated_at = created_at (Supabase no tiene updated_at en esta tabla)
INSERT IGNORE INTO medical_records
  (id, pet_id, date, follow_up_date, type,
   description, description_en, vet, clinic, notes,
   created_at, updated_at)
VALUES
  ('6cb7410d-833e-4b18-a83e-6ee97bf4976b',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-03-12', NULL, 'checkup',
   'Esterilización', 'Esterilización', 'Huellitas felices', '', '',
   '2026-04-26 17:03:24', '2026-04-26 17:03:24'),

  ('2537e05c-5243-43e2-8b7d-90f9b3bf9bdf',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-05-09', NULL, 'deworming',
   'Desparasitación', 'Desparasitación', 'Huellitas felices', '', '',
   '2026-04-26 17:09:03', '2026-04-26 17:09:03'),

  ('31ac67ed-bb93-4273-881e-14e4c6f926a2',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   '2026-05-09', NULL, 'deworming',
   'Desparasitación', 'Desparasitación', 'Huellitas felices', '', '',
   '2026-04-26 17:09:57', '2026-04-26 17:09:57'),

  ('30a1021c-1b4d-493e-9b0d-c5c283568b25',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-03-09', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', 'Huellitas felices', '', '',
   '2026-04-26 17:47:23', '2026-04-26 17:47:23'),

  ('0c4ac73b-ff53-43c6-b1a0-86dacd43e273',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   '2026-06-20', NULL, 'surgery',
   'Castración', 'Castración', 'Huellitas Felices', '', '',
   '2026-04-26 17:49:18', '2026-04-26 17:49:18'),

  ('a9ad9c59-48b3-4936-b8a3-36337c1916d6',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   '2026-05-11', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', 'Huellitas felices', '', '',
   '2026-05-07 04:08:25', '2026-05-07 04:08:25'),

  ('96d8ec0e-6262-494b-a25e-36738f996f24',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-05-11', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', '', '', '',
   '2026-05-07 04:11:09', '2026-05-07 04:11:09');

-- ── owner_subscriptions ───────────────────────────────────────────
INSERT IGNORE INTO owner_subscriptions
  (id, owner_id, plan_code, status, provider,
   checkout_url, billing_email, trial_ends_at,
   current_period_end, support_notes,
   created_at, updated_at)
VALUES
  ('1e07e0c8-2d50-4aea-a10c-d3b99b7341b8',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'starter', 'trialing', 'stripe_payment_link',
   NULL, 'data.rociosalazar@gmail.com', '2026-05-10 16:45:34',
   NULL, NULL,
   '2026-04-26 16:45:34', '2026-04-26 16:45:34'),

  ('5788307a-4814-4e0a-be5e-a0beef63fe6e',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   'starter', 'trialing', 'stripe_payment_link',
   NULL, 'marelisalazar52@gmail.com', '2026-05-11 05:11:00',
   NULL, NULL,
   '2026-04-27 05:11:00', '2026-04-27 05:11:00');

-- ── reminder_settings ─────────────────────────────────────────────
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

-- ── weight_history ────────────────────────────────────────────────
INSERT IGNORE INTO weight_history
  (id, pet_id, weight, recorded_at, notes, created_at)
VALUES
  ('1c17b4d4-1690-4bc1-972b-c7760969e6fc',
   'e942e89d-3844-42fd-8615-37775fcb54d6',
   10.00, '2026-04-15', '',
   '2026-04-15 06:26:19');

-- ── activation_events ─────────────────────────────────────────────
INSERT IGNORE INTO activation_events
  (id, owner_id, pet_id, event_type, source, metadata, occurred_at)
VALUES
  ('ffce3528-2d82-4232-9428-a2598edd401e',
   '16b62fc4-95a4-4a93-b924-b418fe877944',
   '6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f',
   'pet_scan_recorded', 'nfc',
   '{"count":11,"address":"Randers, Central Denmark Region"}',
   '2026-04-20 17:31:17'),

  ('1a8a8700-bd64-49b1-ac32-8b5e050d380d',
   '16b62fc4-95a4-4a93-b924-b418fe877944',
   '6d1d3d1f-e4cb-49c3-bd9c-1bf8252a506f',
   'pet_scan_recorded', 'nfc',
   '{"count":11,"address":"Randers, Central Denmark Region"}',
   '2026-04-20 17:31:17'),

  ('aad46c91-2ea9-4208-9ed5-207ecb8f0400',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   NULL, 'owner_registered', 'system',
   '{"email":"data.rociosalazar@gmail.com"}',
   '2026-04-26 16:45:34'),

  ('8f98334b-8a13-41eb-a4a7-db87aedae4a6',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'pet_created', 'dashboard',
   '{"slug":"michifus-6i8qk"}',
   '2026-04-26 16:46:29'),

  ('db5c1739-db94-4625-89e5-8e99b622d51d',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'first_pet_created', 'dashboard', '{}',
   '2026-04-26 16:46:29'),

  ('16724f83-e2d8-49da-aa4d-f02f8d626c68',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'public_profile_enabled', 'dashboard', '{}',
   '2026-04-26 16:46:29'),

  ('3abcea84-8102-451f-9db4-d309db4137fb',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   'pet_created', 'dashboard',
   '{"slug":"tommy-hr2h4"}',
   '2026-04-26 16:54:29'),

  ('c0db9af6-4c86-4fd7-9120-eb3a4ac3329e',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   'public_profile_enabled', 'dashboard', '{}',
   '2026-04-26 16:54:29'),

  ('535ee8e9-20ac-4efa-be41-2ad9e95d8109',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'pet_created', 'dashboard',
   '{"slug":"maki-aak1k"}',
   '2026-04-26 16:59:08'),

  ('e975260a-fc81-4830-8f49-0e4742bd01df',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'public_profile_enabled', 'dashboard', '{}',
   '2026-04-26 16:59:08'),

  ('59a7329a-3bca-4dea-bf45-9cbdf0420e53',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   NULL, 'owner_registered', 'system',
   '{"email":"marelisalazar52@gmail.com"}',
   '2026-04-27 05:11:00'),

  ('e59e0fac-aa95-40dd-932f-832045455888',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   '52ee2d7a-41fd-49f2-bcf4-830da8a8cefd',
   'pet_created', 'dashboard',
   '{"slug":"pedro-82q20"}',
   '2026-04-27 05:11:57'),

  ('fe61f130-2023-4004-9f2d-f40ae9b380c8',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   '52ee2d7a-41fd-49f2-bcf4-830da8a8cefd',
   'first_pet_created', 'dashboard', '{}',
   '2026-04-27 05:11:57'),

  ('56169bd4-32d7-4644-8cb7-3443cb11daeb',
   '44486ea0-b0b0-4dca-9013-c247a9a03de6',
   '52ee2d7a-41fd-49f2-bcf4-830da8a8cefd',
   'public_profile_enabled', 'dashboard', '{}',
   '2026-04-27 05:11:57'),

  ('565b844d-4927-4256-90f1-583547585185',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '000c4f8a-9470-4486-b8e3-6e43e5eff165',
   'pet_created', 'dashboard',
   '{"slug":"deacon-oqiqe"}',
   '2026-05-16 16:31:43'),

  ('a4cb3859-8e7f-4b64-a49d-1d7e59582e0e',
   '4de155f0-36f1-40a8-9cbe-1d1e5dde0779',
   '000c4f8a-9470-4486-b8e3-6e43e5eff165',
   'public_profile_enabled', 'dashboard', '{}',
   '2026-05-16 16:31:43');

-- ── Restaurar ────────────────────────────────────────────────────
SET foreign_key_checks = 1;

-- ============================================================
-- FIN DEL SEED
-- ============================================================
-- RESUMEN DE REGISTROS:
--   users            : 5  (4 con owner + 1 solo auth)
--   owners           : 4
--   pets             : 7
--   vaccines         : 13
--   medical_records  : 7
--   owner_subscriptions : 2
--   reminder_settings   : 2
--   weight_history   : 1
--   activation_events: 16
--
-- NOTA SOBRE FOTOS:
--   Las URLs apuntan a Supabase Storage. Después de la migración,
--   descargar y re-subir al storage propio y actualizar photo_url.
--
-- NOTA SOBRE CONTRASEÑAS:
--   Los 5 usuarios tienen contraseña placeholder inválida.
--   a) "Olvidé mi contraseña" → email de reset
--   b) Google OAuth → el sistema los vincula por email
-- ============================================================
