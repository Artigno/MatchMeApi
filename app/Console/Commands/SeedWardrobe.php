<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Garment;
use Illuminate\Console\Command;

class SeedWardrobe extends Command
{
    protected $signature = 'app:seed-wardrobe';

    protected $description = 'Seed the fixed test account with a fully classified wardrobe (wipe & reseed), print an access token.';

    public function handle(): int
    {
        $this->error('Not wired yet — execution logic lands in phase 2 of the seed-test-wardrobe plan.');

        return self::FAILURE;
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
        [$gora, $dol, $buty, $akcesorium, $okrycie] = Garment::CATEGORIES;
        [$nowy, $jakNowy, $dobry, $sredni, $znoszony] = Garment::CONDITIONS;

        return [
            [
                'client_ref' => 'seed-01-niebieski-tshirt',
                'category' => $gora,
                'brand' => 'Zara',
                'color' => 'niebieski',
                'condition' => $dobry,
                'description' => 'Niebieski bawełniany t-shirt w dobrym stanie.',
                'fixture' => '01-niebieski-tshirt.jpg',
            ],
            [
                'client_ref' => 'seed-02-biala-koszula',
                'category' => $gora,
                'brand' => 'H&M',
                'color' => 'biały',
                'condition' => $nowy,
                'description' => 'Biała koszula z metką, nigdy nienoszona.',
                'fixture' => '02-biala-koszula.jpg',
            ],
            [
                'client_ref' => 'seed-03-granatowe-jeansy',
                'category' => $dol,
                'brand' => "Levi's",
                'color' => 'granatowy',
                'condition' => $dobry,
                'description' => 'Granatowe jeansy o prostym kroju, lekko sprane.',
                'fixture' => '03-granatowe-jeansy.jpg',
            ],
            [
                'client_ref' => 'seed-04-czarne-spodnie',
                'category' => $dol,
                'brand' => 'Reserved',
                'color' => 'czarny',
                'condition' => $jakNowy,
                'description' => 'Czarne eleganckie spodnie, założone tylko raz.',
                'fixture' => '04-czarne-spodnie.jpg',
            ],
            [
                'client_ref' => 'seed-05-biale-sneakersy',
                'category' => $buty,
                'brand' => 'Nike',
                'color' => 'biały',
                'condition' => $sredni,
                'description' => 'Białe sneakersy z widocznymi śladami użytkowania.',
                'fixture' => '05-biale-sneakersy.jpg',
            ],
            [
                'client_ref' => 'seed-06-brazowe-buty',
                'category' => $buty,
                'brand' => 'CCC',
                'color' => 'brązowy',
                'condition' => $znoszony,
                'description' => 'Brązowe skórzane buty, mocno znoszone podeszwy.',
                'fixture' => '06-brazowe-buty.jpg',
            ],
            [
                'client_ref' => 'seed-07-czerwony-szalik',
                'category' => $akcesorium,
                'brand' => null,
                'color' => 'czerwony',
                'condition' => $dobry,
                'description' => 'Czerwony wełniany szalik, ciepły i miękki.',
                'fixture' => '07-czerwony-szalik.jpg',
            ],
            [
                'client_ref' => 'seed-08-czarna-czapka',
                'category' => $akcesorium,
                'brand' => '4F',
                'color' => 'czarny',
                'condition' => $nowy,
                'description' => 'Czarna czapka zimowa z pomponem, zupełnie nowa.',
                'fixture' => '08-czarna-czapka.jpg',
            ],
            [
                'client_ref' => 'seed-09-bezowy-trencz',
                'category' => $okrycie,
                'brand' => 'Zara',
                'color' => 'beżowy',
                'condition' => $jakNowy,
                'description' => 'Beżowy klasyczny trencz w stanie jak nowy.',
                'fixture' => '09-bezowy-trencz.jpg',
            ],
            [
                'client_ref' => 'seed-10-zielona-kurtka',
                'category' => $okrycie,
                'brand' => 'The North Face',
                'color' => 'zielony',
                'condition' => $dobry,
                'description' => 'Zielona kurtka przejściowa, sprawdzona w górach.',
                'fixture' => '10-zielona-kurtka.jpg',
            ],
            [
                'client_ref' => 'seed-11-szary-sweter',
                'category' => $gora,
                'brand' => 'Medicine',
                'color' => null,
                'condition' => $dobry,
                'description' => null,
                'fixture' => '11-szary-sweter.jpg',
            ],
            [
                'client_ref' => 'seed-12-bordowa-spodnica',
                'category' => $dol,
                'brand' => 'Mango',
                'color' => 'bordowy',
                'condition' => $sredni,
                'description' => 'Bordowa spódnica midi, drobne zmechacenia.',
                'fixture' => '12-bordowa-spodnica.jpg',
            ],
        ];
    }
}
