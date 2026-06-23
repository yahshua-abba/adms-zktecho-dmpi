<?php

namespace Tests\Feature;

use App\Queries\LogQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogQueryTest extends TestCase
{
    use RefreshDatabase;

    private function deviceLog(array $attrs = []): int
    {
        return DB::table('device_log')->insertGetId(array_merge([
            'data' => 'payload', 'url' => '{}', 'sn' => 'DEV-IN', 'created_at' => '2026-06-17 08:00:00',
        ], $attrs));
    }

    public function test_filters_by_device(): void
    {
        $in = $this->deviceLog(['sn' => 'DEV-IN']);
        $this->deviceLog(['sn' => 'DEV-OUT']);

        $ids = LogQuery::filtered('device_log', ['device' => 'DEV-IN'])->pluck('id')->all();

        $this->assertSame([$in], $ids);
    }

    public function test_filters_by_text_in_data(): void
    {
        $hit = $this->deviceLog(['data' => 'OPLOG firmware update']);
        $this->deviceLog(['data' => 'ATTLOG normal punch']);

        $ids = LogQuery::filtered('device_log', ['q' => 'firmware'])->pluck('id')->all();

        $this->assertSame([$hit], $ids);
    }

    public function test_filters_by_date_range(): void
    {
        $this->deviceLog(['created_at' => '2026-06-01 08:00:00']);
        $mid = $this->deviceLog(['created_at' => '2026-06-10 08:00:00']);
        $this->deviceLog(['created_at' => '2026-06-17 08:00:00']);

        $ids = LogQuery::filtered('device_log', ['date_from' => '2026-06-05', 'date_to' => '2026-06-15'])
            ->pluck('id')->all();

        $this->assertSame([$mid], $ids);
    }
}
