<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\GarmentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Exceptions\ClassifierUpstreamException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassifyController extends Controller
{
    public function __construct(private readonly GarmentClassifier $classifier) {}

    /**
     * Stateless: read the photo with AI and return the listing fields.
     * Persists nothing — POST /garments owns persistence.
     */
    public function classify(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        $file = $request->file('photo');
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        try {
            $fields = $this->classifier->classify($base64, $file->getMimeType() ?? 'image/jpeg');
        } catch (ClassifierTimeoutException) {
            return response()->json(['message' => 'Classification timed out, please retry.'], 504);
        } catch (ClassifierUpstreamException) {
            return response()->json(['message' => 'Classification failed, please retry.'], 502);
        }

        return response()->json($fields);
    }
}
