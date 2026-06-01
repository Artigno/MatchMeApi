<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\GarmentClassifier;
use App\Http\Controllers\Controller;
use App\Models\Garment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarmentController extends Controller
{
    public function __construct(private readonly GarmentClassifier $classifier) {}

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
            'category'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'color'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition'   => ['sometimes', 'nullable', 'string', 'in:new,like new,good,fair,worn'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $garment->update($validated);

        return response()->json($this->garmentResource($garment->fresh()));
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

        return response()->json([
            'id'          => $garment->id,
            'category'    => $garment->category,
            'brand'       => $garment->brand,
            'color'       => $garment->color,
            'condition'   => $garment->condition,
            'description' => $garment->description,
            'photo_url'   => $garment->getFirstMediaUrl('photos'),
            'created_at'  => $garment->created_at,
        ]);
    }

    private function garmentResource(Garment $garment): array
    {
        return [
            'id'          => $garment->id,
            'category'    => $garment->category,
            'brand'       => $garment->brand,
            'color'       => $garment->color,
            'condition'   => $garment->condition,
            'description' => $garment->description,
            'photo_url'   => $garment->getFirstMediaUrl('photos'),
            'created_at'  => $garment->created_at,
            'updated_at'  => $garment->updated_at,
        ];
    }
}
