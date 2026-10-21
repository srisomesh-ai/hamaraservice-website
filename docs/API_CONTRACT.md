# HamaraService API contract (v2 — MySQL is the only data store)

Base URL: `https://hamaraservice.com/api`
All responses: `{"success":true,"data":…}` or `{"success":false,"error":"message"}` with an HTTP status (400/401/403/404/409/429…).
Clients MUST check `success` and show `error` to the user.

Firebase is used ONLY for: customer Auth (ID token), FCM push. **No app or page may read/write Firebase Realtime Database or Firestore for bookings, providers, prices, OTPs, wallets or reviews.**

## Auth
| Who | Header |
|---|---|
| Customer (app) | `Authorization: Bearer <Firebase ID token>` (`FirebaseAuth.instance.currentUser.getIdToken()`) |
| Customer (web) | `Authorization: Bearer <token from customers.php?action=web_login>` |
| Provider | `Authorization: Bearer <token from providers.php?action=login>` |
| Admin | `Authorization: Bearer <token from admin.php?action=login>` (12 h) |

Old provider tokens are invalid after the JWT secret rotation → provider app must send the user back to login on HTTP 401.

## Booking status machine
```
searching ──accept──▶ price_quoted ──confirm_price──────────────────────▶ confirmed
                         │  ▲                                              │
                   negotiate  (decline/search_another → searching)    verify_otp (start OTP)
                         ▼                                                  ▼
                    negotiating ──accept_counter──▶ confirmed            active
                         │                                                  │ request_completion_otp
                    final_offer                                             │ complete (completion OTP)
                         ▼                                                  ▼
                  negotiation_final ──confirm_price──▶ confirmed         completed ── payment_status: pending → paid
cancel (customer, any state before active) → cancelled
release (provider, any state before active) → searching
```
`payment_status`: `pending` | `paid` | `refunded`. `payment_method`: `online` | `cash`.

## Booking fields (snake_case, as returned)
`id, customer_id, customer_name, customer_phone*, contact_name, contact_phone*, provider_id, provider_name, provider_phone**, provider_rating, provider_photo, provider_lat**, provider_lng**, distance_km, svc_id, svc_name, svc_icon, address, landmark, city, lat, lng, slot_date (YYYY-MM-DD), slot_time, notes, summary (JSON string), est_price, status, quoted_price, counter_price, final_price, confirmed_price, amount, negotiation_status, commission_pct, commission_amt, provider_earns, payment_status, payment_method, started_at, completed_at, paid_at, created_at, accepted_at, rating, review, cancel_reason, cancelled_by`

- `*` customer phones are returned to the provider only from `confirmed` on.
- `**` provider phone/location are returned to the customer only from `confirmed` on.
- Customer additionally gets `start_otp` while status = `confirmed`, and `completion_otp` while status = `active` and the provider has requested it. **Providers never receive OTPs.** Provider gets `completion_otp_requested: 0|1`.
- DECIMAL columns (`lat`, `lng`, `provider_lat`, …, `rating`) arrive as **strings** → parse with `double.tryParse('$v')`.
- Integer money columns may arrive as int or string → parse defensively.

## bookings.php
| Action | Method | Who | Body / query | Notes |
|---|---|---|---|---|
| `create` | POST | customer | `svc_id, svc_name, svc_icon, address, landmark, city, lat, lng, slot_date, slot_time, notes, contact_name, contact_phone, est_price, summary (array or string)` | → `{id, status:'searching'}`. 409 if customer already has an open booking. 412 if no customer profile (call customers.php register first). |
| `get` | GET | customer/provider of booking | `id` | Unassigned provider gets only `{id,status:'searching'|'taken'}` |
| `active` | GET | customer/provider | – | Current open booking for the caller (includes completed-but-unpaid), or `null` |
| `history` | GET | customer/provider | – | List, newest first; `other_party` = provider name (customer) / customer name (provider) |
| `open` | GET | approved provider | – | Searching jobs matching provider's services + radius/city. No phone, no exact lat/lng; has `distance_km`. Also refreshes provider `last_seen`. |
| `accept` | POST | approved provider | `booking_id, quoted_price` | Atomic. 409 "Another provider already took this job" / "Finish your current job first". 402 if commission dues exceed limit. |
| `negotiate` | POST | customer | `booking_id, counter_price` | status must be `price_quoted`; counter < quote |
| `final_offer` | POST | provider | `booking_id, final_price` | status `negotiating`; ≤ quote |
| `accept_counter` | POST | provider | `booking_id` | status `negotiating` → `confirmed` (start OTP generated for customer) |
| `confirm_price` | POST | customer | `booking_id` | status `price_quoted` or `negotiation_final` → `confirmed`. **Price chosen by server** (final_price if set else quoted_price). |
| `decline` / `search_another` | POST | customer | `booking_id` | back to `searching`, provider detached |
| `release` | POST | provider | `booking_id` | provider gives up before start → `searching` |
| `cancel` | POST | customer (before `active`) or admin | `booking_id, reason` | → `cancelled` |
| `generate_start_otp` | POST | customer | `booking_id` | status `confirmed`; returns `{start_otp}` (normally already in `get`) |
| `verify_otp` | POST | provider | `booking_id, otp` | START OTP. status `confirmed` → `active`. Max 5 wrong tries. |
| `request_completion_otp` | POST | provider | `booking_id` | status `active`. Error `TOO_EARLY:<minutes>` (HTTP 425) if job younger than minimum. OTP is pushed to the customer only. |
| `complete` | POST | provider | `booking_id, completion_otp` | status `active` → `completed`; computes `amount, commission_amt, provider_earns`. Errors: `COMPLETION_OTP_NOT_REQUESTED`, `COMPLETION_OTP_REQUIRED`, `Invalid completion OTP`. |
| `cash_collected` | POST | provider | `booking_id` | status `completed` → `payment_status=paid, payment_method=cash`; commission is debited from provider wallet |
| `create_order` | POST | customer | `booking_id` | status `completed`, unpaid → `{order_id, amount (paise), currency, key_id, booking_id}` for Razorpay Checkout. Amount set by server. |
| `razorpay_confirm` | POST | customer | `booking_id, razorpay_order_id, razorpay_payment_id, razorpay_signature` | verifies signature + order; marks paid; credits provider wallet. Webhook does the same if the app dies. |
| `review` | POST | customer | `booking_id, rating (1-5), review` | status `completed`, once |
| `request_review` | POST | provider | `booking_id` | only after paid |
| `admin_list` | GET | admin | `status, city, limit` | |
| `admin_unassigned` | GET | admin | – | `{threshold_min, count, items:[{id, svc_name, svc_icon, city, address, landmark, slot_date, slot_time, customer_name, customer_phone (10-digit), created_at, minutes_waiting, alerted (bool), alerted_at}]}` — `searching` bookings with no provider older than `NO_PROVIDER_ALERT_MIN` (last 24 h), oldest first. |
| `admin_finance` | GET | admin | – | GMV, commission, online collected, cash commission due, provider balances/dues |
| `mark_paid` | – | – | – | **Removed** (410) |

## providers.php
| Action | Method | Who | Notes |
|---|---|---|---|
| `register` | POST | public | `otp_token` (see **otp.php**; required when `REQUIRE_EMAIL_OTP`), `name, phone (10-digit), email, password (≥8), whatsapp, gender, experience, bio, id_type, id_number, address, city, lat, lng, radius_km, areas[], services[{id,name,icon,cat}]` → `{id, status:'pending'}` — **use the returned `id`** |
| `check_email` | GET | public | `email` → `{exists}` |
| `login` | POST | public | `email, password` → `{token, provider, status}`. Pending providers DO get a token (to see the pending screen); suspended/rejected get an error message — show it. |
| `get` | GET | provider (self, `id` optional) / admin → full profile incl. `services` rows `{svc_id, svc_name, svc_icon, min_price, max_price, enabled}`; anyone else → public profile only |
| `update` | POST | provider | `name, phone, whatsapp, gender, experience, bio, address, city, lat, lng, radius_km, available, upi_id, areas, photo` |
| `available` | POST | provider | `{available: 0|1}`; going online requires approved (403 otherwise) |
| `location` | POST | provider | `{lat, lng}` — send every 30–60 s while online / on a job |
| `fcm` | POST | provider | `{fcm_token}` |
| `nearby` | GET | public | `lat, lng, svc_id, radius, city` — no phone numbers |
| `services` | GET `id` / POST | public / provider | POST `services:[{svc_id, enabled, min_price, max_price}]` |
| `save_option_prices` / `get_option_prices` | POST/GET | provider | unchanged |
| `list`, `approve`, `suspend`, `set_status {id,status}`, `delete`, `restore` | | admin | |

## payouts.php
| Action | Who | Notes |
|---|---|---|
| `balance` | provider (admin: `&id=`) | `{total_earned, pending_earned (may be negative), available, commission_due, withdrawn, in_process, completed_bookings}` |
| `history` | provider (admin: `&id=`) | `[{id, amount, account_type, upi_id, bank_name, account_last4, ifsc, status, requested_at, processed_at, note}]` |
| `request` | approved provider | `amount (≥100), account_type 'upi'|'bank', upi_id` or `account_no, ifsc, bank_name` |
| `pending`, `all`, `approve {id,note}`, `reject {id,note}` | admin | approve AFTER transferring money manually |

Wallet: online-paid job → `+provider_earns`; cash-paid job → `−commission_amt`; withdrawal → `−amount` (returned on reject). Provider cannot accept jobs when wallet < −₹1000 (configurable).

## customers.php
Profile rules (`register`, `update`): **gender is set once** — when the stored gender is non-empty a new value is silently ignored (other fields still save; only `male|female|other` accepted). **City** is trimmed, max 60 chars, and ignored (old value kept) when it contains `@` / looks like an e-mail address (autofill guard; a schema migration also cleared existing e-mail-looking cities). Responses never include `password_hash`. `providers.php` `register`/`update` apply the same city rule.

`register` (POST, Firebase token; upsert profile: `name, phone, email, gender, address, city, lat, lng, fcm_token, auth_method, otp_token`) — call after every login. Creating a **new** profile needs `otp_token` (purpose `customer_register`, same e-mail as the Firebase account) when `REQUIRE_EMAIL_OTP` is on; updating an existing profile never does. `web_register` takes `otp_token` too.
`web_register` / `web_login` (website): create / sign in to the **same Firebase email+password account** the mobile app uses (via Firebase Auth REST with the public web API key), so one customer id = same bookings on web and app. Older web-only accounts (`HS-CUST-…`) are moved onto a Firebase account (bookings included) the next time they log in with their password. `update`, `fcm`, `get`, `bookings`, `delete` (anonymises), `web_register`, `web_login`, `change_password`, admin `list`.

## services.php
Public: `all`, `categories`, `get&id=`, `prices&id=`, `price_ranges`, `commissions`. Admin: `save_prices`, `update {id, …, commission_pct}`, `get_all_prices`, `reset_prices`.

## reviews.php
`submit` (customer; same as bookings review), `provider&id=` (public; provider may omit id for own).

## admin.php / notify.php
`admin.php?action=login {username,password}` → `{token}`; `admin.php?action=me`.
`notify.php?action=send {target:'provider'|'customer', id, title, body}`, `notify.php?action=broadcast {audience, title, body}` — admin only.

## Push notifications (FCM data messages)
`data.event` values: `new_booking, price_quoted, price_negotiation, negotiation_final, price_confirmed, booking_confirmed, booking_released, provider_declined, service_started, completion_otp_ready, completed, payment_done, payment_received, booking_cancelled, new_review, review_requested, payout_approved, account_approved, admin_broadcast`; plus `bookingId`, `title`, `body`. Messages are **data-only**: the app must display them itself (flutter_local_notifications) in foreground AND background handlers, and route taps to the booking.

## Additions (Oct 2026)
- **Quote window:** bookings in `price_quoted` / `negotiation_final` carry `quote_expires_in` (seconds). After `QUOTE_TTL_MIN` (default 5) the server releases the provider and the booking returns to `searching`.
- **Cash rule:** `get` / `active` include `cash_allowed` (0/1). `cash_collected` returns **402 `CASH_NOT_ALLOWED: …`** when the provider wallet is below the job's platform fee.
- **Wallet top-up (provider):** `payouts.php?action=topup_order {amount}` → `{order_id, amount (paise), currency, key_id}` for Razorpay Checkout; then `payouts.php?action=topup_confirm {razorpay_order_id, razorpay_payment_id, razorpay_signature}`. The webhook also credits top-ups. Each top-up is credited once.
- **Provider base location:** `providers.php?action=update {base_lat, base_lng, radius_km}`. Matching uses the pinned base if set, else the live `lat/lng`. `radius_km` is clamped to 1–50. `register` also accepts `base_lat/base_lng`.
- **Login errors:** `providers.php?action=login` → **404 `NOT_REGISTERED: …`** for an unknown email, **400 `Incorrect password…`** for a wrong password. `customers.php?action=web_login` returns `NOT_REGISTERED: …` (404) when it can tell.
- **Site settings (public):** `site.php?action=config` → `{support_whatsapp, support_email, customer_app_url, provider_app_url, web_login_mode, email_otp_required}` (from `hs-secrets.php`).
- **Admin:** `admin.php?action=data_counts`, `admin.php?action=wipe_test_data {confirm:'DELETE TEST DATA', bookings, customers, providers}`. `bookings.php?action=admin_finance` adds `payments_mode` (`test` / `live`).
- `slot_time` up to 80 chars, e.g. `"Morning (8 AM – 12 PM) · 9 AM"`, with `slot_date` = the chosen day.

## otp.php — email verification code on registration (Oct 2026)
Both apps and the web app verify the e-mail with a 6-digit code **before** creating the account. Google sign-in was removed from the customer app.

| Action | Method | Body | Result |
|---|---|---|---|
| `send` | POST | `{email, purpose}` — `purpose`: `customer_register` \| `provider_register` | `{sent:true, email, expires_in:600, resend_in:60}` — the code is e-mailed, **never returned** |
| `verify` | POST | `{email, purpose, code}` | `{otp_token, email, purpose, expires_in:1800}` |

Rules
- `send` rejects an e-mail that is already registered: customer → `Email already registered. Please login.`; provider → `Email already registered` (400).
- Code: 6 digits (`random_int`), stored only as a keyed hash, valid **10 min**, single use; a new `send` replaces the previous code.
- Rate limits (429, body has `retry_after` seconds + `Retry-After` header, message says how long to wait): 1 send per **60 s** per e-mail + purpose, **5** sends per e-mail per hour, **20** sends per IP per hour.
- `verify`: max **5** attempts per code (`Incorrect code. N attempts left.` 400; then 429 `Too many wrong attempts. Please request a new code.`). Expired → 400 `Code expired. Please request a new code.`
- Mail not configured / sending failed → **503** `Could not send email, please try again` (details only in the server log).
- `otp_token` = HS256 JWT `{typ:'email_otp', email, purpose, exp: now+30 min}`. It is **not** a login token.

Enforcement (`customers.php` `register` when it creates a new profile, `web_register`; `providers.php` `register`)
- `otp_token` sent → always checked (signature, expiry, `purpose`, e-mail case-insensitive = registering e-mail); otherwise 400 `Email verification expired. Please verify your email again.`
- No `otp_token` → rejected (400 `Please verify your email with the code we send you…`) **only when** `REQUIRE_EMAIL_OTP` is true (`hs-secrets.php`, default false; public as `site.php` `email_otp_required`). A Firebase account whose e-mail Firebase already marks verified may create its profile without a token.
- Turn `REQUIRE_EMAIL_OTP` on only after the updated apps are live on Google Play (older versions can't send the token).


## "No provider found" admin alert (Oct 2026)
- A booking still `searching` with no provider **`NO_PROVIDER_ALERT_MIN`** minutes (default 5) after `created_at` is e-mailed **once** to **`ADMIN_ALERT_EMAIL`** (default `SUPPORT_EMAIL`): service, city, booking id, customer name + phone (tel: + WhatsApp links), address, slot, minutes waiting, link to the admin panel. Bookings older than 24 h, cancelled or accepted are skipped.
- Column `bookings.unassigned_alerted_at` (DATETIME NULL) is claimed atomically (`UPDATE … WHERE unassigned_alerted_at IS NULL`, mail only when 1 row changed) → no duplicates across concurrent requests. Hidden from customer/provider responses.
- The check (`api/alerts_lib.php` `checkUnassignedBookings()`) runs at most once per 60 s (temp-file timestamp) piggy-backed on `bookings.php` `get`, `active`, `open`, `admin_list`, `admin_unassigned`. Mail errors are logged (`error_log`), never returned.
- Optional cron safety net: `GET /api/cron.php?action=unassigned&key=<CRON_KEY>` → `{alerts_sent:N}` (403 when `CRON_KEY` is empty or wrong). Hostinger → Advanced → Cron Jobs, every 5 minutes:
  `curl -fsS "https://hamaraservice.com/api/cron.php?action=unassigned&key=YOUR_CRON_KEY" >/dev/null`
- Admin panel: red **"Waiting for provider (N)"** card on the Dashboard + nav badge, and a *Waiting for provider* page (Call / WhatsApp buttons, minutes waiting, "Alert emailed ✓"), refreshed every 15 s; new arrivals show a toast.

## Website visitor analytics (Oct 2026)
First-party analytics for the public website (no third-party scripts). `js/hs-track.js` (deferred) is on every public page — static HTML, `get-app.html`, `legal-pages.html` and the PHP SEO pages (`seo/lib.php` footer) — but **not** on `admin.html`, `hs-prices.html` or `/app/*`. It sends nothing when `navigator.doNotTrack === '1'` (or Global Privacy Control is on) or when the host is not hamaraservice.com / www / localhost / 127.0.0.1.

Browser ids: visitor `hs_vid` (random, localStorage, cookie fallback, 1 year); visit `hs_sess` `{id, t}` (localStorage so tabs share it, sessionStorage fallback) — a new visit starts after 30 min without activity, or when the visitor arrives from another site / a utm / gclid / fbclid link. Each page view has a random `pid`.

### track.php (public collector)
`POST /api/track.php`, body = JSON sent as `text/plain` (sendBeacon / fetch keepalive, no CORS pre-flight), max 4 KB. All ids `[A-Za-z0-9_-]{8,32}`.

| `a` | Body | Notes |
|---|---|---|
| `pageview` | `{vid, sid, pid, path, title, ref, utm:{source,medium,campaign,term,content}, gclid:0/1, fbclid:0/1, screen:'WxH', lang, tz}` | → `{ok:1, new_session}`. First page view of a `sid` creates the visit (source, landing page, utm, IP, location, device) and adds 1 to `visitors.visits`. A resent `pid` is ignored. |
| `heartbeat` | `{vid, sid, pid, secs, final?}` | `secs` = active (visible) seconds on that page so far; sent every 15 s while visible and as a beacon on hide / pagehide. Server adds only the increase, capped by the wall-clock time since the page view (max 4 h) → `{ok:1, added}`. |
| `event` | `{vid, sid, pid, type, who, target, path}` | `type`: `install_click` (HS_getApp / HS_login / `[data-hs-getapp]` / Play Store / `/get-app.html` links), `whatsapp_click` (wa.me, HS_support), `call_click` (`tel:`); `who`: `customer` \| `provider`. The same type+who within 3 s counts once. `install_click` sets `visitors.converted_customer` / `converted_provider`. |

- Ignored (HTTP 200 `{ignored:'bot'}`, nothing stored): missing/short UA, bots, crawlers, link-preview fetchers, headless / automation browsers, HTTP libraries.
- `{ignored:…}` also for: foreign `Origin` (403), unknown session/page, another visitor's `sid`. Bad JSON / ids / action → 400, body > 4 KB → 413, GET → 405.
- Rate limit `TRACK_RATE_PER_MIN` (120) hits per IP per minute → 429 `{ignored:'rate'}` + `Retry-After: 60`.
- IP = `REMOTE_ADDR`; `CF-Connecting-IP` / left-most `X-Forwarded-For` only when `TRUST_PROXY` is true.
- Path sanitising (client + server): `utm_*`, click ids and any parameter whose name looks personal (mail, phone, name, address, token, otp, code, key, session, lat/lng, upi, …) or whose value contains `@` or 7+ digits is dropped. Referrer and click targets are stored as scheme/host/path only (no query).
- Source: `gclid` → Google; `fbclid` → Facebook (Instagram when the referrer / utm says so); known `utm_source` (google, bing, whatsapp/wa, facebook/fb, instagram/ig, youtube/yt); else referrer host (Google incl. `android-app://…googlequicksearchbox`, Bing, WhatsApp incl. `android-app://com.whatsapp`, Facebook, Instagram, YouTube); no / own-site referrer → Direct (Other when only an unknown utm_source); anything else → Other.
- Device / OS / browser parsed from the UA server-side (simple regexes).
- Location: IPinfo `IPINFO_URL/<ip>/json[?token=IPINFO_TOKEN]` (2 s timeout) **after** the response is sent, only for public IPs not cached; cached in `ip_geo` for 30 days; failures cached as `ok=0` and retried after 1 h; HTTP 429 → 1 h back-off.

Tables: `visitors` (vid PK, first/last seen, visits, total_seconds, pageviews, first source/referrer/landing/utm, device/os/browser, last IP + city/region/country/org, converted_customer/provider), `visitor_sessions` (sid PK, vid, visit_no, started_at, last_seen, seconds, pageviews, source, referrer, landing, utm_*, has_gclid/has_fbclid, ip, city, region, country, country_code, org, device, os, browser, screen, lang, tz), `visitor_pageviews` (id, sid, vid, pid, path, title, at, seconds), `visitor_events` (id, sid, vid, type, who, path, target, at), `ip_geo` (ip PK, ok, city, region, country, country_code, org, looked_up_at). Created by `api/schema.php` (`HS_SCHEMA_VERSION 2026-10-21-3`).

Retention (`vsCleanup`, at most once a day piggy-backed on track.php / visitors.php, or `GET /api/cron.php?action=visitors_cleanup&key=<CRON_KEY>`): IPs on visits/visitors last seen more than `VISITOR_IP_RETENTION_DAYS` (90) ago are set to NULL (city/country kept); `ip_geo` rows older than 30 days deleted; page views and events older than `VISITOR_PAGEVIEW_RETENTION_DAYS` (180) deleted.

### visitors.php (admin — `requireAdmin()`, 401 otherwise)
| Action | Params | Result |
|---|---|---|
| `summary` | `range=today\|7d\|30d` | `{online_now, visitors, new_visitors, returning_visitors, sessions, avg_session_seconds, total_seconds, pageviews, clicks:{install_customer, install_provider, install_other, install_customer_visitors, install_provider_visitors, whatsapp, call}, top_cities, top_sources, top_campaigns, top_landing, top_pages, devices, daily}` |
| `list` | `range, kind=visitors\|sessions, source, city, converted=customer\|provider\|any\|none, q (IP/city/region/network/page/campaign), page, per (≤100)` | `{kind, total, page, per, items}` — visitor rows carry `online` and `last_session` |
| `detail` | `vid` | `{visitor, sessions:[… , pages:[{path,title,at,seconds}], events:[…]]}` (latest 50 visits; 404 unknown) |
| `live` | — | `{online_now, items}` — visits seen in the last 2 min with `current_path/title`, location, source, `idle_seconds` |
| `cleanup` | POST | runs the retention clean-up now → `{cleanup:{…counts}}` |

Config (`hs-secrets.php`): `IPINFO_TOKEN` (optional, free ipinfo.io account = 50k/month; empty = unauthenticated, low daily limit), `IPINFO_URL` (default `https://ipinfo.io`), `TRUST_PROXY` (false), `VISITOR_IP_RETENTION_DAYS` (90), `VISITOR_PAGEVIEW_RETENTION_DAYS` (180), `TRACK_RATE_PER_MIN` (120).

Admin panel → **Visitors** (green "online now" badge in the sidebar): KPI cards, top cities / sources / landing pages / pages / devices / campaigns, *Live now* table, filterable + paginated visitors table, tap a row for the visit timeline. Refreshed every 15 s. The admin panel is usable at phone width (sidebar becomes a ☰ drawer below 900 px; wide tables scroll inside their card).
