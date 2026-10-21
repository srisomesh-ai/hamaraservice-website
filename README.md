# HamaraService

Website for https://hamaraservice.com (PHP 8 on Hostinger/Apache + static HTML/JS).
The API in `api/` is used by the two Android apps (customer `com.hamaraservice.customer`,
partner `com.hamaraservice.provider`) and by `admin.html` / `hs-prices.html`.

## App-only website

The website does **not** do login or booking. Every login / register / book button goes to
the right app install via `js/hs-site.js`:

- `HS_getApp('customer' | 'provider')` — Play Store link when `CUSTOMER_APP_URL` /
  `PROVIDER_APP_URL` are set in `~/hs-secrets.php`, otherwise `/get-app.html?for=…`
  (which shows "Coming soon on Google Play — WhatsApp us for early access").
- Links with `data-hs-getapp="customer|provider"` are switched to the Play Store link automatically.
- `/app/customer.html` and `/app/provider.html` show a full-screen "use the app" panel.

When the Play Store listings are live, add to `~/hs-secrets.php` (outside public_html):

```php
'CUSTOMER_APP_URL' => 'https://play.google.com/store/apps/details?id=com.hamaraservice.customer',
'PROVIDER_APP_URL' => 'https://play.google.com/store/apps/details?id=com.hamaraservice.provider',
'WEB_LOGIN_MODE'   => 'app',   // 'web' re-enables the website login + booking web app
```

Note: if your existing `hs-secrets.php` contains `'WEB_LOGIN_MODE' => 'web'`, change it to
`'app'` (or delete the line — the default is now `'app'`).

## SEO pages

All generated from `seo/data.php` (services, partner trades, cities) by `.htaccess` rewrites:

| URL | Template |
|---|---|
| `/electrician-in-hyderabad` (service × city) | `seo/service-city.php` |
| `/driver-jobs-in-visakhapatnam` (trade × city, JobPosting) | `seo/jobs-city.php` |
| `/services-in-hyderabad` (city hub) | `seo/city.php` |
| `/jobs`, `/jobs-in-hyderabad` | `seo/jobs-hub.php` |
| `/cities` | `seo/cities.php` |
| `/sitemap.xml` | `seo/sitemap.php` |

Add a city or service in `seo/data.php` and every page, hub, footer link and the sitemap
update automatically. After changing `seo/data.php`, refresh the head tags and link blocks
in the static pages (`svc-*.html`, `index.html`):

```
php seo/build-static.php
```

Job pages use rolling dates (`jobs_epoch` in `seo/data.php`): `datePosted` moves forward every
30 days and `validThrough` is always 30–60 days ahead, so postings never expire.

## Google Search Console (one-time, no code needed)

1. Open https://search.google.com/search-console → **Add property** → **URL prefix** →
   `https://hamaraservice.com/`.
2. Choose **HTML file** verification, download the file Google gives you
   (e.g. `google1234abcd.html`) and upload it **unchanged** to `public_html/` with the
   Hostinger File Manager. Open `https://hamaraservice.com/google1234abcd.html` to check it
   loads, then click **Verify**. Keep the file there.
   (Alternative: the **Domain** property with a DNS TXT record in Hostinger → Domains → DNS.)
3. In Search Console → **Sitemaps**, submit `sitemap.xml`
   (full URL `https://hamaraservice.com/sitemap.xml`).
4. Optional: **URL inspection** → paste a page such as
   `https://hamaraservice.com/electrician-in-hyderabad` → **Request indexing**.
5. Check **Enhancements → Job postings** after a few days for the `/…-jobs-in-…` pages.

`robots.txt` allows everything except `/api/`, `/app/`, `/admin.html`, `/hs-prices.html`,
`/database/` and `/seo/` (the templates; the public URLs are the clean ones).
