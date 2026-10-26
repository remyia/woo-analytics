# Woo Pulse

Snapshot analytics for WooCommerce inside wp-admin. One screen, six tabs: Overview, Geography, Marketing, Customers, Products, Settings.

Built for store owners and marketers who want to answer, without exporting anything:

- Where is revenue coming from, by state and country, on a map, month by month?
- Is paid media paying for itself overall (blended ROAS)? What does marketing cost as a share of revenue (MER)?
- Which channels bring orders, using the attribution WooCommerce already records on every order?
- Who is new vs returning, do customers come back (cohorts, repeat rate, LTV), and when do people buy?
- What is the estimated gross profit after COGS, fees and marketing?

## Requirements

- WordPress 6.2+, PHP 7.4+
- WooCommerce 7.1+ (works with High-Performance Order Storage and with legacy post-based orders)
- Order attribution data (the Channels report) appears on stores running WooCommerce 8.5+ with order attribution enabled
- COGS-based profit appears on stores recording cost of goods on orders (WooCommerce 10.3+ feature); otherwise the margin % from the setup quiz is used

## Install

1. Zip the `woo-pulse` folder (the folder containing `woo-pulse.php`).
2. In wp-admin go to Plugins > Add New > Upload Plugin, upload the zip, and activate.
3. Open **Pulse** in the admin menu. The first visit to the Marketing tab starts the setup quiz.

Administrators and Shop Managers can see the dashboard. An extra role can be granted under Pulse > Settings.

## How the numbers are defined

Amounts are shown in the store currency using WooCommerce's own symbol position, thousand separator, decimal separator and decimals (WooCommerce > Settings > General). Orders placed in other currencies by a currency-switcher plugin are summed without conversion.

All dates use the site timezone. Ranges are inclusive. Comparison is either the previous period of equal length or the same dates one year earlier.

| Metric | Definition |
|---|---|
| Gross sales | Sum of order totals as charged (includes tax and shipping) for orders with a counted status |
| Net sales | Gross sales minus tax, shipping and refunds |
| Revenue | Net sales or gross sales minus refunds, whichever the quiz selected. Used for ROAS, MER, map and charts |
| Refunds | Refund records dated inside the range whose parent order has a counted status |
| AOV | Gross sales / orders |
| New customer | First counted order ever for that customer (identified by billing email, else account id) falls inside the range |
| Ad spend | Sum of ledger entries in the range |
| Fixed costs | Monthly amounts from the quiz, prorated per day (amount / days in that month) |
| Blended ROAS | Revenue / ad spend |
| New-customer ROAS | Revenue from customers acquired in the range / ad spend |
| MER | Revenue / (ad spend + fixed costs), shown as a ratio or as spend % of revenue |
| CAC | (Ad spend + fixed costs) / new customers |
| Break-even ROAS | 1 / gross margin fraction |
| Contribution after marketing | Revenue × gross margin − (ad spend + fixed costs) |
| Estimated gross profit | Net sales − COGS − payment fees (COGS from orders when at least half the orders carry it, else net × margin %) |
| Platform over-claim | Sum of platform-reported revenue entered in the ledger / actual revenue |
| Repeat purchase rate (range) | Customers with 2+ orders in the range / customers in the range |
| Lifetime value | Lifetime revenue (revenue basis, refunds subtracted) per customer across all counted orders; average and median |
| Active customers | Customers with a counted order in the last N days (default 90) |
| Cohort retention | Customers grouped by first-order month over the last 24 months of orders; % with an order N months later |

Orders with a status not in the counted list (pending, failed, cancelled by default) are excluded from every figure except the status breakdown on the Overview tab.

## Spend ledger CSV format

Paste or upload a CSV on the Marketing tab. A header row is optional and detected automatically.

```
date,channel,amount,reported_revenue,note
2026-10-01,Meta,412.50,1890.00,Prospecting + retargeting
2026-10-01,Google,230.00,,Brand + Shopping
10/02/2026,Meta,"$398.10",1702.33,
```

- Dates accept `YYYY-MM-DD`, `YYYY/MM/DD`, `MM/DD/YYYY`, `DD.MM.YYYY`, `DD-MM-YYYY`
- Amounts accept currency symbols and thousands separators
- `reported_revenue` and `note` are optional. Reported revenue is what the ad platform claims; it powers the "platform vs blended" check
- Turn on "Replace existing entries for the same date and channel" to re-import a corrected export

## Ad platform connectors

Spend can also arrive automatically from the Woo Pulse Connect service (a separate Cloudflare Worker, see the `woo-pulse-connect` project). Enter the service URL under Settings > Connectors, then on the Marketing tab click "Enable connectors" and connect a platform. Synced rows carry a source of `meta` or `google`, are read-only in the ledger, and upsert one row per ad account per day. Pushes are authenticated with an HMAC-SHA256 signature over the request body using the store key; no WordPress login is involved.

## Weekly snapshot email

Enable under Settings, add recipients, and a summary of the last 7 full days vs the 7 before goes out every Monday at 07:00 site time through WP-Cron. "Send a test" emails it to you immediately.

## Performance notes

- Reports are cached for 10 minutes and invalidated (at most once a minute) when orders, refunds or settings change. "Refresh" bypasses the cache.
- Lifetime customer figures (LTV, cohorts, top customers) scan all orders and are cached for an hour.
- The map loads D3 and US state outlines from public CDNs. If those requests are blocked, a tile-grid map is shown instead.

## Uninstall

Deleting the plugin always removes its cron event and cached reports. Settings and the spend ledger are deleted only if "Remove all plugin data on uninstall" is enabled under Settings.
