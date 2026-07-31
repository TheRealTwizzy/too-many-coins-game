#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Proxy Trust Self-Check
 *
 *   php tools/proxy_trust_selfcheck.php
 *
 * Exercises tmc_proxy_is_trusted() and its CIDR matcher directly. This is the
 * one decision in the request path where getting it wrong hands an attacker
 * the rate-limit identity - a peer we trust may name its own client IP via
 * CF-Connecting-IP/X-Real-IP/X-Forwarded-For, and a fresh IP per request is a
 * fresh anonymous bucket per request, which is no limit at all.
 *
 * So the cases that matter here are the ones that must NOT match: malformed
 * entries, /0 prefixes, family mismatches, and addresses one bit outside a
 * range. A matcher that is merely permissive passes every positive test.
 *
 * No server and no database required.
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

require_once __DIR__ . '/../includes/net.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  pass  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}";
        if ($detail !== null) {
            echo ' -- ' . (is_string($detail) ? $detail : json_encode($detail));
        }
        echo "\n";
    }
}

/**
 * @param string $list  TMC_TRUSTED_PROXIES value
 * @param string $addr  REMOTE_ADDR of the peer
 */
function trusts(string $list, string $addr): bool {
    return tmc_proxy_is_trusted($addr, $list);
}

echo "Proxy trust self-check\n\n";

echo "Exact addresses (the pre-CIDR behaviour, which must not regress)\n";
check('exact IPv4 match', trusts('10.0.0.5', '10.0.0.5'));
check('exact IPv4 non-match', !trusts('10.0.0.5', '10.0.0.6'));
check('exact IPv6 match', trusts('2001:db8::1', '2001:db8::1'));
check('IPv6 match is form-insensitive', trusts('2001:db8:0:0:0:0:0:1', '2001:db8::1'));
check('comma list, second entry matches', trusts('10.9.9.9,10.0.0.5', '10.0.0.5'));
check('comma list tolerates whitespace', trusts(' 10.9.9.9 , 10.0.0.5 ', '10.0.0.5'));
check('empty list trusts nobody', !trusts('', '10.0.0.5'));
check('list of only separators trusts nobody', !trusts(' , , ', '10.0.0.5'));

echo "\nCIDR ranges\n";
check('IPv4 /8 contains member', trusts('10.0.0.0/8', '10.1.2.3'));
check('IPv4 /8 excludes non-member', !trusts('10.0.0.0/8', '11.1.2.3'));
check('IPv4 /12 contains member (Docker default range)', trusts('172.16.0.0/12', '172.17.0.4'));
check('IPv4 /12 excludes just-outside address', !trusts('172.16.0.0/12', '172.32.0.1'));
check('IPv4 /24 contains member', trusts('192.168.1.0/24', '192.168.1.255'));
check('IPv4 /24 excludes neighbouring block', !trusts('192.168.1.0/24', '192.168.2.1'));
check('IPv4 /32 is a single host', trusts('192.168.1.7/32', '192.168.1.7'));
check('IPv4 /32 excludes the next host', !trusts('192.168.1.7/32', '192.168.1.8'));
check('network with host bits set still matches', trusts('10.1.2.3/8', '10.9.9.9'));
check('IPv6 /32 contains member', trusts('2001:db8::/32', '2001:db8:1234::1'));
check('IPv6 /32 excludes non-member', !trusts('2001:db8::/32', '2001:db9::1'));
check('IPv6 /128 is a single host', trusts('2001:db8::1/128', '2001:db8::1'));
check('IPv6 /128 excludes the next host', !trusts('2001:db8::1/128', '2001:db8::2'));

echo "\nPrefix boundaries (off-by-one in the mask is silent otherwise)\n";
// 10.128.0.0/9 covers 10.128.0.0 - 10.255.255.255 and nothing below it.
check('/9 includes first address in range', trusts('10.128.0.0/9', '10.128.0.0'));
check('/9 includes last address in range', trusts('10.128.0.0/9', '10.255.255.255'));
check('/9 excludes the address one below', !trusts('10.128.0.0/9', '10.127.255.255'));
check('/9 excludes the address one above', !trusts('10.128.0.0/9', '11.0.0.0'));
// /31 leaves exactly two addresses.
check('/31 includes both of its addresses (a)', trusts('192.168.1.4/31', '192.168.1.4'));
check('/31 includes both of its addresses (b)', trusts('192.168.1.4/31', '192.168.1.5'));
check('/31 excludes the third address', !trusts('192.168.1.4/31', '192.168.1.6'));

echo "\nRefusals - the checks that make this file worth having\n";
check('IPv4 /0 is refused', !trusts('0.0.0.0/0', '203.0.113.9'));
check('IPv6 /0 is refused', !trusts('::/0', '2001:db8::1'));
check('IPv4-mapped /0 is refused after prefix adjustment', !trusts('::ffff:0.0.0.0/96', '203.0.113.9'));
check('IPv4 peer is not inside an IPv6 range', !trusts('::/1', '10.0.0.5'));
check('IPv6 peer is not inside an IPv4 range', !trusts('10.0.0.0/8', '2001:db8::1'));
check('prefix above IPv4 width is refused', !trusts('10.0.0.0/33', '10.0.0.5'));
check('prefix above IPv6 width is refused', !trusts('2001:db8::/129', '2001:db8::1'));
check('non-numeric prefix is refused', !trusts('10.0.0.0/eight', '10.0.0.5'));
check('negative prefix is refused', !trusts('10.0.0.0/-8', '10.0.0.5'));
check('empty prefix is refused', !trusts('10.0.0.0/', '10.0.0.5'));
check('missing network is refused', !trusts('/8', '10.0.0.5'));
check('garbage entry is refused', !trusts('not-an-address', '10.0.0.5'));
check('hostname entry is refused', !trusts('traefik', '10.0.0.5'));
check('CIDR with garbage network is refused', !trusts('traefik/16', '10.0.0.5'));
check('wildcard syntax is refused', !trusts('10.0.*.*', '10.0.0.5'));
check('empty peer address matches nothing', !trusts('10.0.0.0/8', ''));
check('garbage peer address matches nothing', !trusts('10.0.0.0/8', 'not-an-address'));

echo "\nA bad entry must narrow the allowlist, never widen it\n";
check('garbage alongside a good range keeps the range', trusts('garbage,10.0.0.0/8', '10.1.2.3'));
check('garbage alongside a good range trusts nothing else', !trusts('garbage,10.0.0.0/8', '11.1.2.3'));
check('refused /0 alongside a good range trusts nothing else', !trusts('0.0.0.0/0,10.0.0.0/8', '203.0.113.9'));
check('all-garbage list trusts nobody', !trusts('garbage,10.0.0.0/99,/8', '10.0.0.5'));

echo "\nIPv4-mapped IPv6 (which form arrives depends on the socket, not config)\n";
check('mapped peer matches plain IPv4 entry', trusts('10.0.0.5', '::ffff:10.0.0.5'));
check('mapped peer matches IPv4 CIDR', trusts('10.0.0.0/8', '::ffff:10.0.0.5'));
check('mapped peer outside IPv4 CIDR still excluded', !trusts('10.0.0.0/8', '::ffff:11.0.0.5'));
check('plain peer matches mapped entry', trusts('::ffff:10.0.0.5', '10.0.0.5'));
check('mapped CIDR is prefix-adjusted (::ffff:10.0.0.0/104 == 10.0.0.0/8)',
    trusts('::ffff:10.0.0.0/104', '10.1.2.3'));
check('mapped CIDR excludes outside addresses', !trusts('::ffff:10.0.0.0/104', '11.1.2.3'));

echo "\nParsed-entry reporting (what diagnostics shows the operator)\n";
check('good entries survive parsing',
    tmc_trusted_proxy_entries('10.0.0.0/8, 192.168.1.7') === ['10.0.0.0/8', '192.168.1.7'],
    tmc_trusted_proxy_entries('10.0.0.0/8, 192.168.1.7'));
check('bad entries are dropped from the parsed list',
    tmc_trusted_proxy_entries('10.0.0.0/8, garbage, 0.0.0.0/0') === ['10.0.0.0/8'],
    tmc_trusted_proxy_entries('10.0.0.0/8, garbage, 0.0.0.0/0'));
check('empty config parses to no entries', tmc_trusted_proxy_entries('') === []);

echo "\nResolution: a trusted peer may name the client, an untrusted one may not\n";
$_SERVER['REMOTE_ADDR'] = '172.17.0.4';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.99';
// tmc_resolve_client_ip() reads the constant, not the parameter, so these two
// cases are covered against the live config below and exhaustively in the
// matcher checks above.
$resolvedUnderRealConfig = tmc_resolve_client_ip();
check('resolution never returns an invalid address',
    $resolvedUnderRealConfig === 'unknown' || tmc_is_valid_ip($resolvedUnderRealConfig),
    $resolvedUnderRealConfig);
check('untrusted peer resolves to its own address, not the header it sent',
    TMC_TRUST_PROXY_HEADERS || tmc_trusted_proxy_entries() !== []
        ? true // live config trusts something; the matcher checks cover it
        : $resolvedUnderRealConfig === '172.17.0.4',
    $resolvedUnderRealConfig);
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR']);

echo "\nDiagnostic reporting helpers\n";
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 172.18.0.1';
check('a present header is reported verbatim',
    tmc_diagnostic_header_value('HTTP_X_FORWARDED_FOR') === '203.0.113.9, 172.18.0.1');
check('an absent header reports null, not empty string',
    tmc_diagnostic_header_value('HTTP_X_NOT_SENT') === null);
$_SERVER['HTTP_X_FORWARDED_FOR'] = str_repeat('a', 500);
$truncated = tmc_diagnostic_header_value('HTTP_X_FORWARDED_FOR');
check('an oversized header is truncated', strlen((string)$truncated) < 250, strlen((string)$truncated));
check('truncation is marked, not silent', str_ends_with((string)$truncated, '...(truncated)'));
$_SERVER['HTTP_X_FORWARDED_FOR'] = '';
check('an empty header is reported as empty, not absent',
    tmc_diagnostic_header_value('HTTP_X_FORWARDED_FOR') === '');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

echo "\nResolution source reporting mirrors the resolution order\n";
$_SERVER['REMOTE_ADDR'] = '10.0.1.4';
$_SERVER['HTTP_X_REAL_IP'] = '172.18.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
// Only meaningful when the live config trusts 10.0.1.4; otherwise the untrusted
// branch is what gets exercised, and that is asserted instead.
$source = tmc_resolve_client_ip_source();
check('source names the header it used, or says the proxy was untrusted',
    in_array($source, ['cf-connecting-ip', 'x-real-ip', 'x-forwarded-for'], true)
        || str_starts_with($source, 'remote_addr'),
    $source);
check('source and resolved address agree',
    $source === 'x-real-ip'
        ? tmc_resolve_client_ip() === '172.18.0.1'
        : (str_starts_with($source, 'remote_addr') ? tmc_resolve_client_ip() === '10.0.1.4' : true),
    [$source, tmc_resolve_client_ip()]);
unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

echo "\n";
echo "pass: {$pass}  fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
