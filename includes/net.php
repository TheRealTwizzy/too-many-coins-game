<?php
/**
 * Too Many Coins - Client address resolution and proxy trust
 *
 * Extracted from api/index.php so the matching logic can be exercised directly
 * by tools/proxy_trust_selfcheck.php. A trust decision that can only be tested
 * by standing up a web server is a trust decision that does not get tested for
 * the cases that matter - malformed entries, family mismatches, off-by-one
 * boundaries - so these live where a unit harness can reach them.
 */
require_once __DIR__ . '/config.php';

function tmc_is_valid_ip(?string $value): bool {
    if (!is_string($value) || $value === '') {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function tmc_is_private_or_reserved_ip(?string $value): bool {
    if (!tmc_is_valid_ip($value)) {
        return false;
    }
    return filter_var(
        $value,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function tmc_extract_first_valid_ip(?string $headerValue): ?string {
    if (!is_string($headerValue) || trim($headerValue) === '') {
        return null;
    }
    $parts = explode(',', $headerValue);
    foreach ($parts as $part) {
        $candidate = trim($part);
        if (tmc_is_valid_ip($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Collapse an IPv4-mapped IPv6 address to its IPv4 form.
 *
 * ::ffff:10.0.0.5 and 10.0.0.5 are the same host, but they are 16 and 4 packed
 * bytes respectively, so a naive comparison treats them as different families
 * and never matches. Which form arrives depends on whether the listening
 * socket is dual-stack - not on anything the operator configured - so an
 * allowlist that only understands one of them works or fails for reasons that
 * look arbitrary from the outside.
 *
 * Returns the address unchanged when it is not IPv4-mapped, and null when it
 * is not an address at all.
 */
function tmc_canonical_ip(?string $value): ?string {
    if (!tmc_is_valid_ip($value)) {
        return null;
    }
    $packed = @inet_pton((string)$value);
    if ($packed === false) {
        return null;
    }
    if (strlen($packed) === 16
        && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
        $v4 = @inet_ntop(substr($packed, 12));
        return $v4 === false ? (string)$value : $v4;
    }
    return (string)$value;
}

/**
 * Split "10.0.0.0/8" into [packed network, prefix bits], or null if unusable.
 *
 * Deliberately rejects a /0 prefix. `0.0.0.0/0` and `::/0` mean "trust every
 * peer", which is TMC_TRUST_PROXY_HEADERS spelled in a way that looks like a
 * restriction - and the whole point of the allowlist is that trusting an
 * arbitrary peer lets it name its own rate-limit identity. Anyone who really
 * wants that has an explicit flag for it.
 */
function tmc_parse_cidr(string $entry): ?array {
    $slash = strpos($entry, '/');
    if ($slash === false) {
        return null;
    }

    $network = trim(substr($entry, 0, $slash));
    $bitsRaw = trim(substr($entry, $slash + 1));
    if ($network === '' || $bitsRaw === '' || !ctype_digit($bitsRaw)) {
        return null;
    }

    $canonicalNetwork = tmc_canonical_ip($network);
    if ($canonicalNetwork === null) {
        return null;
    }

    $bits = (int)$bitsRaw;

    // A network written in IPv4-mapped form carries a 128-bit prefix, so
    // collapsing it to 4 bytes has to drop the 96-bit ::ffff:0:0 prefix from
    // the count as well. ::ffff:10.0.0.0/104 is 10.0.0.0/8.
    if ($canonicalNetwork !== $network && strpos($network, ':') !== false) {
        $bits -= 96;
    }

    $packed = @inet_pton($canonicalNetwork);
    if ($packed === false) {
        return null;
    }

    $maxBits = strlen($packed) * 8;
    if ($bits < 1 || $bits > $maxBits) {
        return null;
    }

    return [$packed, $bits];
}

/**
 * True when $ip falls inside $entry, which may be a bare address or a CIDR
 * range. Host bits set in the network portion are fine (10.1.2.3/8 works).
 */
function tmc_ip_matches_trusted_entry(?string $ip, string $entry): bool {
    $canonicalIp = tmc_canonical_ip($ip);
    if ($canonicalIp === null) {
        return false;
    }

    if (strpos($entry, '/') === false) {
        // Compare packed bytes, not strings. 2001:db8:0:0:0:0:0:1 and
        // 2001:db8::1 are the same host written two legal ways, and an
        // operator who writes the expanded form should not silently get an
        // allowlist that never matches.
        $canonicalEntry = tmc_canonical_ip($entry);
        if ($canonicalEntry === null) {
            return false;
        }
        $packedEntry = @inet_pton($canonicalEntry);
        $packedIp = @inet_pton($canonicalIp);
        return $packedEntry !== false && $packedIp !== false && $packedEntry === $packedIp;
    }

    $parsed = tmc_parse_cidr($entry);
    if ($parsed === null) {
        return false;
    }
    [$network, $bits] = $parsed;

    $packedIp = @inet_pton($canonicalIp);
    if ($packedIp === false || strlen($packedIp) !== strlen($network)) {
        // Different families. An IPv4 peer is not inside an IPv6 range no
        // matter how permissive that range is.
        return false;
    }

    $wholeBytes = intdiv($bits, 8);
    if ($wholeBytes > 0 && strncmp($packedIp, $network, $wholeBytes) !== 0) {
        return false;
    }

    $remainderBits = $bits % 8;
    if ($remainderBits > 0) {
        $mask = (~((1 << (8 - $remainderBits)) - 1)) & 0xFF;
        if ((ord($packedIp[$wholeBytes]) & $mask) !== (ord($network[$wholeBytes]) & $mask)) {
            return false;
        }
    }

    return true;
}

/**
 * Parse TMC_TRUSTED_PROXIES into usable entries, dropping anything malformed.
 *
 * Dropping is the only safe direction - an entry nobody can parse must narrow
 * the allowlist, never widen it - but a silently dropped entry looks exactly
 * like a correctly configured one that is being ignored, so each bad entry is
 * named in the log once per process.
 */
function tmc_trusted_proxy_entries(?string $raw = null): array {
    $raw = $raw ?? (string)TMC_TRUSTED_PROXIES;
    $entries = [];

    foreach (explode(',', $raw) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }

        if (strpos($item, '/') !== false) {
            if (tmc_parse_cidr($item) !== null) {
                $entries[] = $item;
            } else {
                tmc_warn_bad_trusted_proxy_entry($item);
            }
            continue;
        }

        if (tmc_is_valid_ip($item)) {
            $entries[] = $item;
        } else {
            tmc_warn_bad_trusted_proxy_entry($item);
        }
    }

    return $entries;
}

function tmc_warn_bad_trusted_proxy_entry(string $entry): void {
    static $warned = [];
    if (isset($warned[$entry])) return;
    $warned[$entry] = true;

    // A /0 entry is well-formed and deliberately refused, which is a different
    // thing from a typo. Saying so is the difference between the operator
    // fixing their config and the operator concluding the parser is broken.
    $slash = strpos($entry, '/');
    $isZeroPrefix = $slash !== false && trim(substr($entry, $slash + 1)) === '0';

    error_log(
        $isZeroPrefix
            ? '[rate_limit] TMC_TRUSTED_PROXIES entry "' . $entry . '" has a /0 '
                . 'prefix, which would trust every peer, and has been ignored. '
                . 'Use TMC_TRUST_PROXY_HEADERS=1 if that is really what you want.'
            : '[rate_limit] TMC_TRUSTED_PROXIES entry "' . $entry . '" is not a '
                . 'valid address or CIDR range and has been ignored. Ranges must '
                . 'be written like 172.16.0.0/12.'
    );
}

function tmc_proxy_is_trusted(?string $remoteAddr, ?string $trustedList = null): bool {
    if (TMC_TRUST_PROXY_HEADERS) {
        return true;
    }

    foreach (tmc_trusted_proxy_entries($trustedList) as $entry) {
        if (tmc_ip_matches_trusted_entry($remoteAddr, $entry)) {
            return true;
        }
    }

    // A private REMOTE_ADDR is NOT evidence that the peer is a trusted proxy.
    //
    // This used to return true for any private/reserved address, which is the
    // address a containerised deploy always sees - so on the documented
    // Dokploy/Traefik setup every caller was trusted to declare their own IP
    // via CF-Connecting-IP, and could mint a fresh anonymous rate-limit bucket
    // per request simply by changing the header.
    //
    // Trust now has to be configured (TMC_TRUST_PROXY_HEADERS, or the peer
    // matching TMC_TRUSTED_PROXIES). Behind an unconfigured proxy the
    // anonymous tier collapses to one shared bucket keyed on the proxy's
    // address, which is the safe direction to fail: the only traffic in that
    // tier is unauthenticated (login, register), which warrants a tight global
    // ceiling anyway, while every signed-in player already has their own
    // validated session bucket.
    if (tmc_is_private_or_reserved_ip($remoteAddr)) {
        tmc_warn_untrusted_proxy_once($remoteAddr);
    }
    return false;
}

/**
 * Say once, loudly, that proxy headers are being ignored.
 *
 * Silently sharing one bucket is safe but confusing to debug - it looks like
 * the limiter is too aggressive rather than like missing configuration.
 */
function tmc_warn_untrusted_proxy_once(?string $remoteAddr): void {
    static $warned = false;
    if ($warned) return;
    $warned = true;
    if (!isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        && !isset($_SERVER['HTTP_X_REAL_IP'])
        && !isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return; // No proxy headers present; nothing is being ignored.
    }
    error_log(
        '[rate_limit] proxy headers present from ' . (string)$remoteAddr
        . ' but that peer is not a configured trusted proxy, so they are ignored '
        . 'and the anonymous tier is keyed on the peer address. Set '
        . 'TMC_TRUSTED_PROXIES to this address or a range containing it '
        . '(for example 172.16.0.0/12), or TMC_TRUST_PROXY_HEADERS=1, '
        . 'if it really is your reverse proxy.'
    );
}

/**
 * Which input tmc_resolve_client_ip() actually used, for diagnostics.
 *
 * Mirrors the order below deliberately rather than being folded into it: the
 * resolution path is on every request and should not grow a second return
 * value for the benefit of an endpoint that is off by default.
 */
function tmc_resolve_client_ip_source(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!tmc_proxy_is_trusted($remoteAddr)) {
        return tmc_is_valid_ip($remoteAddr) ? 'remote_addr (proxy not trusted)' : 'none';
    }
    if (tmc_is_valid_ip($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null)) {
        return 'cf-connecting-ip';
    }
    if (tmc_is_valid_ip($_SERVER['HTTP_X_REAL_IP'] ?? null)) {
        return 'x-real-ip';
    }
    if (tmc_extract_first_valid_ip($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null) !== null) {
        return 'x-forwarded-for';
    }
    return tmc_is_valid_ip($remoteAddr) ? 'remote_addr (no usable header)' : 'none';
}

/**
 * A caller-supplied header value, safe to echo into the diagnostics payload.
 *
 * Truncated so a deliberately enormous header cannot bloat the response, and
 * returned as null rather than an empty string when absent so the shape stays
 * distinguishable from a header that arrived empty.
 */
function tmc_diagnostic_header_value(string $serverKey): ?string {
    if (!isset($_SERVER[$serverKey])) {
        return null;
    }
    $value = (string)$_SERVER[$serverKey];
    return strlen($value) > 200 ? substr($value, 0, 200) . '...(truncated)' : $value;
}

function tmc_resolve_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!tmc_proxy_is_trusted($remoteAddr)) {
        return tmc_is_valid_ip($remoteAddr) ? $remoteAddr : 'unknown';
    }

    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
    if (tmc_is_valid_ip($cfIp)) {
        return $cfIp;
    }

    $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? null;
    if (tmc_is_valid_ip($realIp)) {
        return $realIp;
    }

    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    $xffIp = tmc_extract_first_valid_ip($forwardedFor);
    if ($xffIp !== null) {
        return $xffIp;
    }

    return tmc_is_valid_ip($remoteAddr) ? $remoteAddr : 'unknown';
}
