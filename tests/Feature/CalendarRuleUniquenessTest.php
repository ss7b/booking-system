<?php

namespace Tests\Feature;

use App\Models\AvailabilityCalendar;
use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarRuleUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_calendar_cannot_have_the_same_rule_twice(): void
    {
        $calendar = $this->makeCalendar();
        $attributes = [
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ];

        $calendar->rules()->create($attributes);

        $this->expectException(QueryException::class);

        $calendar->rules()->create($attributes);
    }

    public function test_a_calendar_can_have_a_different_rule_for_the_same_day(): void
    {
        $calendar = $this->makeCalendar();

        $calendar->rules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ]);
        $calendar->rules()->create([
            'day_of_week' => 1,
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->assertDatabaseCount('calendar_rules', 2);
    }

    public function test_a_rule_can_be_updated_without_being_seen_as_a_duplicate_of_itself(): void
    {
        $calendar = $this->makeCalendar();
        $rule = $calendar->rules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ]);

        $rule->update(['end_time' => '12:00:00']);

        $this->assertSame('12:00:00', $rule->fresh()->end_time);
    }

    private function makeCalendar(): AvailabilityCalendar
    {
        $business = Business::query()->create([
            'name' => 'Calendar Rule Test Business',
            'slug' => 'calendar-rule-'.Str::random(8),
            'timezone' => 'UTC',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);

        return AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Test Calendar',
            'timezone' => 'UTC',
        ]);
    }
}
