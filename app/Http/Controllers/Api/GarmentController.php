<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\GarmentClassifier;
use App\Http\Controllers\Controller;
use App\Models\Garment;
use App\Models\GarmentDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GarmentController extends Controller
{
    public function __construct(private readonly GarmentClassifier $classifier) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = Garment::where('user_id', $request->user()->id)
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

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'nullable', 'string', Rule::in(Garment::CONDITIONS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $garment->update($validated);
        $garment->refresh();

        return response()->json($this->garmentResource($garment));
    }

    public function destroy(Request $request, Garment $garment): Response
    {
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

    public function classify(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        $file = $request->file('photo');
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        try {
            $fields = $this->classifier->classify($base64, $file->getMimeType() ?? 'image/jpeg');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Classification timed out, please retry.'], 504);
        }

        $garment = new Garment($fields);
        $garment->user_id = $request->user()->id;
        $garment->save();

        $garment->addMedia($file)->toMediaCollection('photos');
        $garment->refresh();

        return response()->json($this->garmentResource($garment));
    }

    private function garmentResource(Garment $garment): array
    {
        return [
            'id' => $garment->id,
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
