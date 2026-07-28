<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\GarmentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Exceptions\ClassifierUpstreamException;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassifyController extends Controller
{
    public function __construct(private readonly GarmentClassifier $classifier) {}

    /**
     * Stateless: read the photo with AI and return the listing fields.
     * Persists nothing — POST /garments owns persistence.
     */
    #[HeaderParameter('X-App-Key', description: 'Static shared secret gating /classify. Not a Sanctum bearer token.', required: true)]
    #[Response(403, description: 'Missing or invalid X-App-Key header.')]
    public function classify(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        $file = $request->file('photo');
        $path = $file->getRealPath();

        // Guard the rare edge where the temp upload vanished: never base64 an
        // empty string and ship junk bytes to the paid AI provider.
        abort_if($path === false, 422, 'Uploaded photo could not be read.');

        $base64 = base64_encode((string) file_get_contents($path));

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
