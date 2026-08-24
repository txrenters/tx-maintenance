<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Vendor;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
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
            'properties' => $this->coverageProperties(),
        ]);
    }

    /**
     * Detail panel for one property pin, loaded on click: the building plus
     * its work order history and an open count. Keyed by propertyware_id —
     * that is what work_orders.building_id references and what the map pins
     * carry as id.
     */
    public function property(Request $request, int $propertywareId): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);

        $building = Building::query()
            ->where('propertyware_id', $propertywareId)
            ->firstOrFail();

        $workOrders = $building->workOrders()
            ->with('service_status:id,name')
            ->orderByDesc('created_date')
            ->limit(10)
            ->get(['id', 'work_order_no', 'description', 'category', 'created_date', 'completed_date', 'service_status_id']);

        $openCount = $building->workOrders()
            ->whereNull('completed_date')
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', self::PW_CLOSED_STATUSES);
            })
            ->whereDoesntHave('service_status', fn ($q) => $q->whereIn('name', ['Closed', 'Paid']))
            ->count();

        return response()->json([
            'id' => (int) $building->propertyware_id,
            'name' => $building->name,
            'address' => implode(', ', array_filter([
                trim((string) $building->address),
                trim((string) $building->city),
                trim(trim((string) $building->state_region).' '.trim((string) $building->postal_code)),
            ])),
            'active' => (bool) $building->active,
            'zone' => $this->buildingZoneMap()[(int) $building->propertyware_id] ?? null,
            'lat' => $building->latitude,
            'lng' => $building->longitude,
            'open_work_orders' => $openCount,
            'total_work_orders' => $building->workOrders()->count(),
            'work_orders' => $workOrders->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'description' => Str::limit((string) $workOrder->description, 120),
                'category' => $workOrder->category,
                'status' => $workOrder->service_status?->name,
                'created_date' => $workOrder->created_date ? substr((string) $workOrder->created_date, 0, 10) : null,
                'completed_date' => $workOrder->completed_date ? substr((string) $workOrder->completed_date, 0, 10) : null,
            ])->values(),
        ]);
    }

    /**
     * One month of Jobber visits for the calendar tab, grouped by day.
     * Visits are pinned on the map when the Jobber property address matches
     * a geocoded building (normalized street + city); unmatched visits still
     * appear in the day list, just without coordinates.
     */
    public function visits(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);

        $month = (string) $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 422);

        $start = $month.'-01 00:00:00';
        $end = date('Y-m-01 00:00:00', strtotime($start.' +1 month'));

        $normalize = fn (?string $value): string => Str::of((string) $value)->squish()->lower()->toString();

        $buildingsByAddress = [];
        DB::table('buildings')
            ->where('active', true)
            ->whereNotNull('latitude')
            ->get(['address', 'city', 'latitude', 'longitude'])
            ->each(function ($building) use (&$buildingsByAddress, $normalize) {
                $key = $normalize($building->address).'|'.$normalize($building->city);
                $buildingsByAddress[$key] ??= $building;
            });

        $days = [];
        DB::table('jobber_visits as v')
            ->join('jobber_jobs as j', 'j.id', '=', 'v.jobber_job_id')
            ->leftJoin('jobber_properties as p', 'p.id', '=', 'v.jobber_property_id')
            ->whereNotNull('v.start_at')
            ->where('v.start_at', '>=', $start)
            ->where('v.start_at', '<', $end)
            ->orderBy('v.start_at')
            ->get(['v.start_at', 'v.assigned_to', 'j.job_number', 'j.title', 'p.street', 'p.city'])
            ->each(function ($visit) use (&$days, $buildingsByAddress, $normalize) {
                $building = $buildingsByAddress[$normalize($visit->street).'|'.$normalize($visit->city)] ?? null;
                $days[substr((string) $visit->start_at, 0, 10)][] = [
                    'job_number' => $visit->job_number,
                    'title' => Str::limit((string) $visit->title, 90),
                    'street' => $visit->street,
                    'city' => $visit->city,
                    // THMP encodes the zone in every job title ("Zone N - ...").
                    'zone' => preg_match('/zone\s*(\d)/i', (string) $visit->title, $matches) === 1 ? $matches[1] : null,
                    'category' => $this->visitCategory((string) $visit->title),
                    'technicians' => collect(json_decode((string) $visit->assigned_to, true) ?: [])
                        ->pluck('name')
                        ->filter()
                        ->values()
                        ->all(),
                    'lat' => $building?->latitude,
                    'lng' => $building?->longitude,
                ];
            });

        // The TBP backlog: this quarter's Tenant Benefit Package jobs with no
        // dated visit. The boss's fill rule anchors on these — inspectors get
        // the current quarter's TBPs within 5 miles of their move in / move
        // out inspections. A job titled for a different quarter is excluded;
        // TBP titles with no quarter marking stay in.
        $currentQuarter = (string) now()->quarter;
        $tbpBacklog = [];
        DB::table('jobber_jobs as j')
            ->leftJoin('jobber_properties as p', 'p.id', '=', 'j.jobber_property_id')
            ->where(function ($query) {
                $query->whereRaw("LOWER(j.title) LIKE '%tenant benefit%'")
                    ->orWhereRaw("LOWER(j.title) LIKE '%tbp%'");
            })
            ->whereNull('j.closed_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('jobber_visits as v')
                    ->whereColumn('v.jobber_job_id', 'j.id')
                    ->whereNotNull('v.start_at');
            })
            ->get(['j.job_number', 'j.title', 'p.street', 'p.city'])
            ->each(function ($job) use (&$tbpBacklog, $buildingsByAddress, $normalize, $currentQuarter) {
                if (preg_match('/q([1-4])/i', (string) $job->title, $matches) === 1 && $matches[1] !== $currentQuarter) {
                    return;
                }
                $building = $buildingsByAddress[$normalize($job->street).'|'.$normalize($job->city)] ?? null;
                if ($building === null) {
                    return;
                }
                $tbpBacklog[] = [
                    'job_number' => $job->job_number,
                    'title' => Str::limit((string) $job->title, 90),
                    'lat' => $building->latitude,
                    'lng' => $building->longitude,
                ];
            });

        return response()->json([
            'month' => $month,
            'days' => $days,
            'tbp_backlog' => $tbpBacklog,
            'tbp_quarter' => 'Q'.$currentQuarter,
        ]);
    }

    /**
     * Bucket a visit by its job title: move in / move out / TBP, everything
     * else is maintenance. The TBP wording matches JobberVisit::scopeTbp().
     */
    private function visitCategory(string $title): string
    {
        $title = Str::lower($title);

        return match (true) {
            str_contains($title, 'move in') || str_contains($title, 'move-in') => 'move_in',
            str_contains($title, 'move out') || str_contains($title, 'move-out') => 'move_out',
            str_contains($title, 'tenant benefit') || str_contains($title, 'tbp') => 'tbp',
            default => 'maintenance',
        };
    }

    /**
     * @return list<array{name: string, properties: int, ungeocoded: int, zone: string|null, lat: float|null, lng: float|null}>
     */
    private function coverageCities(): array
    {
        $propertyCounts = [];
        $ungeocodedCounts = [];

        $buildings = DB::table('buildings')
            ->select('city', DB::raw('COUNT(*) as n'), DB::raw('SUM(CASE WHEN latitude IS NULL THEN 1 ELSE 0 END) as ungeocoded'))
            ->where('active', true)
            ->groupBy('city')
            ->get();

        foreach ($buildings as $row) {
            $key = $this->normalizeCity($row->city);
            if ($key === null) {
                continue;
            }
            $propertyCounts[$key] = ($propertyCounts[$key] ?? 0) + (int) $row->n;
            $ungeocodedCounts[$key] = ($ungeocodedCounts[$key] ?? 0) + (int) $row->ungeocoded;
        }

        $cityZones = $this->cityZoneMap();

        $cities = [];
        foreach ($propertyCounts as $key => $count) {
            $coordinates = self::CITY_COORDINATES[$key] ?? null;

            $cities[] = [
                'name' => Str::title($key),
                'properties' => $count,
                'ungeocoded' => $ungeocodedCounts[$key] ?? 0,
                'zone' => $cityZones[$key] ?? null,
                'lat' => $coordinates[0] ?? null,
                'lng' => $coordinates[1] ?? null,
            ];
        }

        usort($cities, fn (array $a, array $b) => $b['properties'] <=> $a['properties']);

        return $cities;
    }

    /**
     * Active buildings with exact coordinates (filled in by
     * geocode:buildings), each colored by its own dominant zone, falling back
     * to its city's.
     *
     * @return list<array{id: int, name: string, address: string, zone: string|null, lat: float, lng: float}>
     */
    private function coverageProperties(): array
    {
        $buildingZones = $this->buildingZoneMap();
        $cityZones = $this->cityZoneMap();
        $thmpBuildings = $this->thmpBuildingIds();
        $unscheduledBuildings = $this->unscheduledBuildingIds();

        return DB::table('buildings')
            ->where('active', true)
            ->whereNotNull('latitude')
            ->get(['propertyware_id', 'name', 'address', 'city', 'latitude', 'longitude'])
            ->map(function ($building) use ($buildingZones, $cityZones, $thmpBuildings, $unscheduledBuildings) {
                $cityKey = $this->normalizeCity($building->city);

                return [
                    'id' => (int) $building->propertyware_id,
                    'name' => (string) $building->name,
                    'address' => implode(', ', array_filter([
                        trim((string) $building->address),
                        trim((string) $building->city),
                    ])),
                    'zone' => $buildingZones[(int) $building->propertyware_id]
                        ?? ($cityKey !== null ? ($cityZones[$cityKey] ?? null) : null),
                    'thmp' => isset($thmpBuildings[(int) $building->propertyware_id]),
                    'unscheduled' => isset($unscheduledBuildings[(int) $building->propertyware_id]),
                    'lat' => (float) $building->latitude,
                    'lng' => (float) $building->longitude,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * PropertyWare's NATIVE work order statuses that mean the order is done.
     * PW has two status fields: this native one, and the "Service Status"
     * custom field mirrored in service_status_id. Staff often close an order
     * in PW without touching the custom field or Date Completed, so the
     * native status is the authoritative openness signal.
     */
    private const PW_CLOSED_STATUSES = ['Closed', 'Canceled By Tenant'];

    /**
     * Base query for currently open work orders — same open rule as the
     * property panel: no completed_date, native PW status not closed or
     * canceled, Service Status custom field not Closed/Paid.
     */
    private function openWorkOrders(): Builder
    {
        return DB::table('work_orders as w')
            ->leftJoin('service_status as ss', 'ss.id', '=', 'w.service_status_id')
            ->whereNull('w.completed_date')
            ->where(function ($query) {
                $query->whereNull('w.status')->orWhereNotIn('w.status', self::PW_CLOSED_STATUSES);
            })
            ->where(function ($query) {
                $query->whereNull('ss.name')->orWhereNotIn('ss.name', ['Closed', 'Paid']);
            })
            ->whereNotNull('w.building_id');
    }

    /**
     * Buildings (keyed by propertyware_id) with at least one currently open
     * work order assigned to the in-house vendor THMP, matched by the same
     * trimmed case-insensitive name rule as Vendor::isThmp().
     *
     * @return array<int, true>
     */
    private function thmpBuildingIds(): array
    {
        return $this->openWorkOrders()
            ->join('work_order_vendors as wov', 'wov.work_order_id', '=', 'w.id')
            ->join('vendors as v', 'v.id', '=', 'wov.vendor_id')
            ->whereRaw('LOWER(TRIM(v.name)) = ?', [Str::lower(Vendor::THMP_NAME)])
            ->distinct()
            ->pluck('w.building_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * The Service Status custom field value the scheduler works from — the
     * same column the maintenance board shows. Per Earl (2026-08-25) the
     * Unscheduled filter means exactly this status with THMP assigned,
     * nothing broader.
     */
    private const AWAITING_SCHEDULING_STATUS = 'Assigned - Waiting on Scheduling';

    /**
     * Buildings (keyed by propertyware_id) with at least one open work order
     * sitting in "Assigned - Waiting on Scheduling" with THMP assigned —
     * the work the scheduling engine will feed into Jobber.
     *
     * @return array<int, true>
     */
    private function unscheduledBuildingIds(): array
    {
        return $this->openWorkOrders()
            ->where('ss.name', self::AWAITING_SCHEDULING_STATUS)
            ->join('work_order_vendors as wov', 'wov.work_order_id', '=', 'w.id')
            ->join('vendors as v', 'v.id', '=', 'wov.vendor_id')
            ->whereRaw('LOWER(TRIM(v.name)) = ?', [Str::lower(Vendor::THMP_NAME)])
            ->distinct()
            ->pluck('w.building_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Dominant zone per normalized city, from work order history. Zone is a
     * PropertyWare custom field; only 1-5 are real (0 and stray numbers are
     * noise), so anything else is ignored.
     *
     * @return array<string, string>
     */
    private function cityZoneMap(): array
    {
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

        $map = [];
        foreach ($zoneCounts as $key => $zones) {
            arsort($zones);
            $map[$key] = (string) array_key_first($zones);
        }

        return $map;
    }

    /**
     * Dominant zone per building (keyed by propertyware_id), same noise
     * filter as the city map.
     *
     * @return array<int, string>
     */
    private function buildingZoneMap(): array
    {
        $zoneCounts = [];
        $zoneRows = DB::table('work_orders')
            ->whereIn('zone', ['1', '2', '3', '4', '5'])
            ->whereNotNull('building_id')
            ->select('building_id', 'zone', DB::raw('COUNT(*) as n'))
            ->groupBy('building_id', 'zone')
            ->get();

        foreach ($zoneRows as $row) {
            $zoneCounts[(int) $row->building_id][$row->zone] = (int) $row->n;
        }

        $map = [];
        foreach ($zoneCounts as $buildingId => $zones) {
            arsort($zones);
            $map[$buildingId] = (string) array_key_first($zones);
        }

        return $map;
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
