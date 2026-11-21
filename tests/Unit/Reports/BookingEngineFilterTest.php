<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Models\Property;
use App\Reports\BookingEngineFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingEngineFilterTest extends TestCase
{
    public function test_hostname_counts_sessions_on_that_host(): void
    {
        $f = BookingEngineFilter::fromDefinition(['type' => 'hostname', 'value' => 'book.example.com']);

        $this->assertTrue($f->isConfigured());
        $this->assertSame(['field' => 'hostName', 'op' => 'exact', 'value' => 'book.example.com'], $f->filter());
        $this->assertSame('sessions', $f->visitsMetric());
        $this->assertSame('eventCount', $f->eventsMetric());
    }

    public function test_path_counts_sessions_that_viewed_a_path_prefix(): void
    {
        $f = BookingEngineFilter::fromDefinition(['type' => 'path', 'value' => '/book']);

        $this->assertSame(['field' => 'pagePath', 'op' => 'begins_with', 'value' => '/book'], $f->filter());
        $this->assertSame('sessions', $f->visitsMetric());
    }

    public function test_event_counts_the_event_itself(): void
    {
        $f = BookingEngineFilter::fromDefinition(['type' => 'event', 'value' => 'click_book_now']);

        $this->assertSame(['field' => 'eventName', 'op' => 'exact', 'value' => 'click_book_now'], $f->filter());
        $this->assertSame('sessions', $f->visitsMetric());   // sessions that contain the event, comparable with hostname and path
        $this->assertSame('eventCount', $f->eventsMetric());
    }

    public function test_booking_events_exist_only_for_the_event_type(): void
    {
        $this->assertSame('eventCount', BookingEngineFilter::fromDefinition(['type' => 'event', 'value' => 'go'])->bookingEventsMetric());
        $this->assertNull(BookingEngineFilter::fromDefinition(['type' => 'hostname', 'value' => 'a.com'])->bookingEventsMetric());
        $this->assertNull(BookingEngineFilter::fromDefinition(['type' => 'path', 'value' => '/book'])->bookingEventsMetric());
        $this->assertNull(BookingEngineFilter::fromDefinition(null)->bookingEventsMetric());
    }

    public function test_all_three_types_measure_sessions(): void
    {
        foreach (['hostname' => 'a.com', 'path' => '/book', 'event' => 'go'] as $type => $value) {
            $this->assertSame('sessions', BookingEngineFilter::fromDefinition(['type' => $type, 'value' => $value])->visitsMetric(), $type);
        }
    }

    public function test_type_and_value_are_trimmed_and_the_type_is_case_insensitive(): void
    {
        $f = BookingEngineFilter::fromDefinition(['type' => ' Hostname ', 'value' => ' book.example.com ']);

        $this->assertSame('hostname', $f->type());
        $this->assertSame('book.example.com', $f->filter()['value']);
    }

    #[DataProvider('missingDefinitions')]
    public function test_a_missing_or_invalid_definition_is_not_configured_and_never_guessed(mixed $definition): void
    {
        $f = BookingEngineFilter::fromDefinition($definition);

        $this->assertFalse($f->isConfigured());
        $this->assertNull($f->type());
        $this->assertNull($f->filter());
        $this->assertNull($f->visitsMetric());
        $this->assertNull($f->eventsMetric());
    }

    public static function missingDefinitions(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['book.example.com'],
            'unknown type' => [['type' => 'url', 'value' => 'x']],
            'missing value' => [['type' => 'hostname']],
            'blank value' => [['type' => 'event', 'value' => '  ']],
            'non-string value' => [['type' => 'event', 'value' => 5]],
        ];
    }

    public function test_it_reads_the_definition_from_the_property(): void
    {
        $property = new Property(['slug' => 'x', 'booking_engine' => ['type' => 'event', 'value' => 'go']]);
        $this->assertSame('event', BookingEngineFilter::fromProperty($property)->type());

        $this->assertFalse(BookingEngineFilter::fromProperty(new Property(['slug' => 'y']))->isConfigured());
    }
}
