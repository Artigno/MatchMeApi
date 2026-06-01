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
}
