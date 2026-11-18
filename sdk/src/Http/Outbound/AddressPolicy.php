<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/**
 * Which addresses {@see StreamHttpClient} may connect to (SDK 1.122, #2151).
 *
 * Use it when the URL comes from a person (a pasted datasheet link, a webhook
 * address someone typed), so the request can't be pointed at the server's own
 * network: localhost, private ranges, link-local cloud metadata addresses.
 * Calls to fixed API hosts don't need it, and without one nothing changes.
 *
 *     $http = new StreamHttpClient(addressPolicy: AddressPolicy::publicOnly());
 *
 * With a policy, the client:
 *  - refuses a host that is not plain ASCII (send punycode), and numeric
 *    spellings parsers disagree on (`2130706433`, `0x7f.1`, `0177.0.0.1`);
 *  - resolves the host once and refuses the request when ANY address it
 *    resolves to is not allowed;
 *  - connects to the address it checked, so a second DNS answer can't swap
 *    it (DNS rebinding), while TLS still verifies the original host name.
 * A refusal is an {@see HttpFailure} that names the host only.
 */
final class AddressPolicy
{
    /**
     * @param list<array{0: string, 1: int}> $allowed extra ranges, as [packed network, prefix bits]
     * @param \Closure(string): list<string> $resolver
     */
    private function __construct(
        private readonly array $allowed,
        private readonly \Closure $resolver,
    ) {
    }

    /**
     * Public internet addresses only: no loopback, private, shared, link-local,
     * reserved, documentation, benchmark or multicast ranges, in IPv4 or IPv6,
     * including IPv4 written inside IPv6 (`::ffff:10.0.0.1`, `64:ff9b::…`).
     *
     * @param (\Closure(string): list<string>)|null $resolver host name => its
     *        addresses. Defaults to the system's DNS; tests pass their own.
     */
    public static function publicOnly(?\Closure $resolver = null): self
    {
        return new self([], $resolver ?? self::systemResolver(...));
    }

    /**
     * The same policy, also allowing these ranges: a supplier on the
     * deployment's own network, say. Each is a CIDR (`10.20.0.0/16`,
     * `fd00:1::/64`) or a single address.
     *
     * @throws \InvalidArgumentException for a range that is not one
     */
    public function allowing(string ...$cidrs): self
    {
        $allowed = $this->allowed;
        foreach ($cidrs as $cidr) {
            [$address, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $packed = @inet_pton((string) $address);
            if ($packed === false) {
                throw new \InvalidArgumentException("Not an address range: {$cidr}");
            }
            $max = strlen($packed) * 8;
            $prefix = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($prefix < 0 || $prefix > $max) {
                throw new \InvalidArgumentException("Not an address range: {$cidr}");
            }
            $allowed[] = [$packed, $prefix];
        }

        return new self($allowed, $this->resolver);
    }

    /**
     * The address to connect to for $host, checked against this policy.
     *
     * @throws HttpFailure when the host is refused, can't be resolved, or any
     *                     of its addresses is not allowed
     */
    public function resolve(string $host): string
    {
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (str_contains($literal, '.') && !str_contains($literal, ':') && long2ip((int) ip2long($literal)) !== $literal) {
                throw new HttpFailure("The address of {$host} is not written plainly.");
            }
            $addresses = [$literal];
        } else {
            if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) {
                throw new HttpFailure("The host name {$host} is not plain ASCII (use its punycode form).");
            }
            $labels = explode('.', rtrim($host, '.'));
            $last = (string) end($labels);
            if (preg_match('/^(\d+|0x[0-9a-f]*)$/i', $last) === 1) {
                throw new HttpFailure("The address of {$host} is not written plainly.");
            }
            $addresses = ($this->resolver)($host);
            if ($addresses === []) {
                throw new HttpFailure("The host {$host} could not be resolved.");
            }
        }

        foreach ($addresses as $address) {
            if (!$this->permits($address)) {
                throw new HttpFailure("The host {$host} is not on the public internet.");
            }
        }

        return $addresses[0];
    }

    /** Whether this policy lets a request reach $address. */
    public function permits(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($this->allowed as [$network, $prefix]) {
            if (self::inRange($packed, $network, $prefix)) {
                return true;
            }
        }

        return self::isPublic($packed);
    }

    private static function isPublic(string $packed): bool
    {
        if (strlen($packed) === 16) {
            // IPv4 inside IPv6: mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96).
            $prefix12 = substr($packed, 0, 12);
            if ($prefix12 === str_repeat("\0", 10) . "\xff\xff" || $prefix12 === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
                return self::isPublic(substr($packed, 12));
            }
            if ($packed[0] === "\xff") {
                return false; // multicast
            }
        } elseif (ord($packed[0]) >= 224) {
            return false; // multicast and the old class E
        }

        $text = (string) inet_ntop($packed);

        return filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    private static function inRange(string $packed, string $network, int $prefix): bool
    {
        if (strlen($packed) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            $v4 = gethostbynamel($host);
            $addresses = is_array($v4) ? $v4 : [];
        }

        return $addresses;
    }
}
