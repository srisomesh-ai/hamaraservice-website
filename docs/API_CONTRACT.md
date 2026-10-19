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
| `admin_finance` | GET | admin | – | GMV, commission, online collected, cash commission due, provider balances/dues |
| `mark_paid` | – | – | – | **Removed** (410) |

## providers.php
| Action | Method | Who | Notes |
|---|---|---|---|
| `register` | POST | public | `name, phone (10-digit), email, password (≥8), whatsapp, gender, experience, bio, id_type, id_number, address, city, lat, lng, radius_km, areas[], services[{id,name,icon,cat}]` → `{id, status:'pending'}` — **use the returned `id`** |
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
`register` (POST, Firebase token; upsert profile: `name, phone, email, gender, address, city, lat, lng, fcm_token, auth_method`) — call after every login.
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
