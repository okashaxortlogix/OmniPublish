<?php

namespace App\Platforms\Services;

use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Adapters\YouTubeAdapter;
use App\Platforms\Adapters\TikTokAdapter;
use App\Platforms\Adapters\InstagramAdapter;
use InvalidArgumentException;

class PlatformManager
{
    /**
     * @var array<string, PlatformAdapterInterface>
     */
    protected array $adapters = [];

    public function __construct()
    {
        $this->register(new YouTubeAdapter());
        $this->register(new TikTokAdapter());
        $this->register(new InstagramAdapter());
    }

    public function register(PlatformAdapterInterface $adapter): void
    {
        $this->adapters[strtoupper($adapter->getPlatformName())] = $adapter;
    }

    public function get(string $platform): PlatformAdapterInterface
    {
        $key = strtoupper($platform);
        if (!isset($this->adapters[$key])) {
            throw new InvalidArgumentException("Unsupported platform: {$platform}");
        }

        return $this->adapters[$key];
    }

    public function getAllCapabilities(): array
    {
        $caps = [];
        foreach ($this->adapters as $name => $adapter) {
            $caps[$name] = $adapter->getCapabilities()->toArray();
        }
        return $caps;
    }
}
