# CI Testing & Coverage

Este backend usa PHPUnit como gate de calidad para Jenkins. El pipeline debe ejecutar
tests antes de cualquier deploy y publicar:

- `build/logs/junit.xml` para resultados de pruebas.
- `build/coverage/clover.xml` para validacion de cobertura.
- `build/coverage/cobertura.xml` para integraciones de coverage.

## Requisitos del agente Jenkins

- PHP con Xdebug o PCOV habilitado para coverage.
- Composer.
- Acceso a una base MySQL aislada de pruebas.
- Usuario MySQL con permisos completos sobre la base de pruebas.
- En `APP_ENV=testing`, la conexion secundaria `roke_pet` usa la misma base
  aislada definida por `DB_DATABASE`, para que `migrate:fresh` no toque la DB
  real de mascotas.

Ejemplo de permisos:

```sql
CREATE DATABASE IF NOT EXISTS hosting_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON hosting_platform_test.* TO 'roke_laravel'@'%';
FLUSH PRIVILEGES;
```

## Comando CI

```bash
mkdir -p build/logs build/coverage
XDEBUG_MODE=coverage ./vendor/bin/phpunit \
  --log-junit build/logs/junit.xml \
  --coverage-clover build/coverage/clover.xml \
  --coverage-cobertura build/coverage/cobertura.xml
php scripts/ci/check-coverage.php build/coverage/clover.xml 35
```

## Politica

- Si falla un test, no hay deploy.
- Si la cobertura queda debajo de `COVERAGE_MIN`, no hay deploy.
- Produccion vuelve a ejecutar `composer install --no-dev` dentro del release antes del switch atomico.
