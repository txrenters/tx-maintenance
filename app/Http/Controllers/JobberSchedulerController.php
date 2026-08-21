<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Response;

class JobberSchedulerController extends Controller
{
    /**
     * Approximate city-center coordinates for every city the portfolio has
     * properties in. City-level only — individual properties are not geocoded
     * yet, so the coverage map plots one circle per city.
     *
     * @var array<string, array{float, float}>
     */
    private const CITY_COORDINATES = [
        'houston' => [29.7604, -95.3698],
        'cypress' => [29.9691, -95.6972],
        'nacogdoches' => [31.6035, -94.6555],
        'spring' => [30.0799, -95.4172],
        'katy' => [29.7858, -95.8245],
        'richmond' => [29.5822, -95.7608],
        'sugar land' => [29.6197, -95.6349],
        'humble' => [29.9988, -95.2622],
        'conroe' => [30.3119, -95.4561],
        'tomball' => [30.0972, -95.6161],
        'league city' => [29.5075, -95.0949],
        'kingwood' => [30.0505, -95.1838],
        'baytown' => [29.7355, -94.9774],
        'dickinson' => [29.4608, -95.0513],
        'the woodlands' => [30.1658, -95.4613],
        'missouri city' => [29.6186, -95.5377],
        'pearland' => [29.5636, -95.286],
        'friendswood' => [29.5294, -95.201],
        'channelview' => [29.7752, -95.1146],
        'fulshear' => [29.6897, -95.8997],
        'magnolia' => [30.2094, -95.7508],
        'new caney' => [30.1585, -95.2188],
        'pasadena' => [29.6911, -95.2091],
        'porter' => [30.1044, -95.2383],
        'rosenberg' => [29.5572, -95.8086],
        'stafford' => [29.6161, -95.5577],
        'webster' => [29.5377, -95.1183],
        'brookshire' => [29.7861, -95.9511],
        'deer park' => [29.7052, -95.1238],
        'hockley' => [30.0447, -95.8536],
        'la marque' => [29.3685, -94.9713],
        'la porte' => [29.6658, -95.0194],
        'el lago' => [29.5727, -95.0449],
        'fresno' => [29.5386, -95.4472],
        'manvel' => [29.4627, -95.3577],
        'montgomery' => [30.3877, -95.6961],
        'pinehurst' => [30.1716, -95.6825],
        'rosharon' => [29.3522, -95.46],
        'willis' => [30.4246, -95.4788],
        'alvin' => [29.4238, -95.2441],
        'bacliff' => [29.5072, -94.9908],
        'crosby' => [29.9147, -95.0621],
        'cleveland' => [30.3374, -95.0855],
        'dayton' => [30.0466, -94.8855],
        'galveston' => [29.3013, -94.7977],
        'huffman' => [30.0333, -95.1041],
        'meadows place' => [29.6491, -95.5866],
        'santa fe' => [29.378, -95.1057],
        'seabrook' => [29.5641, -95.0252],
        'shoreacres' => [29.6205, -95.0091],
        'south houston' => [29.6633, -95.2355],
        'splendora' => [30.233, -95.161],
        'texas city' => [29.3838, -94.9027],
    ];

    /** @var array<string, string> */
    private const CITY_ALIASES = [
        'sugarland' => 'sugar land',
    ];

    /**
     * Coverage map for the scheduling & dispatch engine: one circle per city
     * with a property count and the city's dominant PropertyWare zone (the
     * "Zone N" that ends up in Jobber job titles).
     */
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);

        return inertia('Inspection/Scheduler', [
            'title' => 'Scheduler',
            'cities' => $this->coverageCities(),
        ]);
    }

    /**
     * @return list<array{name: string, properties: int, zone: string|null, lat: float|null, lng: float|null}>
     */
    private function coverageCities(): array
    {
        $propertyCounts = [];

        $buildings = DB::table('buildings')
            ->select('city', DB::raw('COUNT(*) as n'))
            ->where('active', true)
            ->groupBy('city')
            ->get();

        foreach ($buildings as $row) {
            $key = $this->normalizeCity($row->city);
            if ($key === null) {
                continue;
            }
            $propertyCounts[$key] = ($propertyCounts[$key] ?? 0) + (int) $row->n;
        }

        // Dominant zone per city, from work order history. Zone is a
        // PropertyWare custom field; only 1-5 are real (0 and stray numbers
        // are noise), so anything else is ignored.
        $zoneCounts = [];
        $zoneRows = DB::table('work_orders as w')
            ->join('buildings as b', 'b.propertyware_id', '=', 'w.building_id')
            ->whereIn('w.zone', ['1', '2', '3', '4', '5'])
            ->select('b.city', 'w.zone', DB::raw('COUNT(*) as n'))
            ->groupBy('b.city', 'w.zone')
            ->get();

        foreach ($zoneRows as $row) {
            $key = $this->normalizeCity($row->city);
            if ($key === null) {
                continue;
            }
            $zoneCounts[$key][$row->zone] = ($zoneCounts[$key][$row->zone] ?? 0) + (int) $row->n;
        }

        $cities = [];
        foreach ($propertyCounts as $key => $count) {
            $zones = $zoneCounts[$key] ?? [];
            arsort($zones);
            $coordinates = self::CITY_COORDINATES[$key] ?? null;

            $cities[] = [
                'name' => Str::title($key),
                'properties' => $count,
                'zone' => $zones === [] ? null : (string) array_key_first($zones),
                'lat' => $coordinates[0] ?? null,
                'lng' => $coordinates[1] ?? null,
            ];
        }

        usort($cities, fn (array $a, array $b) => $b['properties'] <=> $a['properties']);

        return $cities;
    }

    private function normalizeCity(?string $city): ?string
    {
        $key = strtolower(trim(trim((string) $city), ','));

        if ($key === '') {
            return null;
        }

        return self::CITY_ALIASES[$key] ?? $key;
    }
}
