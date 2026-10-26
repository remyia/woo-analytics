# Woo Pulse ad-spend connectors: build plan

Status: proposal, 2026-10-26. Covers a hosted connector service plus Meta Ads and Google Ads spend sync into the Woo Pulse spend ledger.

## 1. Goal

A store owner clicks "Connect Meta" or "Connect Google Ads" on the Marketing tab, picks an ad account, and from then on daily spend and platform-reported conversion value land in the spend ledger automatically. Blended ROAS, MER, CAC and the platform over-claim check then work without anyone typing numbers.

Out of scope for this phase: campaign-level reporting inside Woo Pulse, writing anything back to the ad platforms, TikTok / Pinterest / Microsoft (same pattern, later).

## 2. Why a hosted service

Both platforms issue OAuth app credentials (client id + secret) to the developer, not to each store. A secret shipped inside a plugin is public. The service holds the secrets, performs the OAuth handshakes, stores refresh tokens, and pushes spend into each store. The plugin never sees platform credentials, only its own store key.

This is also what makes the connectors a recurring-revenue feature: the service is a real running cost and a real differentiator.

## 3. Architecture

```
Store owner's browser
   │  Connect click (plugin opens service URL with store key + return URL)
   ▼
Connector service (Cloudflare Workers + D1)  ◄──OAuth──►  Meta / Google
   │  nightly cron per connection: fetch spend by day
   ▼
Store's WordPress  (POST /wp-json/woo-pulse/v1/spend/sync, HMAC-signed)
   │
   ▼
wp_woo_pulse_spend  (source = meta | google, one row per day per account)
```

Flow:
1. Plugin generates a store key on first use and registers the store with the service (site URL, store key, currency, timezone).
2. "Connect" opens `https://connect.<yourdomain>/meta/start?store=…&return=…`. The service redirects to the platform's OAuth screen.
3. Platform redirects back to the service, which exchanges the code for tokens, lists the user's ad accounts, and shows an account picker (a plain HTML page on the service). The user picks one or more accounts.
4. Service stores the connection, runs a 90-day backfill, and redirects back to the plugin with `?connected=meta`.
5. Every night (store-local 03:00) the service fetches the last 3 days for every connection (platforms restate recent days) and POSTs rows to the store. The plugin upserts by (date, channel, source, external account id).
6. Disconnect from the plugin deletes the connection and its tokens on the service; the plugin keeps the historical rows unless the user chooses to delete them.

## 4. The service

Stack: Cloudflare Workers (you already run one for the GA4 connector), Hono for routing, D1 for storage, Workers Cron Triggers for the nightly sync, Workers Secrets for platform credentials.

Tables (D1):
- `stores` (id, site_url, store_key_hash, currency, timezone, plan, created_at, last_seen_at)
- `connections` (id, store_id, platform, external_account_id, account_name, account_currency, account_timezone, refresh_token_enc, access_token_enc, token_expires_at, status, last_sync_at, last_error, created_at)
- `sync_runs` (id, connection_id, started_at, finished_at, rows_pushed, status, error)

Endpoints:
- `POST /stores/register` (store key, site URL, currency, timezone) → store id
- `GET /{platform}/start` → redirect to OAuth
- `GET /{platform}/callback` → token exchange, account picker
- `POST /{platform}/select` → save chosen accounts, kick off backfill
- `GET /stores/{id}/connections` → status for the plugin's Connections panel
- `POST /connections/{id}/resync?days=N` → manual resync
- `DELETE /connections/{id}` → revoke tokens, delete connection
- Cron: `0 * * * *` hourly, picks connections whose store-local time is 03:00 and runs them

Security:
- Store key: 32 random bytes, stored hashed on the service, sent by the plugin as a bearer token.
- Pushes to the store are signed: `X-WooPulse-Signature: HMAC-SHA256(store_key, timestamp + body)`, 5-minute replay window. The plugin verifies with its own copy of the key.
- Tokens encrypted at rest with AES-GCM using a key held in Workers Secrets; never logged.
- Minimum scopes only (read-only on both platforms).
- Disconnect revokes the platform token (`DELETE /me/permissions` on Meta, `revoke` endpoint on Google) and deletes the row.

Observability: `sync_runs` plus a per-connection `last_error` surfaced in the plugin. Alert on any connection failing 3 nights in a row.

## 5. Meta Ads

Setup (one time, your company):
1. Meta Developer account, app type Business, add Marketing API and Facebook Login for Business.
2. Business Verification for Metro Studios (legal name, address, a business document). Takes 1 to 5 business days.
3. App Review for `ads_read`. Submission needs: privacy policy URL, data deletion instructions URL, a screencast showing a user connecting and seeing spend in Woo Pulse, and a written explanation of each permission's use. Plan on one rejection and resubmission; 1 to 3 weeks total. Until approved, only users with a role on the app can connect, which is enough for development and for your own client stores.
4. Switch the app to Live mode after approval.

Connection:
- Facebook Login for Business with a "config" that requests `ads_read` and `business_management` is not needed; `ads_read` alone lists ad accounts via `/me/adaccounts?fields=id,name,currency,timezone_name,account_status`.
- Exchange the short-lived user token for a long-lived token (60 days). Refresh by re-exchanging before expiry; if the user changes their password or removes the app, the token dies and the plugin shows "Reconnect".
- Alternative for agency-managed stores: a System User token under the agency's Business Manager never expires. Support both: default to user tokens, allow pasting a system user token for agencies.

Data:
- `GET /{ad_account_id}/insights?level=account&time_increment=1&time_range={since,until}&fields=spend,impressions,clicks,actions,action_values&action_attribution_windows=['7d_click','1d_view']`
- Spend → `amount`. Platform revenue → `action_values` where `action_type = 'omni_purchase'` (falls back to `purchase`), using the attribution window chosen in the connection settings (default 7-day click, 1-day view, which matches Ads Manager's default).
- Currency: the ad account's currency. If it differs from the store currency, convert with a daily rate (ECB or Open Exchange Rates) and record the rate on the row; flag the mismatch in the plugin.
- Timezone: the ad account's timezone defines the "day"; store it on the connection and tell the user when it differs from the store.
- Rate limits: insights calls are cheap at account level, one call per connection per night. Backfill of 90 days is one call with `time_increment=1`.

## 6. Google Ads

Setup (one time, your company):
1. Google Cloud project, enable the Google Ads API, create an OAuth client (web application) with the service's callback URL.
2. OAuth consent screen, external, scope `https://www.googleapis.com/auth/adwords`. This scope is "sensitive", so publishing the consent screen triggers Google's brand verification (domain ownership, privacy policy, homepage describing the app). Allow 1 to 2 weeks. While "testing", up to 100 named test users can connect; enough for development.
3. A Google Ads Manager account (MCC) for Metro Studios, then apply for an API developer token from it. New tokens get Test access (test accounts only). Apply for Basic access with the API Access form: describes the tool, read-only reporting. Approval 1 to 2 weeks; this is the long pole.
4. Keep the developer token in Workers Secrets.

Connection:
- Standard OAuth with `access_type=offline` and `prompt=consent` so a refresh token is issued.
- List accessible accounts with `customers:listAccessibleCustomers`, then `customer_client` query to expand manager accounts into child accounts with names and currencies. User picks one or more customer ids. Store `login-customer-id` when the chosen account sits under a manager.
- Access tokens last an hour; refresh with the stored refresh token on each sync.

Data (GAQL, one query per customer per sync):
```
SELECT segments.date, metrics.cost_micros, metrics.conversions, metrics.conversions_value,
       metrics.impressions, metrics.clicks
FROM customer
WHERE segments.date BETWEEN '2026-09-27' AND '2026-10-26'
```
- `cost_micros / 1,000,000` → `amount`. `conversions_value` → `reported_revenue`. Conversion value depends on the account's conversion actions; document that the store should have a purchase conversion with value set.
- Currency and timezone from `customer.currency_code` and `customer.time_zone`, handled the same way as Meta.
- Use `searchStream` with the `google-ads-api` Node client in the Worker, or plain REST (`/v18/customers/{id}/googleAds:searchStream`) to avoid a heavy dependency. REST is enough here.

## 7. Plugin changes

Schema (dbDelta, version bump):
- `wp_woo_pulse_spend` gains `source VARCHAR(20) NOT NULL DEFAULT 'manual'`, `external_id VARCHAR(100) NULL` (platform account id), `synced_at DATETIME NULL`, `fx_rate DECIMAL(12,6) NULL`, and a unique key on `(spend_date, channel, source, external_id)` so nightly pushes upsert instead of duplicating.
- Settings gain `connector_url`, `store_key`, `store_id`, and per-connection display data cached from the service.

REST:
- `POST /spend/sync` (HMAC-verified, no cookie auth): body is `{ source, external_id, rows: [ { spend_date, channel, amount, reported_revenue, currency, fx_rate } ] }`. Upserts, bumps the report cache, returns counts.
- `GET /connections` proxies the service's status for the current store.
- `POST /connections/disconnect` proxies the delete.

UI (Marketing tab, new "Connections" card above the ledger):
- One row per platform: logo, "Connect" or account name + "Last synced 03:12, 30 rows" + Resync + Disconnect.
- Error state with the service's `last_error` in plain words ("Meta token expired, reconnect").
- Spend-by-channel table and ledger get a Source column and a filter (manual / CSV / Meta / Google).
- Settings: service URL (prefilled), store key (masked, with regenerate), "keep synced rows after disconnect" toggle.

Behaviour rules:
- Synced rows are read-only in the ledger (edit/delete hidden) so a manual tweak isn't silently overwritten the next night. Users can still add a manual adjustment row.
- Channel names from connectors are fixed ("Meta", "Google Ads") and auto-added to the channel list.
- If the same date already has a manual row for "Meta", the dashboard shows both and a note; it does not merge them.

## 8. Testing

- Meta: a test ad account under the app (Marketing API supports sandbox ad accounts) plus one real account with small spend. Record API responses as fixtures and replay them in unit tests of the mapping code.
- Google: a test manager account with test customer accounts (available with Test access), fixtures again.
- Service: Miniflare / `wrangler dev` locally, D1 migrations under version control, a contract test that a signed push into a local WordPress (wp-env) produces the expected rows.
- Plugin: the existing staging store with both platforms connected to Metro Studios' own accounts for a two-week soak before any client store.
- Failure drills: expired token, account removed, platform 5xx, store offline during push (service retries with backoff for 24h, then marks the run failed).

## 9. Phases and effort

| Phase | Work | Effort | Blocked by |
|---|---|---|---|
| 0 | Apply for Meta Business Verification, submit Meta App Review, publish Google consent screen, apply for Google developer token Basic access | 2 days of paperwork, then waiting | Nothing; start first |
| 1 | Service skeleton: store registration, HMAC push, D1 schema, cron, plugin `/spend/sync` route and schema migration | 4 days | Nothing |
| 2 | Meta connector end to end with your own app users, backfill, nightly sync, plugin Connections card | 4 days | Phase 1 |
| 3 | Google Ads connector with test users and Test developer token | 4 days | Phase 1 |
| 4 | Currency conversion, timezone handling, error surfacing, resync, disconnect, read-only synced rows | 3 days | Phases 2 and 3 |
| 5 | Soak on staging with real accounts, fixture tests, docs | 3 days | Phase 4 plus platform approvals for non-app-user stores |

Total: about 18 working days of build, spread over 4 to 6 weeks because of the approval queues. Phases 2 and 3 can run in parallel if two people are on it.

## 10. Running costs

- Cloudflare Workers paid plan and D1: under $10 a month at this scale.
- FX rates: Open Exchange Rates free tier covers daily rates.
- Domain and TLS for the connector host: trivial.
- The real cost is support when a token expires or an account is reassigned; the Connections card's error messages are what keep that manageable.

## 11. Decisions needed before starting

1. Whose company goes on the Meta and Google applications. It has to be a verifiable business with a public privacy policy; Metro Studios is the natural choice.
2. Domain for the service, e.g. `connect.metro-studios.com`.
3. Whether agency-managed stores connect with the client's own login (default) or a Metro Studios system user. Support both, but the default affects the UI copy.
4. Attribution window default for Meta's reported revenue (proposal: 7-day click, 1-day view).
5. Whether synced history is deleted on disconnect (proposal: kept, with a toggle).
6. Pricing: whether connectors are included or a paid tier. This changes nothing technical but decides whether the service needs plan checks on `stores`.
