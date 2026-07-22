<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Garment;
use App\Models\GarmentDeletion;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GarmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = Garment::where('user_id', $request->user()->id)
            ->with('media')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Garment $g) => $this->garmentResource($g))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function show(Request $request, Garment $garment): JsonResponse
    {
        if ($garment->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json($this->garmentResource($garment));
    }

    public function update(Request $request, Garment $garment): JsonResponse
    {
        if ($garment->user_id !== $request->user()->id) {
            abort(404);
        }

        // Optimistic concurrency: the client may send the updated_at it last saw.
        // A newer server copy means another device wrote in between → 409 with the
        // current resource so the client can reconcile instead of overwriting blind.
        if ($request->hasHeader('If-Unmodified-Since')) {
            try {
                $since = Carbon::parse($request->header('If-Unmodified-Since'));
            } catch (\Throwable) {
                abort(400, 'Invalid If-Unmodified-Since header.');
            }

            if ($garment->updated_at !== null && $garment->updated_at->startOfSecond()->greaterThan($since)) {
                return response()->json([
                    'message' => 'Garment was modified after the given timestamp.',
                    'garment' => $this->garmentResource($garment),
                ], 409);
            }
        }

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', Rule::in(Garment::CATEGORIES)],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'nullable', 'string', Rule::in(Garment::CONDITIONS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $garment->update($validated);
        $garment->refresh();

        return response()->json($this->garmentResource($garment));
    }

    public function destroy(Request $request, int $garmentId): Response
    {
        $garment = Garment::find($garmentId);

        if ($garment === null) {
            // Idempotent delete: the audit tombstone proves this caller already
            // deleted the row, so a retry (client never saw the first 204) succeeds
            // instead of looping on 404 forever.
            $alreadyDeletedByCaller = GarmentDeletion::where('user_id', $request->user()->id)
                ->where('garment_id', $garmentId)
                ->exists();

            abort_unless($alreadyDeletedByCaller, 404);

            return response()->noContent();
        }

        if ($garment->user_id !== $request->user()->id) {
            abort(404);
        }

        // forceDelete (not delete) — the model uses SoftDeletes, and only a real
        // delete triggers spatie's media removal from S3. The audit snapshot is
        // written inside the same transaction so row + file live or die together.
        DB::transaction(function () use ($request, $garment) {
            GarmentDeletion::create([
                'user_id' => $request->user()->id,
                'garment_id' => $garment->id,
                'snapshot' => $this->garmentResource($garment),
            ]);

            $garment->forceDelete();
        });

        return response()->noContent();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_ref' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(Garment::CATEGORIES)],
            'brand' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', Rule::in(Garment::CONDITIONS)],
            'description' => ['nullable', 'string', 'max:5000'],
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        // Idempotent create: client_ref is the mobile app's local garment id. If a
        // previous POST persisted the row but the response was lost, the retry must
        // return the existing row instead of creating a duplicate with a second photo.
        $clientRef = $validated['client_ref'] ?? null;

        if ($clientRef !== null) {
            $existing = Garment::where('user_id', $request->user()->id)
                ->where('client_ref', $clientRef)
                ->first();

            if ($existing !== null) {
                return response()->json($this->garmentResource($existing));
            }
        }

        $garment = new Garment(collect($validated)->except('photo')->all());
        $garment->user_id = $request->user()->id;

        try {
            $garment->save();
        } catch (UniqueConstraintViolationException) {
            // Concurrent retry won the race on (user_id, client_ref) — serve its row.
            $existing = Garment::where('user_id', $request->user()->id)
                ->where('client_ref', $clientRef)
                ->firstOrFail();

            return response()->json($this->garmentResource($existing));
        }

        $garment->addMedia($request->file('photo'))->toMediaCollection('photos');
        $garment->refresh();

        return response()->json($this->garmentResource($garment));
    }

    public function replacePhoto(Request $request, Garment $garment): JsonResponse
    {
        if ($garment->user_id !== $request->user()->id) {
            abort(404);
        }

        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        // The 'photos' collection is singleFile — adding replaces the previous
        // media row and deletes the old file from storage.
        $garment->addMedia($request->file('photo'))->toMediaCollection('photos');
        $garment->touch();
        $garment->refresh();

        return response()->json($this->garmentResource($garment));
    }

    private function garmentResource(Garment $garment): array
    {
        return [
            'id' => $garment->id,
            'client_ref' => $garment->client_ref,
            'category' => $garment->category,
            'brand' => $garment->brand,
            'color' => $garment->color,
            'condition' => $garment->condition,
            'description' => $garment->description,
            'photo_url' => $garment->getFirstMediaUrl('photos'),
            'created_at' => $garment->created_at,
            'updated_at' => $garment->updated_at,
        ];
    }
}
