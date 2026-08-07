# NexA AI — Master Reference Document
> **Purpose:** Complete technical and operational reference for any developer or AI model working on this codebase.
> **Last Updated:** 2026-08-07 | **Owner:** NexZen Institute

---

## 1. WHAT IS THIS PRODUCT?

**NexA AI** is an AI-powered study assistant web application built for students in West Bengal, India. It is owned and operated by **NexZen Institute**.

- **Live URL:** https://ai.nexzen.live
- **Admin Panel:** https://ai.nexzen.live/admin-login.html → https://ai.nexzen.live/admin
- **Hosting:** ServerbYt shared hosting (cPanel at `cp.serverbyt.in`)
- **Server path:** `/home/sites/14a/2/234da30a10/nexa-ai/`
- **Local dev folder:** `C:\Users\MD. SHAHNAWAZ\Downloads\NEXZEN AI\`
- **Tech Stack:** Pure PHP backend, Vanilla HTML/CSS/JS frontend, SQLite databases
- **PWA:** Yes — installable as an app on Android/iOS via `manifest.json`

### Target Audience
West Bengal students preparing for:
- **WBJEE** (West Bengal Joint Entrance Examination)
- **JENPAS UG** (Nursing entrance)
- **ANM/GNM** (Nursing diplomas)
- **WBP / KP** (West Bengal Police / Kolkata Police)
- **SSC** (Staff Selection Commission)
- **West Bengal Board Exams**

### Language
- Primary UI: English
- AI responses: **Bengali** by default (system prompt hardcoded)
- English responses: Only for English grammar questions or when explicitly requested

---

## 2. REPOSITORY & FILE STRUCTURE

```
NEXZEN AI/
├── index.html              ← Main chat app (single page)
├── login.html              ← User login/register page
├── admin.html              ← Admin dashboard (requires admin session)
├── admin-login.html        ← Admin login page
├── nexa-app.js             ← Main app JavaScript (v43) — all chat UI logic
├── nexa-engine.js          ← AI engine, streaming, quiz rendering
├── nexa-main.css           ← Main stylesheet (v40)
├── admin-engine.js         ← Admin dashboard JS
├── admin-tabs.js           ← Admin tab logic
├── admin-style.css / admin-tabs.css ← Admin styles
├── sw.js                   ← Service Worker (PWA offline caching)
├── manifest.json           ← PWA manifest
├── .htaccess               ← Apache rules: CORS, security, clean URLs
├── .env                    ← SECRET KEYS (never commit)
│
├── api/                    ← All PHP backend endpoints
│   ├── config.php          ← Loads .env, defines all constants
│   ├── db.php              ← All database logic, schema, rate limiting
│   ├── ask.php             ← Main AI chat endpoint (Gemini streaming)
│   ├── quiz-api.php        ← Quiz generation & retrieval
│   ├── deepseek-fallback.php ← Fallback AI if Gemini fails
│   ├── bedrock-fallback.php  ← AWS Bedrock fallback (Tier 3)
│   ├── auth-login.php      ← User login
│   ├── auth-register.php   ← User registration (sends OTP)
│   ├── auth-verify-otp.php ← OTP verification → creates account
│   ├── auth-logout.php     ← Clears session + cookie
│   ├── auth-check.php      ← Returns current session/profile
│   ├── auth-profile.php    ← Update profile
│   ├── auth-google.php     ← Google OAuth login
│   ├── auth-forgot-password.php  ← Sends reset OTP
│   ├── auth-reset-password.php   ← Applies new password
│   ├── admin-login.php     ← Admin login (separate session)
│   ├── admin-check.php     ← Checks admin session
│   ├── admin-stats.php     ← Dashboard stats (users, queries, graphs)
│   ├── admin-limit.php     ← Update global daily limit
│   ├── admin-config.php    ← Update feature flags & config
│   ├── admin-clear-cache.php ← Wipe AI response cache
│   ├── admin-announcement.php ← Set announcement banner
│   ├── admin-subscriptions.php ← View/manage subscriptions
│   ├── subscribe.php       ← Create Razorpay payment order
│   ├── verify-payment.php  ← Verify payment + upgrade user plan
│   ├── leaderboard.php     ← Top quiz scorers (public)
│   ├── report-problem.php  ← User bug/problem reports
│   ├── sync-history.php    ← Sync chat history across devices
│   ├── push-broadcast.php  ← Send FCM push notifications
│   ├── save-fcm-token.php  ← Register device for push
│   └── telegram-webhook.php ← Telegram bot handler
│
└── data/                   ← Runtime data (NOT in git, server-only)
    ├── nexa.sqlite         ← Main database (users, subscriptions, etc.)
    ├── nexa-cache.sqlite   ← Cache database (quizzes, AI responses)
    ├── crash_log.txt       ← PHP fatal error log
    └── quiz-error.log      ← Quiz-specific error log
```

---

## 3. ENVIRONMENT VARIABLES (`.env`)

The `.env` file lives in the **root** of `nexa-ai/` on the server. Never hardcode these.

```ini
GEMINI_API_KEY=AIzaSy...           # Google Gemini API key
ADMIN_EMAIL=admin@nexzeninstitute.in  # Admin login email
ADMIN_PASSWORD_HASH=$2y$12$...     # bcrypt hash of admin password
                                   # Generate: password_hash('pass', PASSWORD_BCRYPT, ['cost'=>12])
GOOGLE_CLIENT_ID=600840176497-...  # Google OAuth client ID
GROQ_API_KEY=gsk_...               # Groq API key (reserved/not yet active)
DEEPSEEK_API_KEY=sk-...            # DeepSeek API key (quiz + chat fallback)
RAZORPAY_KEY_ID=rzp_...            # Razorpay payment key
RAZORPAY_KEY_SECRET=...            # Razorpay secret
FIREBASE_SERVER_KEY=AAAA_...       # FCM push notification server key
```

> **⚠️ IMPORTANT:** `config.php` loads `.env` manually (no dotenv library). It uses `file()` to read line by line, splits on `=`, and calls `putenv()`.

---

## 4. DATABASES

All data is stored in **SQLite** files inside the `data/` directory.

### 4a. Main Database — `data/nexa.sqlite`

| Table | Purpose |
|-------|---------|
| `users` | Registered accounts. Fields: email, password_hash, auth_token, type (email/google/pro/premium), name, country, state, district, pin, address, bonus_limit, verified, referral_code |
| `pending_users` | Unverified registrations awaiting OTP. Auto-cleaned after 15 min |
| `rate_limits` | Daily AI usage per user. Key: `user-{email}` or `appmode-{deviceId}` |
| `ip_limits` | Hourly per-IP hard cap (300/hour) against session bypass |
| `login_attempts` | Brute-force protection. Max 5 attempts, 15-min lockout |
| `api_usage_log` | Token usage per request (input + output tokens) |
| `daily_stats` | Total questions asked per day (for admin graph) |
| `recent_queries` | Last 100 user queries (live firehose in admin) |
| `analytics_subject` | Query count by subject (Math/Science/Nursing/Programming/General) |
| `bug_reports` | User-submitted problem reports |
| `global_config` | Key-value config (daily limits, feature flags, maintenance mode) |
| `subscription_plans` | Plan definitions (free/pro/premium) |
| `user_subscriptions` | Active subscription records |
| `payments` | Razorpay payment records |
| `fcm_tokens` | Firebase push notification tokens |
| `telegram_users` | Telegram bot user registry |
| `password_resets` | OTP-based password reset tokens |
| `otp_requests` | Rate limiting for OTP email sends |

### 4b. Cache Database — `data/nexa-cache.sqlite`

| Table | Purpose |
|-------|---------|
| `quizzes` | Cached MCQ questions (topic, difficulty, options A-D, correct answer, explanation) |
| `user_quiz_history` | Which quizzes each user answered and if correct |
| `cache_responses` | Cached AI chat answers (keyed by MD5 hash of question + system prompt). Auto-evicts after 14 days |

---

## 5. API ENDPOINTS REFERENCE

### Authentication
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/auth-check.php` | GET | None | Returns session status, profile, today's usage count |
| `/api/auth-login.php` | POST | None | `{email, password}` → sets session + cookie |
| `/api/auth-register.php` | POST | None | `{email, password, name, ...}` → sends OTP email |
| `/api/auth-verify-otp.php` | POST | None | `{email, otp}` → creates account, sets session |
| `/api/auth-logout.php` | POST | Session | Clears session + `nexa_token` cookie |
| `/api/auth-google.php` | POST | None | `{credential}` → Google JWT login |
| `/api/auth-forgot-password.php` | POST | None | Sends reset OTP to email |
| `/api/auth-reset-password.php` | POST | None | `{email, otp, new_password}` → updates password |
| `/api/auth-profile.php` | POST | Session | Update name, address, etc. |

### AI Chat
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/ask.php` | POST | Session/Cookie/AppMode | Streaming SSE endpoint. Sends question to Gemini, falls back to DeepSeek, then Bedrock |
| `/api/quiz-api.php` | POST | Session/Cookie | `{action: 'generate'|'get'|'answer'}` |

### Payments
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/subscribe.php` | POST | Session | `{plan_name}` → Creates Razorpay order |
| `/api/verify-payment.php` | POST | Session | `{razorpay_payment_id, order_id, signature, plan_name}` → Verifies HMAC, upgrades plan |

### Admin (All require `$_SESSION['admin_logged_in'] = true`)
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/admin-login.php` | POST | `{email, password}` → sets admin session |
| `/api/admin-check.php` | GET | Returns `{loggedIn: true/false}` |
| `/api/admin-stats.php` | GET | Full dashboard data |
| `/api/admin-limit.php` | POST | `{daily_limit: N}` → updates global limit |
| `/api/admin-config.php` | GET/POST | Read/write feature flags and config values |
| `/api/admin-clear-cache.php` | POST | Wipes AI response cache |
| `/api/admin-announcement.php` | POST | Set banner announcement |
| `/api/admin-subscriptions.php` | GET | List all subscriptions |

### Public/Misc
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/leaderboard.php` | GET | Top 10 quiz scorers today (anonymized) |
| `/api/report-problem.php` | POST | Submit bug report |
| `/api/save-fcm-token.php` | POST | Register push notification token |
| `/api/push-broadcast.php` | POST | Admin: send push to all users |
| `/api/ping.php` | GET | Health check (`OK`) |

---

## 6. AI PIPELINE

### Tier 1 — Gemini 2.5 Flash (Primary)
- **Endpoint:** `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent`
- **Mode:** Server-Sent Events (SSE streaming)
- **Config:** `thinkingBudget: 0` (no reasoning tokens billed), `temperature: 0.1`, `topP: 0.8`
- **Max output tokens:** 350 for text, 800 for image/OCR requests
- **System prompt (compressed ~45 tokens):** `"Rules: Be concise. Use bullets. Cite facts accurately. CRITICAL: Respond exclusively in intact Bengali with an exam-oriented scholarly tone. Output English ONLY for English grammar/subject questions or if explicitly demanded. Never fabricate medical doses or exam stats."`

### Tier 2 — DeepSeek `deepseek-chat` (Fallback)
- **Triggers when:** Gemini returns non-200 HTTP code
- **Endpoint:** `https://api.deepseek.com/chat/completions`
- **Mode:** SSE streaming, OpenAI-compatible format
- **Note:** Images are skipped (not supported). Responses reformatted to Gemini-style JSON for the frontend.

### Tier 3 — AWS Bedrock (via Lambda proxy)
- **Triggers when:** DeepSeek also fails
- **Configured via:** `BEDROCK_PROXY_URL` + `BEDROCK_PROXY_SECRET` in `.env`
- **Not yet fully active** (proxy URL is empty in current `.env`)

### Cache Layer
- Before hitting any AI: MD5 hash of `normalize(question) + system_prompt` is checked in `cache_responses`
- On cache hit: response is streamed word-by-word (simulated streaming, 8ms delay per word)
- On cache miss + successful AI response: answer is stored (if >50 chars and not an error string)
- Cache TTL: **14 days** (auto-eviction on startup)

---

## 7. USER AUTHENTICATION FLOW

```
Register:
  POST /api/auth-register.php → stores in pending_users → sends OTP email
  POST /api/auth-verify-otp.php → moves to users table → sets PHP session + nexa_token cookie (1 year)

Login:
  POST /api/auth-login.php → verifies bcrypt hash → sets PHP session + nexa_token cookie
  Brute-force: 5 attempts max, 15-min lockout per email

Session Restoration:
  Every page load → GET /api/auth-check.php
  1. Checks $_SESSION['user_email']
  2. Falls back to $_COOKIE['nexa_token'] → DB lookup → restores session

Google OAuth:
  POST /api/auth-google.php with Google JWT credential → verifies client_id → upsert user

Admin Login (SEPARATE from user auth):
  POST /api/admin-login.php → checks ADMIN_EMAIL + bcrypt ADMIN_PASSWORD_HASH
  Sets $_SESSION['admin_logged_in'] = true (NOT the user session)
```

---

## 8. RATE LIMITING

Implemented in `db.php → check_nexa_limit()`:

1. **Per-IP hourly limit:** 300 requests/hour (uses `ip_limits` table, 1-hour rolling window). Reads real IP from `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `REMOTE_ADDR`.

2. **Per-user daily limit:** Configurable via admin. Default 100. Stored in `global_config['daily_limit']`. Checked against `rate_limits` table.

3. **Rate limit key format:**
   - Logged-in web users: `user-{email}` (note: check stores as just email in some places — minor inconsistency)
   - App/guest users: `appmode-{deviceId}` (from `X-Device-Id` header or `device_id` body field)

4. **Subscription tiers** (defined but NOT yet enforced in rate limiter):
   - Free: 20/day
   - Pro: 100/day
   - Premium: 9999/day

> ⚠️ **Known Bug:** The `check_nexa_limit()` function only reads the single `daily_limit` global config key. It does NOT look up the user's subscription plan. All users get the same limit regardless of payment status.

---

## 9. SUBSCRIPTION & PAYMENT SYSTEM

**Payment Provider:** Razorpay (India)

**Plans (stored in `subscription_plans` table):**
| Plan | Price | Daily Limit | Features |
|------|-------|-------------|---------|
| Free | ₹0 | 20 questions/day | Voice, Basic Quiz |
| Pro | ₹99/month | 100 questions/day | Camera, Full Quiz, Offline, No Ads |
| Premium | ₹199/month | Unlimited (9999) | All Pro + Priority Speed + Badge |

**Payment Flow:**
1. User clicks upgrade → `POST /api/subscribe.php {plan_name}` → creates Razorpay order → returns `order_id`
2. Frontend opens Razorpay checkout widget
3. User pays → Razorpay calls frontend callback with `{payment_id, order_id, signature}`
4. Frontend calls `POST /api/verify-payment.php` with all 3 values
5. Server verifies HMAC-SHA256 signature → updates `user_subscriptions` → sets `users.type = 'pro'|'premium'`
6. Plan is active for **30 days** from payment date

---

## 10. QUIZ SYSTEM

**Two types of quizzes:**

### Type 1 — Official Mock Quiz (preset topics)
- Triggered by the pill button: "Free ANM/GNM Mock Quiz" (or other exam modes)
- Fetches from `quizzes` table (pre-seeded questions)
- Tracks answers in `user_quiz_history`

### Type 2 — Custom AI Quiz (any topic)
- Triggered by the 🧠 button → quiz modal
- User inputs topic, number of questions (5/10/15/20), difficulty (easy/medium/hard)
- `POST /api/quiz-api.php {action: 'generate', topic, count, difficulty}`
- **Primary:** DeepSeek `deepseek-chat` generates JSON MCQs
- **Fallback:** Gemini 2.5 Flash generates MCQs
- Generated questions are stored in `quizzes` table for reuse (cache)
- Response format: `{questions: [{question, opt_a, opt_b, opt_c, opt_d, correct_answer, explanation}]}`

**Leaderboard:** Top 10 by quiz score today, names are anonymized (first name + last initial).

---

## 11. ADMIN PANEL

**URL:** https://ai.nexzen.live/admin
**Login:** https://ai.nexzen.live/admin-login.html

**Admin Credentials (current):**
- Email: Set in `.env` as `ADMIN_EMAIL`
- Password: bcrypt hash stored in `.env` as `ADMIN_PASSWORD_HASH`

**Admin Panel Features:**
- Live stats: total questions today, active users, 7-day graph
- User list: all registered users with type/location
- Recent queries: live firehose of last 100 questions
- Subject analytics: pie chart of Math/Science/Nursing/etc.
- Daily limit control: change the per-user daily cap live
- Feature flags: enable/disable voice, camera, quiz, subscriptions, maintenance mode
- Cache management: wipe the AI response cache
- Announcement banner: show a site-wide message to all users
- Subscription management: view all paid subscriptions

**⚠️ Session key:** All admin endpoints check `$_SESSION['admin_logged_in']`.
`admin-login.php` sets `$_SESSION['admin_logged_in'] = true`.
`admin-limit.php` and a few older files still check `$_SESSION['admin']` — these need updating.

---

## 12. GLOBAL CONFIG FLAGS (in `global_config` table)

These are editable live from the admin panel without any code changes:

| Key | Default | Description |
|-----|---------|-------------|
| `daily_limit` | 100 | Global per-user daily AI question limit |
| `free_daily_limit` | 20 | Free plan limit (not yet enforced in rate limiter) |
| `pro_daily_limit` | 100 | Pro plan limit |
| `premium_daily_limit` | 9999 | Premium plan limit |
| `voice_enabled` | 1 | Show/hide voice input button |
| `camera_enabled` | 1 | Show/hide image attach button |
| `quiz_enabled` | 1 | Enable quiz features |
| `subscription_enabled` | 1 | Enable subscription/payment UI |
| `maintenance_mode` | 0 | If 1, shows maintenance page to all users |
| `maintenance_message` | "..." | Text shown during maintenance |
| `announcement_text` | "" | Banner text (empty = hidden) |
| `announcement_active` | 0 | 1 = show announcement banner |
| `app_version_required` | 1.0.0 | Minimum app version gate |
| `app_update_url` | play.google.com/... | App update URL |
| `welcome_message` | "" | Welcome message for new users |
| `free_trial_days` | 0 | Trial days for new signups |

---

## 13. FRONTEND ARCHITECTURE

- **Single Page App** — `index.html` + `nexa-app.js` (46KB) + `nexa-engine.js` (47KB)
- No framework (vanilla JS)
- **CDN dependencies:**
  - `marked.js` v9 — Markdown rendering
  - `DOMPurify` v3 — XSS sanitization
  - `KaTeX` 0.16.9 — Math formula rendering
  - `highlight.js` 11.9.0 — Code syntax highlighting
  - Google Fonts: Outfit, JetBrains Mono, Hind Siliguri (Bengali)

**Key UI Components:**
- Streaming chat interface with SSE reader
- Image/PDF attachment (base64 encoded, sent to Gemini vision)
- Voice input (Web Speech API)
- Quiz modal with timer
- Profile drawer (usage bar, referral code, bookmarks, settings)
- Splash screen on first load
- Toast notification system
- Dark/light theme toggle (persisted in localStorage)
- Scroll-to-bottom FAB

**Referral System:**
- Code = `NX-` + first 4 chars of `MD5(email)` (computed client-side and server-side consistently)
- Referrer gets +20 bonus questions when referred user verifies OTP

---

## 14. SECURITY SETUP

### `.htaccess` Rules
- Blocks direct access to: `/data/`, `/api/config.php`, `/.env`, debug/test PHP files
- Blocks access to `.json`, `.env`, `.toml` files (except `manifest.json`)
- Security headers: HSTS, CSP (`frame-ancestors`), `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`
- ModSecurity bypass for Telegram webhook

### PHP Security
- All passwords: PHP `password_hash()` / `password_verify()` (bcrypt)
- All DB queries: PDO prepared statements (no SQL injection possible)
- Session cookies: `secure=true`, `httponly=true`, `samesite=Lax`
- Auth tokens: `bin2hex(random_bytes(32))` (cryptographically secure)
- Admin: completely separate session from user session

### Input Validation
- Email: `filter_var()` + `FILTER_VALIDATE_EMAIL`
- Password: min 8 chars, 1 uppercase, 1 number
- All API inputs: sanitized via regex or cast to expected types

---

## 15. KNOWN BUGS & PENDING FIXES

| # | Severity | File | Issue | Status |
|---|---------|------|-------|--------|
| 1 | 🔴 Fixed | `admin-login.php` | Session key `admin` → `admin_logged_in` | ✅ Fixed locally, upload needed |
| 2 | 🔴 Fixed | `admin-stats.php` | Same session key bug | ✅ Fixed locally, upload needed |
| 3 | 🔴 Fixed | `db.php` | SQLite `ts` column crash broke quiz | ✅ Fixed locally, upload needed |
| 4 | 🔴 Config | Live `.env` | Wrong password hash + wrong admin email | ❌ Must fix on server manually |
| 5 | 🟠 Security | `admin-stats.php` | CORS `*` on admin endpoint | ✅ Fixed locally |
| 6 | 🟠 Security | `quiz-api.php` | CORS `*` | ✅ Fixed locally |
| 7 | 🟠 Security | `admin-limit.php` | CORS `*` + old session key | ❌ Not yet fixed |
| 8 | 🟠 Security | `config.php` | DeepSeek key was hardcoded as fallback | ✅ Fixed locally |
| 9 | 🟡 Logic | `db.php` | Rate limiter ignores subscription plan | ❌ Not yet fixed |
| 10 | 🟡 Logic | `leaderboard.php` | References `u.profile` column that doesn't exist in schema | ❌ Will error silently |
| 11 | 🟡 Logic | `auth-verify-otp.php` | OTP expiry check uses 600s (10 min) but register says 15 min | ✅ Fixed (email now says 15 min) |
| 12 | 🟢 Info | Multiple | `admin-limit.php`, `admin-announcement.php` use old `$_SESSION['admin']` key | ❌ Not yet fixed |

---

## 16. DEPLOYMENT

### How to Deploy (Manual via cPanel)
1. Log into cPanel: `cp.serverbyt.in` with credentials `admin@nexzeninstitute.in` / `Masum@2107`
2. Go to **File Manager** → navigate to `nexa-ai/`
3. Upload files into the correct subdirectory
4. For `.env` changes: edit directly in cPanel File Manager text editor

### Files to Upload After Any Code Change
| File | Server Path |
|------|------------|
| `api/ask.php` | `nexa-ai/api/` |
| `api/quiz-api.php` | `nexa-ai/api/` |
| `api/db.php` | `nexa-ai/api/` |
| `api/config.php` | `nexa-ai/api/` |
| `api/admin-login.php` | `nexa-ai/api/` |
| `api/admin-stats.php` | `nexa-ai/api/` |
| `api/auth-*.php` | `nexa-ai/api/` |
| `index.html` | `nexa-ai/` |
| `nexa-app.js` | `nexa-ai/` |
| `nexa-engine.js` | `nexa-ai/` |
| `nexa-main.css` | `nexa-ai/` |
| `.env` (edit in place) | `nexa-ai/` |

> **Note:** There is no CI/CD. All deployments are manual file uploads via cPanel or FTP. FTP scripts exist (`ftp_upload.ps1`, `deploy_all.py`) but require credentials to be set.

---

## 17. TELEGRAM BOT

- Webhook registered at `/api/telegram-webhook.php`
- Bot responds to student questions via Telegram
- Uses same Gemini API as the web app
- User data stored in `telegram_users` table

---

## 18. QUICK REFERENCE — IMPORTANT CONSTANTS

| Constant | Value | Where |
|----------|-------|-------|
| `DEFAULT_DAILY_LIMIT` | 100 | `config.php` |
| `DATA_DIR` | `__DIR__ . '/../data/'` | `config.php` |
| `ADMIN_EMAIL` | From `.env` | `config.php` |
| Max login attempts | 5 | `auth-login.php` |
| Login lockout duration | 15 min | `auth-login.php` |
| OTP expiry (verify) | 10 min (600s) | `auth-verify-otp.php` |
| OTP expiry (cleanup) | 15 min (900s) | `auth-register.php` |
| Auth cookie lifetime | 1 year | `auth-login.php` |
| IP hourly hard cap | 300 requests | `db.php` |
| AI cache TTL | 14 days | `db.php` |
| Max request size | 15 MB | `ask.php` |
| Max output tokens (text) | 350 | `ask.php` |
| Max output tokens (image) | 800 | `ask.php` |
| Gemini temperature | 0.1 | `ask.php` |
| Quiz generation model | `deepseek-chat` | `quiz-api.php` |
| Subscription duration | 30 days | `verify-payment.php` |
| Referral bonus | +20 questions | `auth-verify-otp.php` |
