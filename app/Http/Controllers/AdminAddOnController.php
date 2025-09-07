<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAddOnRequest;
use App\Http\Requests\Admin\UpdateAddOnRequest;
use App\Http\Resources\AddOnResource;
use App\Models\AddOn;
use App\Models\ServicePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAddOnController extends Controller
{
    public function index(Request $request)
    {
        $q = AddOn::query();

        // Filtros
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($request->has('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE));
        }

        // Eager load opcional
        $withPlans = $request->boolean('with_plans', true);
        if ($withPlans) $q->with('plans:id,uuid,name');

        // Orden simple
        $q->orderBy($request->input('order_by', 'created_at'), $request->input('order_dir', 'desc'));

        // Paginación
        $perPage = (int) $request->input('per_page', 20);
        $paginate = $request->boolean('paginate', true);

        if ($paginate) {
            $page = $q->paginate($perPage);
            return response()->json([
                'success' => true,
                'data'    => AddOnResource::collection($page)->resource, // colección mantiene meta/links
                'meta'    => [
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                ],
            ]);
        }

        $rows = $q->get();
        return response()->json([
            'success' => true,
            'data'    => AddOnResource::collection($rows),
        ]);
    }

    public function store(StoreAddOnRequest $request)
    {
        $data = $request->validated();
        $addOn = new AddOn($data);
        $addOn->uuid = (string) Str::uuid();
        $addOn->save();

        // Attach planes (ids)
        if (!empty($data['service_plans'])) {
            $addOn->plans()->sync($data['service_plans']);
        }

        $addOn->load('plans:id,uuid,name');

        return response()->json([
            'success' => true,
            'message' => 'Add-on creado correctamente.',
            'data'    => new AddOnResource($addOn),
        ], 201);
    }

    public function show(string $uuid, Request $request)
    {
        $addOn = AddOn::query()
            ->when($request->boolean('with_plans', true), fn($q) => $q->with('plans:id,uuid,name'))
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => new AddOnResource($addOn),
        ]);
    }

    public function update(UpdateAddOnRequest $request, string $uuid)
    {
        $addOn = AddOn::where('uuid', $uuid)->firstOrFail();
        $data = $request->validated();

        $addOn->fill($data)->save();

        if (array_key_exists('service_plans', $data)) {
            $addOn->plans()->sync($data['service_plans'] ?? []);
        }

        $addOn->load('plans:id,uuid,name');

        return response()->json([
            'success' => true,
            'message' => 'Add-on actualizado correctamente.',
            'data'    => new AddOnResource($addOn),
        ]);
    }

    public function destroy(string $uuid)
    {
        $addOn = AddOn::where('uuid', $uuid)->firstOrFail();
        $addOn->plans()->detach();
        $addOn->delete();

        return response()->json([
            'success' => true,
            'message' => 'Add-on eliminado correctamente.',
            'data'    => null,
        ]);
    }

    public function attachToPlan(string $uuid, Request $request)
    {
        $request->validate([
            'plan_id'   => ['nullable', 'integer', 'exists:service_plans,id'],
            'plan_uuid' => ['nullable', 'string', 'exists:service_plans,uuid'],
        ]);

        $addOn = AddOn::where('uuid', $uuid)->firstOrFail();

        $plan = null;
        if ($request->filled('plan_id')) {
            $plan = ServicePlan::findOrFail($request->integer('plan_id'));
        } elseif ($request->filled('plan_uuid')) {
            $plan = ServicePlan::where('uuid', $request->string('plan_uuid'))->firstOrFail();
        } else {
            abort(422, 'Debe proporcionar plan_id o plan_uuid.');
        }

        $addOn->plans()->syncWithoutDetaching([$plan->id]);

        return response()->json([
            'success' => true,
            'message' => 'Add-on vinculado al plan correctamente.',
            'data'    => [
                'add_on' => new AddOnResource($addOn->fresh('plans:id,uuid,name')),
            ],
        ]);
    }

    public function detachFromPlan(string $uuid, Request $request)
    {
        $request->validate([
            'plan_id'   => ['nullable', 'integer', 'exists:service_plans,id'],
            'plan_uuid' => ['nullable', 'string', 'exists:service_plans,uuid'],
        ]);

        $addOn = AddOn::where('uuid', $uuid)->firstOrFail();

        $plan = null;
        if ($request->filled('plan_id')) {
            $plan = ServicePlan::findOrFail($request->integer('plan_id'));
        } elseif ($request->filled('plan_uuid')) {
            $plan = ServicePlan::where('uuid', $request->string('plan_uuid'))->firstOrFail();
        } else {
            abort(422, 'Debe proporcionar plan_id o plan_uuid.');
        }

        $addOn->plans()->detach($plan->id);

        return response()->json([
            'success' => true,
            'message' => 'Add-on desvinculado del plan correctamente.',
            'data'    => [
                'add_on' => new AddOnResource($addOn->fresh('plans:id,uuid,name')),
            ],
        ]);
    }
}
