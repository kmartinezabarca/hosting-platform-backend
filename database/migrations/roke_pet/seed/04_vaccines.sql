-- ============================================================
-- 04 — vaccines  (13 registros)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

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

  -- Michifus — Triple Felina (Dra. Zarco)
  ('1f928622-7552-4928-a24d-3fae9a9f78f9',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   'Triple Felina', '',
   NULL, '2027-05-16', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 18:25:46', '2026-05-16 18:25:46'),

  -- Deacon (Rocío) — Triple felina (Dra. Zarco)
  ('ceb6ba4e-4bfd-48c6-b5e5-bdc57b4d3d22',
   '000c4f8a-9470-4486-b8e3-6e43e5eff165',
   'Triple felina', '',
   NULL, '2027-05-16', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 18:29:23', '2026-05-16 18:29:23'),

  -- Maki — Triple Felina (Dra. Zarco)
  ('33c11cf3-f40f-4eb0-b0f6-2b030a007b88',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Triple Felina', '',
   NULL, '2026-06-06', 'Dra. Brenda Cecilia Zarco Lezma', '', 'applied',
   '2026-05-16 20:03:02', '2026-05-16 20:03:02'),

  -- Maki — Triple felina (pending)
  ('9569ac42-e35d-4bb8-9154-f11b2e1c1a46',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Triple felina', 'Triple felina',
   '2026-06-06', NULL, '', '', 'pending',
   '2026-05-16 22:36:35', '2026-05-16 22:36:35'),

  -- Maki — Desparasitación
  ('08a12236-631b-4b3a-b84d-4cdc3adc663b',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   'Desparasitación', 'Desparasitación',
   '2026-05-11', '2026-06-11', '', '', 'applied',
   '2026-05-17 19:18:32', '2026-05-17 19:18:32');

SET foreign_key_checks = 1;
