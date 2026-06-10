# APP_KEY Rotation Runbook

**Why:** the current `APP_KEY` was committed to git history (commit `e9d7a4f`, removed in `8c1c934`). Anyone with repo access at that time could decrypt cookies, `services.connection_secrets`, and `users.two_factor_secret`. Rotate it before launch.

## What depends on APP_KEY

| Data | Storage | Effect of rotation |
|---|---|---|
| `services.connection_secrets` | encrypted column (DB passwords for hosting) | must be re-encrypted (this runbook) |
| `users.two_factor_secret` | encrypted column (TOTP seeds) | must be re-encrypted (this runbook) |
| Cookies / sessions | encrypted at the edge | invalidated — users must log in again (acceptable) |
| Signed URLs | HMAC with APP_KEY | outstanding links invalidate (acceptable) |
| Password hashes, Sanctum tokens | bcrypt/sha256 — **not** APP_KEY | unaffected |

> Historical note: `connection_secrets` has had three storage formats (plain JSON → `encrypt(json)` → `encryptString(json)`). Both the model accessor and the rotation command tolerate all three; after rotation everything is normalized to the canonical `encryptString(json)`.

## Procedure (no downtime required except a deploy/restart)

1. **Generate the new key** (do NOT change `.env` yet):
   ```bash
   php artisan key:generate --show
   # → base64:NEWKEY...
   ```
2. **Database backup** (or rely on your regular snapshot — verify it exists):
   ```bash
   mysqldump ... services users > pre-rotation-backup.sql
   ```
3. **Dry run** (counts what would rotate; mutates nothing):
   ```bash
   OLD_APP_KEY="base64:OLD..." NEW_APP_KEY="base64:NEW..." \
     php artisan security:rotate-app-key --dry-run
   ```
   On Windows PowerShell:
   ```powershell
   $env:OLD_APP_KEY='base64:OLD...'; $env:NEW_APP_KEY='base64:NEW...'
   php artisan security:rotate-app-key --dry-run
   ```
4. **Rotate with backup of the original encrypted payloads** (backup contains only ciphertext encrypted with the old key — no plaintext):
   ```bash
   OLD_APP_KEY="base64:OLD..." NEW_APP_KEY="base64:NEW..." \
     php artisan security:rotate-app-key --backup=storage/app/key-rotation-backup.json
   ```
   The command verifies every row round-trips with the new key and aborts on the first mismatch.
5. **Switch the key**: set `APP_KEY=base64:NEW...` in the environment (`.env` / secret manager).
6. **Restart everything** that caches config: PHP-FPM, `php artisan queue:restart`, scheduler workers, Reverb. Then `php artisan config:clear` (or re-cache).
7. **Verify**:
   - Log in (sessions were invalidated — expected).
   - A user with 2FA can pass the TOTP check.
   - A hosting service's database panel still shows credentials (`connection_secrets` readable).
8. **Clean up**: delete `storage/app/key-rotation-backup.json` once verified, remove `OLD_APP_KEY`/`NEW_APP_KEY` from the environment, and ensure the old key is no longer referenced anywhere.

## Rollback

If something fails before step 5, nothing user-visible changed (data is re-encrypted but the active APP_KEY hasn't moved — note rows already rotated are unreadable by the old key; restore them from `key-rotation-backup.json` or the SQL backup).

If something fails after step 5, either restore the SQL backup and revert `APP_KEY`, or re-run the command with the keys swapped (`OLD_APP_KEY`=new, `NEW_APP_KEY`=old).

## Notes

- The command **refuses to run** without both `OLD_APP_KEY` and `NEW_APP_KEY` set, and never logs or prints decrypted values.
- Unreadable rows (corrupt/foreign-key payloads) are skipped and reported — investigate them manually; they were already unreadable before rotation.
- Run during a low-traffic window: a `connection_secrets` write between step 4 and step 5 would be encrypted with the *old* key by the running app. Re-run the command (it tolerates mixed states) right after switching the key if in doubt.
