<?php

namespace App\Scanner\Support;

final class NativeResolver implements Resolver
{
    public function addresses(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
