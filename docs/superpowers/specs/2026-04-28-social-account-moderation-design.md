# Social, Account Security, and Moderation Design

## Purpose

Too Many Coins needs a stronger social and account foundation without disturbing the game economy. This design adds standard player account/profile editing, friend and block lists, staff moderation tools, administrator operations, staff-only direct chat, and a more useful notification system.

This patch must not change economic configuration, economic tuning, tick processing, pricing formulas, reward formulas, simulation configuration, or simulation behavior. Admin reset controls may call existing day-0 reset utilities or carefully scoped reset procedures, but the economics themselves are out of scope.

## Existing Context

The current application is a vanilla JavaScript SPA backed by a PHP API and MySQL. Existing schema already includes `players.role`, `profile_visibility`, `profile_deleted_at`, handle history/registry tables, cosmetics, global and season chat, notification storage, friend request/friendship/block tables, and preserve-auth reset tooling.

The current implementation exposes profiles, cosmetics, chat, notifications, and gameplay actions, but it does not yet provide a complete account settings flow, staff panel, admin panel, social graph UI, staff direct chat, or full moderation tooling.

## Roles and Permissions

`players.role` remains the source of truth:

- `Player`: manages their own account, profile, security settings, deletion request, friends, blocks, chats, and notifications.
- `Moderator`: inherits player permissions and can moderate social/account surfaces for regular players.
- `Admin`: inherits moderator permissions and can perform system-level administrative actions.

Backend authorization is required for every privileged endpoint. Hidden UI controls are only a usability layer, not a security boundary.

Moderators can edit regular player account/profile fields, request verified account deletion for regular players, remove chat messages, mute/unmute users, start Staff chat conversations, send staff messages, and send custom notifications.

Admins can do everything moderators can do, plus manage roles and trigger day-0 economy reset actions. Admin reset work must preserve user authentication data and must not alter economy rules or tuning.

## Account and Profile Management

Players get an Account area with tabs:

- Profile: editable display fields such as bio, profile status, visibility, and cosmetic preview.
- Security: password change, email display, and verified email-change request/confirmation.
- Privacy: friends, blocks, and profile visibility.
- Delete: verified account deletion request and confirmation.

Password change requires:

- current password
- new password
- confirm new password

The backend verifies the current password, rejects mismatched confirmation, applies existing password length policy, updates the password hash, and writes an audit event.

Account deletion uses email verification. Player self-deletion sends verification to the player's email. Staff/admin-triggered deletion sends verification to the staff/admin actor's email. The target player receives a notification that account action has been taken. Deletion is soft deletion: clear sessions, prevent login, hide public profile content behind `[Removed]`, and preserve account rows for audit and handle non-reuse.

## Staff Panel

The Staff panel is visible to Moderators and Admins. It provides:

- user search by handle, email, and player id
- active/deleted filters
- user detail view
- account/profile edit controls
- account deletion request flow
- active mute state and moderation history
- recent audit events
- direct Staff chat initiation
- custom notification send controls

Staff actions require a reason where appropriate and always write to the audit log.

Moderators can act on regular players. Admins can act on players and moderators. Role management is admin-only.

## Admin Panel

The Admin panel is visible only to Admins. It provides:

- system status summary
- global day-0 economy reset preserving accounts/auth
- per-player day-0 economy reset preserving account/auth
- role management
- recent administrative audit events

Dangerous actions use high-friction confirmation. Global economy reset requires typed confirmation and an email/token verification step. Per-player economic reset requires a reason and clear impact summary.

Admin reset controls must use existing reset concepts where possible and must not change economic config, tick logic, pricing logic, reward logic, simulation config, or active balancing rules.

## Social Graph

Friend requests, friendships, and blocks will be wired through API and UI using the existing schema unless implementation reveals a concrete schema gap.

Friend features:

- send friend request
- accept/decline friend request
- remove friend
- view friend list
- show friend status on profiles where privacy allows

Block features:

- block player
- unblock player
- view block list
- blocked players cannot send friend requests to the blocker
- blocked player messages can be hidden or visually collapsed for the blocker

There are no player-to-player private messages in this patch.

## Chat

Existing Global and Season chat remain. Chat UX will improve:

- readable timestamps using relative text and exact datetime tooltip/title
- clearer message rows with handle, role badge, timestamp, and content hierarchy
- removed messages shown appropriately for staff and hidden from regular players
- muted state feedback before message send
- contextual staff controls on messages

Chat moderation:

- remove a message with actor and reason metadata
- mute a player for a duration or indefinitely
- unmute a player
- scope mutes as `GLOBAL`, `SEASON`, `STAFF`, or `ALL`

## Staff Chat

Staff chat is separate from notifications and separate from Global/Season chat.

Rules:

- Staff/Admins can initiate a Staff chat conversation with a player.
- Players cannot initiate new Staff chat conversations in this patch.
- Players can reply in their Staff chat conversation.
- Staff chat is private to the target player and staff/admins.
- Staff chat appears as a dedicated `Staff` chat tab.
- Staff chat messages are persisted in dedicated `staff_chat_threads` and `staff_chat_messages` tables.
- Player blocks do not block official Staff chat.

## Notifications

Notifications are a separate event inbox, not a chat system.

The notification system will support:

- gameplay events such as sigil drops, theft/freeze outcomes, boosts, lock-in outcomes, season endings, and reward payouts
- season and global economy events
- account/security events such as password changes, deletion requests, deletion confirmations, and staff actions
- moderation events such as mutes and message removals
- administrative announcements and upcoming gameplay-change notices

Staff/Admin notification tools:

- send custom notification to one player
- send custom notification to all players
- choose category and severity
- include optional structured action/link payload

The notification center will provide better category grouping, unread state, clear timestamps, action links where useful, and easy mark-read/remove controls.

## Data Model

Add one guarded migration following the repository's runtime migration style. The migration must be MySQL-compatible with the existing guarded `INFORMATION_SCHEMA` pattern.

New or extended tables/columns:

- `account_verification_tokens`: hashed token, actor player id, target player id, action type, payload JSON, expiry, consumed timestamp, request IP/user agent metadata.
- `staff_audit_log`: actor, target, action type, reason, before JSON, after JSON, created timestamp.
- `chat_mutes`: target player, actor, scope, optional season id, reason, expires timestamp, revoked timestamp.
- `chat_messages`: removed by, removed at, removal reason, while preserving `is_removed`.
- `staff_chat_threads` and `staff_chat_messages`: direct staff/admin-to-player conversations, target player, staff participants by role, message body, sender, read timestamps, and close/archive metadata.
- `players`: bio/status fields, email verification timestamp, deletion actor/reason metadata.
- `player_notifications`: add category/severity/audience/action payload fields if missing from the existing table, while preserving current notification behavior.

Existing friend, friend request, and block tables must be used unless implementation reveals a concrete schema gap.

## API Surface

Player account:

- `account_get`
- `account_update`
- `account_change_password`
- `account_delete_request`
- `account_delete_confirm`

Social graph:

- `friends_list`
- `friend_requests_list`
- `friend_request_send`
- `friend_request_respond`
- `friend_remove`
- `blocks_list`
- `block_add`
- `block_remove`

Staff:

- `staff_users_search`
- `staff_user_get`
- `staff_user_update`
- `staff_account_delete_request`
- `staff_account_delete_confirm`
- `staff_chat_remove_message`
- `staff_chat_mute_user`
- `staff_chat_unmute_user`
- `staff_chat_start`
- `staff_chat_send`
- `staff_notifications_send_player`
- `staff_notifications_send_all`

Admin:

- `admin_role_update`
- `admin_economy_reset_global`
- `admin_economy_reset_player`

Existing endpoints will be adjusted for authorization, mute checks, improved payloads, and role-aware UI data.

## Email Verification

Introduce a small mail layer configured by environment variables. It must support production email delivery and a dev fallback that records verification links/tokens in logs or audit-visible storage.

Verification token storage must hash tokens at rest, expire tokens, consume tokens once, and bind each token to action type, actor, and target.

## Error Handling and Safety

All privileged actions return clear JSON errors for missing auth, insufficient role, invalid target, expired token, consumed token, and invalid confirmation.

Deletion and reset flows require explicit confirmation and reasons. Staff/admin flows must produce audit rows even when the target is later deleted.

No social/account feature may silently alter live economic balances except the explicit admin reset endpoints. Those endpoints must be isolated, audited, and documented.

## Testing and Verification

Backend coverage will include:

- player account updates
- password change success and failure paths
- deletion request/confirm token behavior
- staff and admin permission boundaries
- role escalation prevention
- chat message removal
- mute send blocking and unmute behavior
- staff chat visibility and reply rules
- friend/block request behavior
- notification creation, broadcast, list, read, and removal
- admin reset authorization boundaries

Manual browser verification will cover:

- Account tabs
- Profile editing
- password change UX
- deletion request UX
- friend/block management
- Global, Season, and Staff chat
- improved timestamps
- staff moderation controls
- Staff panel search/detail/actions
- Admin panel reset and role controls
- notification center category/read/action behavior

Verification must include a diff check that economic config and logic files were not modified unless the user explicitly approves a separate economy change.

## Out of Scope

- Player-to-player private messages
- Economy tuning or simulation changes
- New gameplay mechanics
- Live repository deployment or production environment value changes
- Public test to live promotion
