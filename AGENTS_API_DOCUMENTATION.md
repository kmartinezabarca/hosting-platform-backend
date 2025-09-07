# API de Agentes - Documentación

## Descripción General

La API de agentes proporciona un sistema completo para gestionar agentes de soporte en el módulo administrativo. Permite crear, actualizar, eliminar y gestionar agentes con funcionalidades avanzadas como asignación automática de tickets, estadísticas de rendimiento y gestión de disponibilidad.

## Estructura de Base de Datos

### Tabla `agents`

La tabla de agentes extiende la funcionalidad de usuarios con campos específicos para agentes de soporte:

- **uuid**: Identificador único del agente
- **user_id**: Relación con la tabla users
- **agent_code**: Código único del agente (ej: AGT123456)
- **department**: Departamento (support, technical, billing, sales)
- **specialization**: Especialización (general, technical, billing, sales, escalation)
- **status**: Estado (active, inactive, busy, away)
- **max_concurrent_tickets**: Máximo de tickets concurrentes
- **current_ticket_count**: Tickets actuales asignados
- **performance_rating**: Rating de rendimiento (1.00 - 5.00)
- **total_tickets_resolved**: Total de tickets resueltos
- **average_response_time**: Tiempo promedio de respuesta (minutos)
- **average_resolution_time**: Tiempo promedio de resolución (minutos)
- **working_hours**: Horarios de trabajo (JSON)
- **skills**: Habilidades/competencias (JSON)
- **notes**: Notas administrativas
- **last_activity_at**: Última actividad

## Endpoints de la API

### Base URL
```
/admin/tickets/agents
```

### 1. Listar Agentes
```http
GET /admin/tickets/agents
```

**Parámetros de consulta:**
- `status` (string): Filtrar por estado (active, inactive, busy, away)
- `department` (string): Filtrar por departamento
- `specialization` (string): Filtrar por especialización
- `available` (boolean): Solo agentes disponibles (true/false)
- `search` (string): Buscar por nombre, email o código de agente
- `sort_by` (string): Campo para ordenar (created_at, performance_rating, current_ticket_count, total_tickets_resolved, last_activity_at)
- `sort_order` (string): Orden (asc, desc)
- `per_page` (integer): Elementos por página (máximo 100)

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": [
    {
      "uuid": "123e4567-e89b-12d3-a456-426614174000",
      "agent_code": "AGT123456",
      "department": "support",
      "specialization": "general",
      "status": "active",
      "max_concurrent_tickets": 10,
      "current_ticket_count": 3,
      "performance_rating": "4.85",
      "total_tickets_resolved": 127,
      "average_response_time": "15.30",
      "average_resolution_time": "120.45",
      "working_hours": {...},
      "skills": [...],
      "notes": "Agente experimentado",
      "last_activity_at": "2025-09-06T23:00:00.000000Z",
      "user": {
        "id": 1,
        "first_name": "Ana",
        "last_name": "García",
        "email": "ana.garcia@support.com",
        "avatar_url": null
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 67,
    "from": 1,
    "to": 15
  }
}
```

### 2. Crear Agente
```http
POST /admin/tickets/agents
```

**Cuerpo de la solicitud:**
```json
{
  "user_id": 1,
  "department": "support",
  "specialization": "general",
  "max_concurrent_tickets": 10,
  "working_hours": {
    "monday": {"start": "09:00", "end": "18:00"},
    "tuesday": {"start": "09:00", "end": "18:00"},
    "wednesday": {"start": "09:00", "end": "18:00"},
    "thursday": {"start": "09:00", "end": "18:00"},
    "friday": {"start": "09:00", "end": "18:00"}
  },
  "skills": ["Atención al cliente", "Comunicación", "Resolución de problemas"],
  "notes": "Nuevo agente de soporte general"
}
```

**Validaciones:**
- `user_id`: Requerido, debe existir en la tabla users
- `department`: Requerido, máximo 100 caracteres
- `specialization`: Requerido, valores válidos: general, technical, billing, sales, escalation
- `max_concurrent_tickets`: Entero entre 1 y 50
- `working_hours`: Array opcional
- `skills`: Array opcional
- `notes`: String opcional, máximo 1000 caracteres

### 3. Mostrar Agente Específico
```http
GET /admin/tickets/agents/{uuid}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": {
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "agent_code": "AGT123456",
    "department": "support",
    "specialization": "general",
    "status": "active",
    "max_concurrent_tickets": 10,
    "current_ticket_count": 3,
    "performance_rating": "4.85",
    "total_tickets_resolved": 127,
    "average_response_time": "15.30",
    "average_resolution_time": "120.45",
    "working_hours": {...},
    "skills": [...],
    "notes": "Agente experimentado",
    "last_activity_at": "2025-09-06T23:00:00.000000Z",
    "user": {
      "id": 1,
      "first_name": "Ana",
      "last_name": "García",
      "email": "ana.garcia@support.com",
      "avatar_url": null,
      "phone": "+1234567890",
      "company": "ROKE Industries"
    },
    "statistics": {
      "tickets_open": 2,
      "tickets_in_progress": 1,
      "tickets_resolved_this_month": 15,
      "average_rating": "4.85"
    }
  }
}
```

### 4. Actualizar Agente
```http
PUT /admin/tickets/agents/{uuid}
```

**Cuerpo de la solicitud:**
```json
{
  "department": "technical",
  "specialization": "technical",
  "status": "active",
  "max_concurrent_tickets": 12,
  "working_hours": {...},
  "skills": [...],
  "notes": "Agente promovido a soporte técnico"
}
```

### 5. Eliminar Agente
```http
DELETE /admin/tickets/agents/{uuid}
```

**Nota:** No se puede eliminar un agente que tenga tickets activos asignados.

### 6. Estadísticas de Agentes
```http
GET /admin/tickets/agents/statistics
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": {
    "total_agents": 25,
    "active_agents": 20,
    "busy_agents": 3,
    "away_agents": 1,
    "inactive_agents": 1,
    "available_agents": 17,
    "departments": [
      {"department": "support", "count": 10},
      {"department": "technical", "count": 8},
      {"department": "billing", "count": 4},
      {"department": "sales", "count": 3}
    ],
    "specializations": [
      {"specialization": "general", "count": 10},
      {"specialization": "technical", "count": 8},
      {"specialization": "billing", "count": 4},
      {"specialization": "sales", "count": 2},
      {"specialization": "escalation", "count": 1}
    ],
    "performance_metrics": {
      "average_rating": "4.65",
      "total_tickets_resolved": 2847,
      "average_response_time": "18.45",
      "average_resolution_time": "145.30"
    }
  }
}
```

### 7. Obtener Agente Recomendado
```http
GET /admin/tickets/agents/recommended
```

**Parámetros de consulta:**
- `department` (string): Departamento preferido
- `specialization` (string): Especialización preferida

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": {
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "agent_code": "AGT123456",
    "department": "support",
    "specialization": "general",
    "status": "active",
    "current_ticket_count": 2,
    "max_concurrent_tickets": 10,
    "performance_rating": "4.85",
    "user": {
      "id": 1,
      "first_name": "Ana",
      "last_name": "García",
      "email": "ana.garcia@support.com"
    }
  }
}
```

### 8. Asignar Ticket a Agente
```http
POST /admin/tickets/agents/{uuid}/assign-ticket
```

**Cuerpo de la solicitud:**
```json
{
  "ticket_id": 123
}
```

### 9. Obtener Tickets del Agente
```http
GET /admin/tickets/agents/{uuid}/tickets
```

**Parámetros de consulta:**
- `status` (string): Filtrar por estado del ticket
- `priority` (string): Filtrar por prioridad
- `sort_by` (string): Campo para ordenar
- `sort_order` (string): Orden (asc, desc)
- `per_page` (integer): Elementos por página

## Códigos de Estado HTTP

- **200**: Operación exitosa
- **201**: Recurso creado exitosamente
- **404**: Recurso no encontrado
- **422**: Error de validación
- **500**: Error interno del servidor

## Middleware y Autenticación

Todas las rutas están protegidas por:
- Middleware `auth:sanctum`: Requiere autenticación
- Middleware `admin`: Requiere rol de administrador

## Modelo de Datos

### Relaciones
- **Agent** pertenece a **User** (belongsTo)
- **Agent** tiene muchos **Tickets** (hasMany)

### Scopes Disponibles
- `active()`: Agentes activos
- `available()`: Agentes disponibles para nuevos tickets
- `byDepartment($department)`: Filtrar por departamento
- `bySpecialization($specialization)`: Filtrar por especialización

### Métodos Útiles
- `isAvailable()`: Verificar disponibilidad
- `incrementTicketCount()`: Incrementar contador de tickets
- `decrementTicketCount()`: Decrementar contador de tickets
- `updatePerformanceStats()`: Actualizar estadísticas de rendimiento
- `getLeastBusyAgent()`: Obtener agente con menor carga

## Ejemplos de Uso

### Crear un agente técnico
```bash
curl -X POST /admin/tickets/agents \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{
    "user_id": 5,
    "department": "technical",
    "specialization": "technical",
    "max_concurrent_tickets": 8,
    "skills": ["Linux", "Redes", "Troubleshooting"]
  }'
```

### Obtener agentes disponibles
```bash
curl -X GET "/admin/tickets/agents?available=true&department=support" \
  -H "Authorization: Bearer {token}"
```

### Asignar ticket automáticamente
```bash
# 1. Obtener agente recomendado
curl -X GET "/admin/tickets/agents/recommended?specialization=technical" \
  -H "Authorization: Bearer {token}"

# 2. Asignar ticket al agente
curl -X POST "/admin/tickets/agents/{uuid}/assign-ticket" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{"ticket_id": 123}'
```

## Notas Importantes

1. **Separación de Responsabilidades**: Esta API está específicamente diseñada para el módulo admin y está claramente separada de las funcionalidades del cliente.

2. **Rendimiento**: La API incluye índices optimizados para consultas frecuentes y paginación para manejar grandes volúmenes de datos.

3. **Escalabilidad**: El sistema de agentes está diseñado para manejar múltiples departamentos y especializaciones.

4. **Integridad de Datos**: Se incluyen validaciones y restricciones para mantener la consistencia de los datos.

5. **Monitoreo**: Las estadísticas y métricas permiten monitorear el rendimiento del equipo de soporte.

6. **Flexibilidad**: Los campos JSON permiten almacenar configuraciones personalizadas sin modificar la estructura de la base de datos.

