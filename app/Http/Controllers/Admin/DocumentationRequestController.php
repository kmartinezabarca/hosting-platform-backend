<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentationRequest;
use Illuminate\Http\Request;

class DocumentationRequestController extends Controller
{
    /**
     * Display a listing of documentation requests.
     */
    public function index(Request $request)
    {
        $query = DocumentationRequest::query();

        // Filter by kind if provided
        if ($request->has('kind') && in_array($request->kind, ['documentation', 'api_documentation'])) {
            $query->where('kind', $request->kind);
        }

        // Filter by resolution status if provided
        if ($request->has('is_resolved')) {
            $query->where('is_resolved', $request->boolean('is_resolved'));
        }

        // Search by name, email, or topic
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('topic', 'like', "%{$search}%");
            });
        }

        // Sort by created_at descending by default
        $requests = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json(['data' => $requests], 200);
    }

    /**
     * Display the specified documentation request.
     */
    public function show($id)
    {
        $request = DocumentationRequest::findOrFail($id);
        return response()->json(['data' => $request], 200);
    }

    /**
     * Mark a documentation request as resolved.
     */
    public function markResolved($id)
    {
        $request = DocumentationRequest::findOrFail($id);
        $request->update(['is_resolved' => true]);
        return response()->json(['message' => 'Solicitud marcada como resuelta.', 'data' => $request], 200);
    }

    /**
     * Delete a documentation request.
     */
    public function destroy($id)
    {
        $request = DocumentationRequest::findOrFail($id);
        $request->delete();
        return response()->json(['message' => 'Solicitud eliminada exitosamente.'], 200);
    }
}
