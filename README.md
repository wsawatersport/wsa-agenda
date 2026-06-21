# WSA Agenda — WordPress Plugin

Custom agenda/calendar plugin for WSA Watersport. No third-party calendar plugins. Built for WordPress 6.0+, PHP 8.0+.

---

## Installation

1. Upload the `wsa-agenda/` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Geïnstalleerde plugins**.
3. On activation the plugin:
   - Creates the `/agenda` page with `[wsa_agenda]` shortcode.
   - Seeds the default event categories with their colours.
   - Creates the `wp_wsa_rsvp`, `wp_wsa_vacations`, and `wp_wsa_ics_feeds` database tables.
   - Registers the `wsa_board` user role.

---

## Adding board members

Board members can create, edit, and delete events, and manage categories/vacations/ICS feeds.

**Option A — manually set the role:**
1. Go to **Users** in the WordPress admin.
2. Edit the desired user.
3. Change their role to **WSA Bestuur**.

**Option B — via Google/Apple SSO:**
Users who log in via SSO are created as `subscriber` by default. An admin must then upgrade them to `wsa_board` via **Users → Edit**. (See notes in `class-sso.php` if you want auto-promotion.)

---

## Configuring Google OAuth

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → Credentials.
2. Create an **OAuth 2.0 Client ID** (type: Web application).
3. Add the following as an authorised redirect URI:
   ```
   https://yourdomain.nl/wp-json/wsa/v1/sso/google/callback
   ```
4. In WordPress: **WSA Agenda → Instellingen**, paste your Client ID and Client Secret.

---

## Configuring Apple SSO

1. Sign in to [Apple Developer](https://developer.apple.com/) → Certificates, Identifiers & Profiles.
2. Create a **Services ID** (e.g. `nl.wsawatersport.login`).
3. Enable **Sign in with Apple** for it and add redirect URL:
   ```
   https://yourdomain.nl/wp-json/wsa/v1/sso/apple/callback
   ```
   ⚠️ Apple requires HTTPS and a verified domain.
4. Generate a **Key** with Sign in with Apple enabled. Download the `.p8` file.
5. In WordPress: **WSA Agenda → Instellingen**, fill in:
   - **Services ID** (the client ID you created above)
   - **Team ID** (10-character code from your developer account)
   - **Key ID** (shown in your key list)
   - **Private Key** — paste the entire content of the `.p8` file, including the `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` lines.

---

## Managing the calendar

| Screen | Path |
|--------|------|
| View all events | WSA Agenda → Evenementen |
| Categories | WSA Agenda → Categorieën |
| School vacations | WSA Agenda → Vakanties |
| ICS feeds | WSA Agenda → ICS Feeds |
| SSO settings | WSA Agenda → Instellingen |
| Export/Import | Tools → WSA Agenda Migratie |

**Public holidays** are fetched automatically from `date.nager.at` for the Netherlands (cached 7 days). No configuration needed.

---

## Sandbox → Production migration

### On the sandbox server

1. Go to **Tools → WSA Agenda Migratie**.
2. Review the pre-migration checklist — all items should be green (⚠️ warnings about old events are non-blocking).
3. Click **Exporteren als JSON**. A file like `wsa-agenda-export-YYYY-MM-DD.json` will download.

### On the production server

1. Install and activate the WSA Agenda plugin.
2. Configure OAuth credentials (they are NOT included in the export).
3. Go to **Tools → WSA Agenda Migratie**.
4. Upload the JSON file.
5. Select a mode:
   - **Samenvoegen** — adds/updates events without removing existing ones. Safe for incremental syncs.
   - **Vervangen** — deletes all existing events first, then imports. Use for initial migration.
6. Click **Dry-run** to preview what will happen.
7. Click **Import uitvoeren** to apply.

The import:
- Upserts events by their stable `wsa_event_uuid`.
- Re-downloads attachments from sandbox URLs and registers them in the production Media Library.
- Upserts categories by slug, vacations by name+dates, ICS feeds by URL.
- Returns a completion report: N aangemaakt, M bijgewerkt, K overgeslagen.

**RSVP registrations** and **OAuth credentials** are never exported.

---

## REST API

Base URL: `https://yourdomain.nl/wp-json/wsa/v1`

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/events?start=ISO&end=ISO&category=slug` | Public | List events in range |
| GET | `/events/{id}` | Public | Single event (full detail + attachments) |
| POST | `/events` | Board | Create event |
| PATCH | `/events/{id}` | Board | Update event |
| DELETE | `/events/{id}` | Board | Delete event |
| GET | `/public-blocks?start=ISO&end=ISO` | Public | Merged public blocks (holidays, vacations, ICS) |
| POST | `/events/{id}/rsvp` | Public | Register RSVP |
| GET | `/events/{id}/rsvp` | Board | List RSVPs |
| GET | `/events/{id}/rsvp/csv` | Board | Download RSVP as CSV |
| GET | `/categories` | Public | List categories |

All write endpoints require a valid `X-WP-Nonce` header (nonce action: `wp_rest`).

---

## Database tables

```sql
CREATE TABLE wp_wsa_rsvp (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id   BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(200)    NOT NULL,
    email      VARCHAR(200)    NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY event_id (event_id)
);

CREATE TABLE wp_wsa_vacations (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(200)    NOT NULL,
    start_date DATE            NOT NULL,
    end_date   DATE            NOT NULL,
    regions    VARCHAR(50)     NOT NULL DEFAULT 'Noord,Midden,Zuid',
    PRIMARY KEY (id)
);

CREATE TABLE wp_wsa_ics_feeds (
    id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200)    NOT NULL,
    url  TEXT            NOT NULL,
    PRIMARY KEY (id)
);
```

Tables are created automatically on plugin activation via `dbDelta()`. They are also available as the SQL above for manual setup if needed.

---

## Uninstallation

Deleting the plugin via **Plugins → Verwijderen** will permanently remove:
- All `wsa_event` posts and their meta
- All `wsa_event_category` terms and term meta
- All three custom DB tables and their data
- All plugin options (SSO credentials etc.)
- All transients (holiday/ICS caches, OAuth state)
- The `wsa_board` user role
- The auto-created `/agenda` page (if its content is still `[wsa_agenda]`)

RSVP data is also removed as part of the `wp_wsa_rsvp` table drop. Export any RSVP CSV files beforehand if you need them.

---

## Notes

- **JS bundle size**: `calendar.js` is intentionally under 40 KB unminified. No frameworks or jQuery.
- **WCAG AA**: category colour picker is `<input type="color">`. Administrators should ensure chosen colours pass contrast against white text (4.5:1 ratio). A contrast warning is noted in the admin UI description.
- **ICS import**: only `VEVENT` with `DTSTART`, `DTEND`, and `SUMMARY` are imported. `VALARM` and `VTIMEZONE` are ignored.
- **Apple SSO private key**: stored in `wp_options`. Ensure your WordPress database and `wp-config.php` are secured appropriately.
