<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedWardrobe extends Command
{
    public const EMAIL = 'wardrobe-test@example.com';

    public const TOKEN_NAME = 'seed-access';

    private const FIXTURES_DIR = 'database/seeders/fixtures';

    protected $signature = 'app:seed-wardrobe';

    protected $description = 'Seed the fixed test account with a fully classified wardrobe (wipe & reseed), print an access token.';

    public function handle(): int
    {
        $fixturesDir = base_path(self::FIXTURES_DIR);

        if (empty(glob($fixturesDir.'/*.jpg'))) {
            $this->error("No fixture images in {$fixturesDir} — run: php database/seeders/fixtures/generate.php");

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Wardrobe Test', 'password' => Str::random(32)],
        );

        // Wipe & reseed: per-model forceDelete so spatie removes media rows + files
        // (SoftDeletes — a mass delete() would only soft-delete and orphan media).
        // No GarmentDeletion audit rows: maintenance operation, not a user delete.
        $wiped = 0;
        foreach (Garment::withTrashed()->where('user_id', $user->id)->get() as $garment) {
            $garment->forceDelete();
            $wiped++;
        }

        foreach ($this->wardrobe() as $row) {
            $garment = new Garment(collect($row)->except('fixture')->all());
            $garment->user_id = $user->id;
            $garment->save();

            $garment->addMedia($fixturesDir.'/'.$row['fixture'])
                ->preservingOriginal()
                ->toMediaCollection('photos');
        }

        $user->tokens()->where('name', self::TOKEN_NAME)->delete();
        $token = $user->createToken(self::TOKEN_NAME, ['access'], now()->addDay())->plainTextToken;

        $garments = Garment::where('user_id', $user->id)->get();

        $this->info("Seeded {$garments->count()} garments for {$user->email} (wiped {$wiped}).");
        $this->table(
            ['client_ref', 'category', 'brand', 'color', 'condition'],
            $garments->map(fn (Garment $g) => [$g->client_ref, $g->category, $g->brand ?? '—', $g->color ?? '—', $g->condition])->all(),
        );
        $this->newLine();
        $this->line("Token (24 h): {$token}");
        $this->newLine();
        $this->line('Try: curl -H "Authorization: Bearer '.$token.'" -H "Accept: application/json" '.config('app.url').'/api/garments');
        $this->line('Local photo URLs need: php artisan storage:link');

        return self::SUCCESS;
    }

    /**
     * Curated wardrobe: every category ≥ 2×, every condition ≥ 1×, two rows with
     * null optional fields (07: brand, 11: color + description). Fixtures map
     * positionally by filename. Values reference the canonical constants so a
     * value-set change breaks the seed loudly instead of drifting.
     *
     * @return list<array{client_ref: string, category: ?string, brand: ?string, color: ?string, condition: ?string, description: ?string, fixture: string}>
     */
    private function wardrobe(): array
    {
        [$tops, $bottoms, $footwear, $accessories, $outerwear] = Garment::CATEGORIES;
        [$new, $likeNew, $good, $fair, $worn] = Garment::CONDITIONS;

        return [
            [
                'client_ref' => 'seed-01-niebieski-tshirt',
                'category' => $tops,
                'brand' => 'Zara',
                'color' => 'niebieski',
                'condition' => $good,
                'description' => 'Niebieski bawełniany t-shirt w dobrym stanie.',
                'fixture' => '01-niebieski-tshirt.jpg',
            ],
            [
                'client_ref' => 'seed-02-biala-koszula',
                'category' => $tops,
                'brand' => 'H&M',
                'color' => 'biały',
                'condition' => $new,
                'description' => 'Biała koszula z metką, nigdy nienoszona.',
                'fixture' => '02-biala-koszula.jpg',
            ],
            [
                'client_ref' => 'seed-03-granatowe-jeansy',
                'category' => $bottoms,
                'brand' => "Levi's",
                'color' => 'granatowy',
                'condition' => $good,
                'description' => 'Granatowe jeansy o prostym kroju, lekko sprane.',
                'fixture' => '03-granatowe-jeansy.jpg',
            ],
            [
                'client_ref' => 'seed-04-czarne-spodnie',
                'category' => $bottoms,
                'brand' => 'Reserved',
                'color' => 'czarny',
                'condition' => $likeNew,
                'description' => 'Czarne eleganckie spodnie, założone tylko raz.',
                'fixture' => '04-czarne-spodnie.jpg',
            ],
            [
                'client_ref' => 'seed-05-biale-sneakersy',
                'category' => $footwear,
                'brand' => 'Nike',
                'color' => 'biały',
                'condition' => $fair,
                'description' => 'Białe sneakersy z widocznymi śladami użytkowania.',
                'fixture' => '05-biale-sneakersy.jpg',
            ],
            [
                'client_ref' => 'seed-06-brazowe-buty',
                'category' => $footwear,
                'brand' => 'CCC',
                'color' => 'brązowy',
                'condition' => $worn,
                'description' => 'Brązowe skórzane buty, mocno znoszone podeszwy.',
                'fixture' => '06-brazowe-buty.jpg',
            ],
            [
                'client_ref' => 'seed-07-czerwony-szalik',
                'category' => $accessories,
                'brand' => null,
                'color' => 'czerwony',
                'condition' => $good,
                'description' => 'Czerwony wełniany szalik, ciepły i miękki.',
                'fixture' => '07-czerwony-szalik.jpg',
            ],
            [
                'client_ref' => 'seed-08-czarna-czapka',
                'category' => $accessories,
                'brand' => '4F',
                'color' => 'czarny',
                'condition' => $new,
                'description' => 'Czarna czapka zimowa z pomponem, zupełnie nowa.',
                'fixture' => '08-czarna-czapka.jpg',
            ],
            [
                'client_ref' => 'seed-09-bezowy-trencz',
                'category' => $outerwear,
                'brand' => 'Zara',
                'color' => 'beżowy',
                'condition' => $likeNew,
                'description' => 'Beżowy klasyczny trencz w stanie jak nowy.',
                'fixture' => '09-bezowy-trencz.jpg',
            ],
            [
                'client_ref' => 'seed-10-zielona-kurtka',
                'category' => $outerwear,
                'brand' => 'The North Face',
                'color' => 'zielony',
                'condition' => $good,
                'description' => 'Zielona kurtka przejściowa, sprawdzona w górach.',
                'fixture' => '10-zielona-kurtka.jpg',
            ],
            [
                'client_ref' => 'seed-11-szary-sweter',
                'category' => $tops,
                'brand' => 'Medicine',
                'color' => null,
                'condition' => $good,
                'description' => null,
                'fixture' => '11-szary-sweter.jpg',
            ],
            [
                'client_ref' => 'seed-12-bordowa-spodnica',
                'category' => $bottoms,
                'brand' => 'Mango',
                'color' => 'bordowy',
                'condition' => $fair,
                'description' => 'Bordowa spódnica midi, drobne zmechacenia.',
                'fixture' => '12-bordowa-spodnica.jpg',
            ],
        ];
    }
}
