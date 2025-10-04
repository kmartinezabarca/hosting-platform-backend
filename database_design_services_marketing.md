# Diseño de la Base de Datos para Servicios de Marketing

Para externalizar los datos de los servicios mostrados en el `Services.jsx` del frontend, se propone una nueva tabla `marketing_services` que almacenará la información estática de los servicios ofrecidos en la landing page. Esta tabla es independiente de la tabla `services` existente, que gestiona los servicios contratados por los usuarios.

## 1. Estructura de la Tabla `marketing__services`

Se creará una nueva tabla llamada `marketing_services` con la siguiente estructura:

| Campo         | Tipo de Dato      | Restricciones         | Descripción                                                              |
| :------------ | :---------------- | :-------------------- | :----------------------------------------------------------------------- |
| `id`          | `BIGINT UNSIGNED` | `PRIMARY KEY`, `AUTO_INCREMENT` | Identificador único del servicio.                                        |
| `uuid`        | `UUID`            | `UNIQUE`              | Identificador universal único para referencia externa.                   |
| `type`        | `ENUM`            | `("main", "additional")` | Tipo de servicio (principal o adicional).                                |
| `icon_name`   | `VARCHAR(255)`    | `NOT NULL`            | Nombre del ícono de Lucide React (ej. "Server").                         |
| `title`       | `VARCHAR(255)`    | `NOT NULL`            | Título del servicio.                                                     |
| `slug`        | `VARCHAR(255)`    | `UNIQUE`, `NOT NULL`  | Slug amigable para URLs (ej. "hosting-web-gestionado").                  |
| `description` | `TEXT`            | `NOT NULL`            | Descripción detallada del servicio.                                      |
| `features`    | `JSON`            | `NULLABLE`            | Array de características (solo para servicios `main`).                   |
| `color`       | `VARCHAR(255)`    | `NULLABLE`            | Clase de Tailwind CSS para el color del ícono (ej. "text-blue-500").     |
| `bg_color`    | `VARCHAR(255)`    | `NULLABLE`            | Clase de Tailwind CSS para el color de fondo del ícono (ej. "bg-blue-500/10"). |
| `order`       | `INTEGER`         | `NOT NULL`, `DEFAULT 0` | Orden de visualización del servicio.                                     |
| `created_at`  | `TIMESTAMP`       | `NULLABLE`            | Marca de tiempo de creación.                                             |
| `updated_at`  | `TIMESTAMP`       | `NULLABLE`            | Marca de tiempo de última actualización.                                 |

## 2. Racionalización de la Nueva Tabla

- **Separación de Responsabilidades:** La tabla `services` existente está diseñada para gestionar instancias de servicios contratados por usuarios (con `user_id`, `plan_id`, `next_due_date`, etc.). Los datos de la landing page son información de marketing estática que describe los tipos de servicios que se ofrecen, no las instancias de servicio en sí. Una tabla separada evita la sobrecarga de la tabla `services` con campos irrelevantes para su propósito principal y mejora la claridad del modelo de datos.
- **Flexibilidad:** Permite añadir, modificar o eliminar servicios de marketing sin afectar la lógica de negocio de los servicios provisionados.
- **Escalabilidad:** Facilita la gestión de un catálogo de servicios de marketing que puede crecer independientemente de la complejidad de los servicios operativos.

## 3. Modelo Eloquent `MarketingService`

Se creará un nuevo modelo `MarketingService` en `app/Models/MarketingService.php` para interactuar con la tabla `marketing_services`.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketingService extends Model
{
    use HasFactory;

    protected $table = 'marketing_services';

    protected $fillable = [
        'uuid',
        'type',
        'icon_name',
        'title',
        'slug',
        'description',
        'features',
        'color',
        'bg_color',
        'order',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->title);
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
```

## 4. Endpoint de API

Se creará un nuevo endpoint `GET /api/marketing-services` que devolverá todos los servicios de marketing, ordenados por el campo `order`.

```json
[
  {
    "id": 1,
    "uuid": "uuid-del-servicio-1",
    "type": "main",
    "icon_name": "Server",
    "title": "Hosting Web Gestionado",
    "slug": "hosting-web-gestionado",
    "description": "Plataforma de hosting de alto rendimiento...",
    "features": [
      "Almacenamiento NVMe SSD",
      "Certificados SSL Gratuitos"
    ],
    "color": "text-blue-500",
    "bg_color": "bg-blue-500/10",
    "order": 1,
    "created_at": "2025-10-03T10:00:00.000000Z",
    "updated_at": "2025-10-03T10:00:00.000000Z"
  },
  // ... otros servicios
]
```

Este diseño asegura una clara separación de responsabilidades y una gestión eficiente de los datos de marketing.
