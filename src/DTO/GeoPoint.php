<?php

namespace VitisStudio\NativeFirestore\DTO;

use InvalidArgumentException;
use JsonSerializable;

final class GeoPoint implements JsonSerializable
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('GeoPoint latitude must be between -90 and 90.');
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('GeoPoint longitude must be between -180 and 180.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }

    /**
     * @return array{__type: 'geopoint', latitude: float, longitude: float}
     */
    public function toWire(): array
    {
        return ['__type' => 'geopoint', 'latitude' => $this->latitude, 'longitude' => $this->longitude];
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function jsonSerialize(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
