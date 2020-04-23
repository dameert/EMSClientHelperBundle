<?php

namespace EMS\ClientHelperBundle\Helper\Cache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class CacheHelper
{
    /**
     * @var AdapterInterface
     */
    private $cache;

    const DATE_KEY = 'cache_date';

    public function __construct(AdapterInterface $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $key): ?CacheItemInterface
    {
        return $this->cache->getItem($key);
    }

    public function isValid(CacheItemInterface $item, \DateTime $lastChanged): bool
    {
        if (!$item->isHit()) {
            return false;
        }

        $data = $item->get();
        $cacheDate = \DateTime::createFromFormat(DATE_ATOM, $data[self::DATE_KEY]);

        return $cacheDate > $lastChanged;
    }

    public function getData(CacheItemInterface $item): array
    {
        return $item->get()['data'];
    }

    public function save(CacheItemInterface $item, array $data)
    {
        $now = new \DateTime();

        $item->set([
            self::DATE_KEY => $now->format(DATE_ATOM),
            'data' => $data
        ]);

        $this->cache->save($item);
    }
}
