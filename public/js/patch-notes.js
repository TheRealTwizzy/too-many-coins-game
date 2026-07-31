/**
 * patch-notes.js — hand-maintained release data for the construction page.
 *
 * This is the only version/changelog surface in the project, and the only
 * thing anyone can read while the gate is up. Stale content here is worse than
 * none: it tells a visitor the project stopped where the notes did.
 *
 * Update it with every release wave: bump CURRENT_VERSION, rewrite
 * CURRENT_WORK/PROJECTED to match reality, and prepend a PATCH_NOTES entry.
 * Keep entries player-facing — what changed for them, not which files moved.
 * Operator and infrastructure work belongs in commit messages, not here,
 * unless a player would notice it.
 */

export const CURRENT_VERSION = '2026.07.31';

/** What is being worked on right now, shown while the gate is up. */
export const CURRENT_WORK = [
    'Proving one whole season end to end: every sigil, every ability, every screen',
    'Sigil families — wards, sight, the market and the forge — checked against real play',
    'Theft, freezes and boosts balanced so spending beats sitting still',
    'Account safety: confirmed email addresses, and sessions that hold up under load',
];

/** Best-guess timeline. Honest ranges beat precise fiction. */
export const PROJECTED = [
    { when: 'Early August 2026', what: 'One season, fully playable and verified — the closed test starts here' },
    { when: 'August 2026', what: 'Overlapping seasons and Season Lockout, so a finished season leads somewhere' },
    { when: 'Later in 2026', what: 'Global Stars between seasons, and a narrow off-season economy' },
    { when: 'Unscheduled', what: 'Trading and clans — real designs, not yet built, and not promised' },
];

/** Newest first. */
export const PATCH_NOTES = [
    {
        version: '2026.07.31',
        date: '2026-07-31',
        title: 'Your email is yours',
        notes: [
            'New accounts confirm their email address before playing. The link arrives on signup, and you can ask for another if it goes astray.',
            'That confirmation is what lets us check with you before anything important happens to your account.',
            'The maintenance page now signs you out properly when you go to sign in as someone else.',
        ],
    },
    {
        version: '2026.07.30.3',
        date: '2026-07-30',
        title: 'Everything a season needs, in one client',
        notes: [
            'Sigil theft is playable: pick a target, stake your sigils, see the odds before you commit.',
            'Boosts arrive with a catalogue, previews and a live timer on everything running.',
            'The sigil family panel opens up — affinity, wards, the market, sight and the chronicle.',
            'Notifications, player profiles, friends and blocks, and a real look at your own account.',
            'Every icon, sigil and moment in the game now has art behind it instead of a placeholder.',
            'Coins, stars and rates all read from server truth, so the numbers on screen are the numbers you have.',
            'Sigil drops are paced: a long dry spell is now caught and corrected rather than left to luck.',
        ],
    },
    {
        version: '2026.07.30.2',
        date: '2026-07-30',
        title: 'Harder to cheat, harder to break',
        notes: [
            'Chat, leaderboards and season transcripts no longer answer to strangers.',
            'Sixteen ways to bend the game were found and closed — forged notifications, faked identities, spending races.',
            'Buying stars or staking sigils asks you to confirm when the spend is a large share of what you hold.',
            'Freezes and market discounts can no longer be double-spent by acting twice at once.',
        ],
    },
    {
        version: '2026.07.30.1',
        date: '2026-07-30',
        title: 'Low-tier sigils wake up; the season can too',
        notes: [
            'Tier 1 Michael sigils now prime a one-shot deflect that breaks the next theft attempt against you.',
            'Tier 1 and 2 Valefor sigils can now be staked on theft — small stakes, small odds, cheap recon.',
            'Something season-wide stirs when the right sigils meet. You will know it when you hear it.',
            'The game now announces maintenance honestly instead of leaving you guessing.',
        ],
    },
    {
        version: '2026.07.29',
        date: '2026-07-29',
        title: 'The families get names and a fair economy',
        notes: [
            'The seven sigil families are now Goliath, Anak, Michael, Valefor, Mammon, Azazel and Legion.',
            'Theft is worth considering again: punching up pays, punching down never does.',
            'Hoarding no longer beats spending — settle values are a uniform two-thirds of use value.',
            'New players receive five Tier 1 sigils on their first season join, settled fairly at lock-in.',
        ],
    },
    {
        version: '2026.07.28',
        date: '2026-07-28',
        title: 'The rebuilt client arrives',
        notes: [
            'A rebuilt interface: faster, cleaner, focused on the season in front of you.',
            'Live season screen, ranks, chat, shop and sign-in, all rebuilt from the ground up.',
            'Server heartbeat surfaced so the client can tell you when the world last moved.',
        ],
    },
];
