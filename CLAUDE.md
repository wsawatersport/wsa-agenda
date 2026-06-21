# Agenda — WSA

## Role
Events plus the "Herinner mij" reminder subscription flow.

## Stack
WordPress plugin (wsa-agenda). PHP 8.1 — HARD constraint (server runs 8.1;
do not use 8.2+ syntax or features).

## External API
None external (WordPress-hosted).

## Shared data
- Table: `wp_wsa_subscriptions` — reminder subscriptions
- Reads:  member identity [if reminders tie to members]
- Writes: subscriptions

## Layout
- Main plugin file: `wsa-agenda.php`
- [includes / admin / public dirs]

## Commands
- Deploy: `~/Documents/OpDePoel/deploy.sh`

## Gotchas
- PHP 8.1 only — no 8.2+ syntax
- Deploy via the script; never edit on the server
- UI strings in Dutch; text domain `wsa-agenda`
