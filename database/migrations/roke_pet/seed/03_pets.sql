-- ============================================================
-- 03 — pets
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

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

SET foreign_key_checks = 1;
