<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreQuotationRequest;
use App\Http\Requests\Admin\UpdateQuotationRequest;
use App\Http\Resources\QuotationListResource;
use App\Http\Resources\QuotationResource;
use App\Domains\Platform\Models\Quotation;
use App\Domains\Platform\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $service) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/quotations
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 15), 100);

        $quotations = Quotation::search($request->get('search'))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success'    => true,
            'data'       => QuotationListResource::collection($quotations->items()),
            'pagination' => [
                'current_page'   => $quotations->currentPage(),
                'per_page'       => $quotations->perPage(),
                'total'          => $quotations->total(),
                'last_page'      => $quotations->lastPage(),
                'has_more_pages' => $quotations->hasMorePages(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/quotations/{quotation}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(Quotation $quotation): JsonResponse
    {
        $quotation->load('activities');

        return response()->json([
            'success' => true,
            'data'    => new QuotationResource($quotation),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations
    // ─────────────────────────────────────────────────────────────────────────

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        try {
            $quotation = $this->service
                ->withRequest($request)
                ->create($request->validated(), $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Cotización creada exitosamente.',
                'data'    => new QuotationResource($quotation),
            ], 201);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /admin/quotations/{quotation}
    // ─────────────────────────────────────────────────────────────────────────

    public function update(UpdateQuotationRequest $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('update', $quotation);

        try {
            $quotation = $this->service
                ->withRequest($request)
                ->update($quotation, $request->validated(), $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Cotización actualizada.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /admin/quotations/{quotation}
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('delete', $quotation);

        try {
            $this->service->withRequest($request)->delete($quotation, $request->user());

            return response()->json(['success' => true, 'message' => 'Cotización eliminada.']);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/send
    // ─────────────────────────────────────────────────────────────────────────

    public function send(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('send', $quotation);

        try {
            $quotation = $this->service->withRequest($request)->send($quotation, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Cotización enviada. El enlace es válido por 72 horas.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/accept
    // ─────────────────────────────────────────────────────────────────────────

    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('accept', $quotation);

        try {
            $quotation = $this->service->withRequest($request)->accept($quotation, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Cotización aceptada.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/reject
    // ─────────────────────────────────────────────────────────────────────────

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('reject', $quotation);

        try {
            $reason    = $request->string('reason')->value() ?: null;
            $quotation = $this->service->withRequest($request)->reject($quotation, $request->user(), $reason);

            return response()->json([
                'success' => true,
                'message' => 'Cotización rechazada.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/reopen
    // ─────────────────────────────────────────────────────────────────────────

    public function reopen(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('reopen', $quotation);

        $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $reason    = $request->string('reason')->value() ?: null;
            $quotation = $this->service->withRequest($request)->reopen($quotation, $request->user(), $reason);

            return response()->json([
                'success' => true,
                'message' => 'Cotización reabierta. Ahora está en revisión.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/regenerate-link
    // ─────────────────────────────────────────────────────────────────────────

    public function regenerateLink(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('regenerateLink', $quotation);

        try {
            $quotation = $this->service->withRequest($request)->regenerateLink($quotation, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Enlace regenerado. Válido por 72 horas.',
                'data'    => new QuotationResource($quotation),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/quotations/{quotation}/revision
    // ─────────────────────────────────────────────────────────────────────────

    public function createRevision(Request $request, Quotation $quotation): JsonResponse
    {
        try {
            $revision = $this->service->withRequest($request)->createRevision($quotation, $request->user());

            return response()->json([
                'success' => true,
                'message' => "Revisión {$revision->revision_number} creada como borrador.",
                'data'    => new QuotationResource($revision),
            ], 201);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function domainError(\DomainException $e): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    }

    private function serverError(\Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Error interno del servidor.',
            'debug'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
