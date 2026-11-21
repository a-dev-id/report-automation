<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Property;

/**
 * The only place that knows how a hotel's booking engine shows up in GA4.
 *
 * properties.booking_engine is {"type": "hostname|event|path", "value": "..."}:
 *   hostname : visitors reached the booking engine's host (hostName equals value).
 *   path     : visitors viewed a booking engine path (pagePath begins with value).
 *   event    : the site fires a GA4 event when someone goes to the booking engine (eventName equals value).
 * Visits are always the sessions metric under that filter, so all three types measure the same thing
 * (sessions that reached the booking engine, or contained the event) and can be compared.
 * "Booking events" (bookingEventsMetric) exist only for the "event" type, where eventCount under the filter is a
 * count of that event. For "hostname" and "path" eventCount would include every event there (page_view
 * included), so it is not a booking figure and is null. eventsMetric() is the raw eventCount used for the
 * per-row Country Performance figure, whose limits are documented there.
 *
 * A missing or invalid definition is "not configured": filter() and the metrics are null. Callers
 * must then report null, never 0 and never a guess.
 */
final class BookingEngineFilter
{
    public const TYPES = ['hostname', 'event', 'path'];

    private function __construct(
        private readonly ?string $type,
        private readonly ?string $value,
    ) {
    }

    public static function fromProperty(Property $property): self
    {
        return self::fromDefinition($property->booking_engine);
    }

    public static function fromDefinition(mixed $definition): self
    {
        if (! is_array($definition)) {
            return new self(null, null);
        }

        $type = is_string($definition['type'] ?? null) ? strtolower(trim($definition['type'])) : null;
        $value = is_string($definition['value'] ?? null) ? trim($definition['value']) : null;

        if ($type === null || ! in_array($type, self::TYPES, true) || $value === null || $value === '') {
            return new self(null, null);
        }

        return new self($type, $value);
    }

    public function isConfigured(): bool
    {
        return $this->type !== null;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    /** GA4 dimension filter (see Ga4Report), or null when not configured. */
    public function filter(): ?array
    {
        return match ($this->type) {
            'hostname' => ['field' => 'hostName', 'op' => 'exact', 'value' => $this->value],
            'path' => ['field' => 'pagePath', 'op' => 'begins_with', 'value' => $this->value],
            'event' => ['field' => 'eventName', 'op' => 'exact', 'value' => $this->value],
            default => null,
        };
    }

    /** Metric that counts booking engine visits under filter(), or null when not configured. */
    public function visitsMetric(): ?string
    {
        return match ($this->type) {
            'hostname', 'path', 'event' => 'sessions',
            default => null,
        };
    }

    /** eventCount of the booking event itself: only for the "event" type, else null. */
    public function bookingEventsMetric(): ?string
    {
        return $this->type === 'event' ? 'eventCount' : null;
    }

    /** Raw eventCount under filter() (all events on the host or path for hostname and path), or null when not configured. */
    public function eventsMetric(): ?string
    {
        return $this->isConfigured() ? 'eventCount' : null;
    }
}
