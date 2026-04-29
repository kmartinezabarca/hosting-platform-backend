<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Reglas de validación reutilizables
    // ─────────────────────────────────────────────────────────────────────────

    private function baseRules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes|required';

        return [
            'title'            => "{$req}|string|max:255",
            'client_name'      => "{$req}|string|max:255",
            'client_email'     => "{$req}|email|max:255",
            'client_company'   => 'nullable|string|max:255',
            'client_phone'     => 'nullable|string|max:50',

            'items'            => "{$req}|array|min:1",
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',

            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_percent'      => 'nullable|numeric|min:0|max:100',
            'currency'         => 'nullable|string|in:MXN,USD',
            'notes'            => 'nullable|string',
            'terms'            => 'nullable|string',
        ];
    }

    private function baseMessages(): array
    {
        return [
            'title.required'               => 'El título es obligatorio.',
            'client_name.required'         => 'El nombre del cliente es obligatorio.',
            'client_email.required'        => 'El correo del cliente es obligatorio.',
            'client_email.email'           => 'El correo del cliente no es válido.',
            'items.required'               => 'La cotización debe tener al menos un concepto.',
            'items.min'                    => 'La cotización debe tener al menos un concepto.',
            'items.*.description.required' => 'Cada concepto debe tener una descripción.',
            'items.*.quantity.required'    => 'Cada concepto debe tener una cantidad.',
            'items.*.quantity.min'         => 'La cantidad debe ser mayor a cero.',
            'items.*.unit_price.required'  => 'Cada concepto debe tener un precio unitario.',
            'currency.in'                  => 'La moneda debe ser MXN o USD.',
            'discount_percent.max'         => 'El descuento no puede superar el 100%.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/quotations
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 15), 100);
            $search  = $request->get('search');
            $status  = $request->get('status');

            $quotations = Quotation::search($search)
                ->when($status, fn($q) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $quotations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cotizaciones.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/quotations/{uuid}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(string $uuid): JsonResponse
    {
        try {
            $quotation = Quotation::where('uuid', $uuid)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $quotation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), $this->baseRules(true), $this->baseMessages());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            $quotation = new Quotation([
                'title'            => $data['title'],
                'client_name'      => $data['client_name'],
                'client_email'     => $data['client_email'],
                'client_company'   => $data['client_company']   ?? null,
                'client_phone'     => $data['client_phone']     ?? null,
                'items'            => $data['items'],
                'discount_percent' => $data['discount_percent'] ?? 0,
                'tax_percent'      => $data['tax_percent']      ?? 16,
                'currency'         => $data['currency']         ?? 'MXN',
                'notes'            => $data['notes']            ?? null,
                'terms'            => $data['terms']            ?? null,
                'status'           => 'draft',
            ]);

            $quotation->recalculate();
            $quotation->save();

            return response()->json([
                'success' => true,
                'message' => 'Cotización creada exitosamente.',
                'data'    => $quotation->fresh(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /admin/quotations/{uuid}
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $quotation = Quotation::where('uuid', $uuid)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            $rules = $this->baseRules(false);

            // Permitir cambio manual de status
            $rules['status'] = ['sometimes', Rule::in(['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired'])];

            $validator = Validator::make($request->all(), $rules, $this->baseMessages());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Aplicar los campos que llegaron
            $quotation->fill(array_filter($data, fn($v) => $v !== null || array_key_exists('notes', $data) || array_key_exists('terms', $data)));

            // Forzar campos nullable a null si llegan explícitamente
            foreach (['client_company', 'client_phone', 'notes', 'terms'] as $field) {
                if ($request->has($field)) {
                    $quotation->$field = $data[$field] ?? null;
                }
            }

            // Si cambiaron items o porcentajes, recalcular
            $needsRecalc = $request->hasAny(['items', 'discount_percent', 'tax_percent']);
            if ($needsRecalc) {
                $quotation->recalculate();
            }

            $quotation->save();

            return response()->json([
                'success' => true,
                'message' => 'Cotización actualizada.',
                'data'    => $quotation->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /admin/quotations/{uuid}
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(string $uuid): JsonResponse
    {
        try {
            $quotation = Quotation::where('uuid', $uuid)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            $quotation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cotización eliminada.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{uuid}/send
    // ─────────────────────────────────────────────────────────────────────────

    public function send(string $uuid): JsonResponse
    {
        try {
            $quotation = Quotation::where('uuid', $uuid)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            $token     = Str::random(48);
            $publicUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
                         . '/cotizacion/' . $token;

            $quotation->update([
                'public_token' => $token,
                'public_url'   => $publicUrl,
                'expires_at'   => now()->addHours(72),
                'status'       => 'sent',
                'sent_at'      => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cotización enviada. El enlace es válido por 72 horas.',
                'data'    => $quotation->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{uuid}/regenerate-link
    // ─────────────────────────────────────────────────────────────────────────

    public function regenerateLink(string $uuid): JsonResponse
    {
        try {
            $quotation = Quotation::where('uuid', $uuid)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            if (!$quotation->public_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta cotización aún no ha sido enviada. Usa el endpoint /send primero.',
                ], 422);
            }

            $token     = Str::random(48);
            $publicUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
                         . '/cotizacion/' . $token;

            // Genera nuevo token (invalida el anterior) y resetea la expiración.
            // El status NO se modifica.
            $quotation->update([
                'public_token' => $token,
                'public_url'   => $publicUrl,
                'expires_at'   => now()->addHours(72),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Enlace regenerado. El nuevo enlace es válido por 72 horas.',
                'data'    => $quotation->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al regenerar el enlace.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
