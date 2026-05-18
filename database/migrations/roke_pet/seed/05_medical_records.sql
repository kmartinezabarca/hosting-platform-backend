-- ============================================================
-- 05 — medical_records  (7 registros)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

INSERT IGNORE INTO medical_records
  (id, pet_id, date, follow_up_date, type,
   description, description_en, vet, clinic, notes,
   created_at, updated_at)
VALUES
  -- Maki — Esterilización (checkup)
  ('6cb7410d-833e-4b18-a83e-6ee97bf4976b',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-03-12', NULL, 'checkup',
   'Esterilización', 'Esterilización', 'Huellitas felices', '', '',
   '2026-04-26 17:03:24', '2026-04-26 17:03:24'),

  -- Maki — Desparasitación (deworming)
  ('2537e05c-5243-43e2-8b7d-90f9b3bf9bdf',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-05-09', NULL, 'deworming',
   'Desparasitación', 'Desparasitación', 'Huellitas felices', '', '',
   '2026-04-26 17:09:03', '2026-04-26 17:09:03'),

  -- Tommy — Desparasitación (deworming)
  ('31ac67ed-bb93-4273-881e-14e4c6f926a2',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   '2026-05-09', NULL, 'deworming',
   'Desparasitación', 'Desparasitación', 'Huellitas felices', '', '',
   '2026-04-26 17:09:57', '2026-04-26 17:09:57'),

  -- Maki — Desparacitación (deworming)
  ('30a1021c-1b4d-493e-9b0d-c5c283568b25',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-03-09', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', 'Huellitas felices', '', '',
   '2026-04-26 17:47:23', '2026-04-26 17:47:23'),

  -- Tommy — Castración (surgery)
  ('0c4ac73b-ff53-43c6-b1a0-86dacd43e273',
   '77a29b20-66c1-45bc-a003-355e97eda08f',
   '2026-06-20', NULL, 'surgery',
   'Castración', 'Castración', 'Huellitas Felices', '', '',
   '2026-04-26 17:49:18', '2026-04-26 17:49:18'),

  -- Michifus — Desparacitación (deworming)
  ('a9ad9c59-48b3-4936-b8a3-36337c1916d6',
   'bbed8435-21c5-45e2-bd2e-d4afe151b971',
   '2026-05-11', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', 'Huellitas felices', '', '',
   '2026-05-07 04:08:25', '2026-05-07 04:08:25'),

  -- Maki — Desparacitación (deworming)
  ('96d8ec0e-6262-494b-a25e-36738f996f24',
   '2e72498f-cbca-4882-a734-ffd1215bdb02',
   '2026-05-11', NULL, 'deworming',
   'Desparacitación', 'Desparacitación', '', '', '',
   '2026-05-07 04:11:09', '2026-05-07 04:11:09');

SET foreign_key_checks = 1;
