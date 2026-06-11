<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carga inicial de la base de conocimiento de ROKE Pet.
 * Idempotente: usa updateOrInsert por (brand, slug), así que correr la migración
 * más de una vez no duplica artículos ni pisa los ids existentes.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        $now = now();

        foreach ($this->articles() as $a) {
            DB::connection('roke_pet')->table('pet_knowledge_articles')->updateOrInsert(
                ['brand' => 'roke_pet', 'slug' => $a['slug']],
                [
                    'id'         => (string) Str::uuid(),
                    'title'      => $a['title'],
                    'excerpt'    => $a['excerpt'],
                    'content'    => $a['content'],
                    'category'   => $a['category'],
                    'tags'       => json_encode($a['tags'], JSON_UNESCAPED_UNICODE),
                    'keywords'   => $a['keywords'],
                    'status'     => 'published',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->articles(), 'slug');
        DB::connection('roke_pet')->table('pet_knowledge_articles')
            ->where('brand', 'roke_pet')
            ->whereIn('slug', $slugs)
            ->delete();
    }

    private function articles(): array
    {
        return [
            [
                'slug'     => 'como-registrar-mi-mascota',
                'title'    => 'Cómo registrar a tu mascota en ROKE Pet',
                'category' => 'primeros-pasos',
                'excerpt'  => 'Crea el perfil digital de tu mascota en unos minutos.',
                'tags'     => ['registro', 'perfil', 'alta', 'mascota', 'crear'],
                'keywords' => 'registrar dar de alta crear perfil mascota nueva agregar añadir cuenta',
                'content'  => <<<MD
Para registrar a tu mascota en ROKE Pet:

1. Inicia sesión en tu panel de ROKE Pet (o crea tu cuenta gratis).
2. Entra a la sección **Mis mascotas** y toca **Agregar mascota**.
3. Completa los datos básicos: nombre, especie, raza aproximada y señas particulares.
4. Sube una foto para que sea fácil de identificar.
5. Añade el contacto de emergencia que quieras mostrar en el perfil público.
6. Guarda. Se generará automáticamente un perfil público con su enlace y código QR.

Con el plan gratuito puedes registrar 1 mascota; los planes de pago permiten más mascotas y funciones adicionales como historial médico completo y recordatorios.
MD,
            ],
            [
                'slug'     => 'como-funciona-qr-nfc',
                'title'    => 'Cómo funciona la identificación con QR y NFC',
                'category' => 'identificacion',
                'excerpt'  => 'El QR y el tag NFC abren el perfil público de tu mascota desde cualquier celular.',
                'tags'     => ['qr', 'nfc', 'tag', 'collar', 'placa', 'escaneo'],
                'keywords' => 'qr nfc tag chip collar placa escanear escaneo identificacion abrir perfil',
                'content'  => <<<MD
La identificación de ROKE Pet funciona sin que la otra persona tenga que instalar nada:

- **QR:** cualquier persona escanea el código con la cámara de su celular y se abre el perfil público de tu mascota.
- **NFC:** si tienes un tag NFC en el collar, basta con acercar el celular para abrir el mismo perfil.
- **Enlace:** también puedes compartir el enlace público directamente.

En el perfil público sólo se muestra la información que tú decidas: nombre, señas, datos de salud importantes y el contacto de emergencia. Tus datos privados no se publican.

No necesitas comprar hardware obligatorio: puedes empezar con un QR impreso o una placa grabada. Los tags NFC son opcionales y sólo agregan comodidad.
MD,
            ],
            [
                'slug'     => 'que-hacer-si-mi-mascota-se-pierde',
                'title'    => 'Qué hacer si tu mascota se pierde (modo extraviado)',
                'category' => 'emergencias',
                'excerpt'  => 'Activa el modo extraviado para recibir avisos cuando alguien escanee a tu mascota.',
                'tags'     => ['perdida', 'extraviado', 'modo perdido', 'alerta', 'emergencia'],
                'keywords' => 'perdida perdido extraviado modo perdido se perdio alerta aviso recompensa encontrar buscar',
                'content'  => <<<MD
Si tu mascota se pierde:

1. Entra a su perfil en el panel y activa el **modo extraviado**.
2. Cuando alguien escanee el QR, NFC o abra el enlace, ROKE Pet puede avisarte al instante y registrar una ubicación aproximada si la persona acepta compartirla.
3. Revisa que tu contacto de emergencia esté actualizado para que puedan comunicarse contigo.
4. Opcionalmente puedes mostrar un mensaje y una recompensa en el cartel público.

El modo extraviado no es rastreo GPS en tiempo real: te avisa cuando hay actividad en el perfil de tu mascota. Si es una emergencia de salud, acude además a un veterinario.
MD,
            ],
            [
                'slug'     => 'como-actualizar-vacunas',
                'title'    => 'Cómo registrar y actualizar las vacunas',
                'category' => 'salud',
                'excerpt'  => 'Lleva la cartilla de vacunas al día y recibe recordatorios.',
                'tags'     => ['vacunas', 'cartilla', 'salud', 'recordatorio', 'desparasitacion'],
                'keywords' => 'vacuna vacunas cartilla actualizar registrar salud refuerzo desparasitacion recordatorio proxima',
                'content'  => <<<MD
Para mantener la cartilla de tu mascota al día:

1. Abre el perfil de tu mascota en el panel.
2. Ve a la sección **Salud / Vacunas**.
3. Toca **Agregar vacuna** y registra el nombre, la fecha de aplicación y, si aplica, la fecha de la próxima dosis.
4. Puedes adjuntar una foto de la cartilla o del comprobante.
5. Guarda. ROKE Pet puede enviarte recordatorios cuando se acerque la próxima dosis.

La información médica se guarda de forma privada y puedes compartirla temporalmente con tu veterinario mediante un enlace. Esta cartilla no sustituye la valoración de un profesional veterinario.
MD,
            ],
            [
                'slug'     => 'como-funcionan-las-suscripciones',
                'title'    => 'Cómo funcionan los planes y suscripciones',
                'category' => 'planes-y-pagos',
                'excerpt'  => 'Planes, periodo de prueba, renovación y cancelación.',
                'tags'     => ['suscripcion', 'plan', 'pago', 'precio', 'cancelar', 'trial'],
                'keywords' => 'suscripcion plan planes pago precio cobro renovacion cancelar prueba trial stripe tarjeta factura',
                'content'  => <<<MD
ROKE Pet ofrece un plan gratuito y planes de pago:

- Puedes empezar con una **prueba gratis** y luego elegir un plan de suscripción.
- Según el plan, accedes a más mascotas, historial médico completo, recordatorios y soporte prioritario.
- Las suscripciones se **renuevan automáticamente** según el periodo contratado, sin permanencia obligatoria.
- Puedes **cancelar en cualquier momento** desde tu panel; conservas el acceso hasta el final del periodo ya pagado.

Los pagos se procesan a través de Stripe u otro proveedor autorizado; ROKE Pet no almacena los datos completos de tu tarjeta. Para cambios de plan, reembolsos o problemas de cobro, el asistente puede ponerte en contacto con una persona del equipo.
MD,
            ],
            [
                'slug'     => 'como-contactar-soporte-humano',
                'title'    => 'Cómo contactar con una persona del equipo de soporte',
                'category' => 'soporte',
                'excerpt'  => 'Puedes pedir hablar con una persona en cualquier momento.',
                'tags'     => ['soporte', 'humano', 'contacto', 'ayuda', 'agente'],
                'keywords' => 'soporte humano persona agente contacto ayuda hablar escalar correo email atencion',
                'content'  => <<<MD
Si necesitas hablar con una persona del equipo:

- En el chat, toca **"Hablar con soporte"** o escribe que quieres hablar con una persona. La conversación se pasará a un agente humano y la IA dejará de responder automáticamente.
- También puedes escribir a soporte por correo electrónico desde la sección de ayuda.

Pasamos siempre con una persona en casos sensibles: problemas de pago o suscripción, acceso a tu cuenta, mascota perdida, reembolsos o cualquier acción que requiera permisos especiales. Intentamos responder lo antes posible.
MD,
            ],
        ];
    }
};
