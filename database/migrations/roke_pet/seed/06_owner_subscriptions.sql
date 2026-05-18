-- ============================================================
-- 06 — owner_subscriptions  (2 registros)
-- DB: roke_pet
-- ============================================================
SET NAMES utf8mb4;

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
