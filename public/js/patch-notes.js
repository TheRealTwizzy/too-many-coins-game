/**
 * patch-notes.js — hand-maintained release data for the construction page.
 *
 * This is the only version/changelog surface in the project. Update it with
 * every release wave: bump CURRENT_VERSION, rewrite CURRENT_WORK/PROJECTED to
 * match reality, and prepend a PATCH_NOTES entry. Keep entries player-facing:
 * what changed for them, not which files moved.
 */

export const CURRENT_VERSION = '2026.07.30';

/** What is being worked on right now, shown while the gate is up. */
export const CURRENT_WORK = [
    'Cutting over to the rebuilt client as the only client',
    'Sigil family abilities for every tier',
    'Season-wide events',
    'Progression and discovery: features reveal themselves as you reach them',
];

/** Best-guess timeline. Honest ranges beat precise fiction. */
export const PROJECTED = [
    { when: 'Early August 2026', what: 'Theft, boosts and profiles in the rebuilt client' },
    { when: 'August 2026', what: 'Sigil family panel, notifications and account management' },
    { when: 'Later', what: 'Trade, clans and custom seasons — after progression gating settles' },
];

/** Newest first. */
export const PATCH_NOTES = [
    {
        version: '2026.07.30',
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
