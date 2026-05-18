-- ============================================================
-- 09 — activation_events  (16 registros)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

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

SET foreign_key_checks = 1;
