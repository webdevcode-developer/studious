# Studious

Past questions and study materials for tertiary students, with Paystack-powered subscriptions.

```
studious/
├── backend/                 PHP API  → Render (Docker) + Clever Cloud MySQL
│   ├── api.php              ALL endpoints, router, auto DB setup, Paystack, file storage
│   ├── .htaccess            routes every request to api.php, forwards Authorization, blocks secrets
│   ├── .env.example         every secret / setting (copy to .env)
│   ├── Dockerfile, docker-entrypoint.sh
│   └── uploads/             local file storage (development only)
├── frontend/                static site → Netlify
│   ├── index.html, assets/  the app (vanilla JS, hash routing)
│   ├── config.js            API URL (regenerated on Netlify by build.js)
│   └── netlify.toml, build.js
├── render.yaml              Render blueprint for the backend
└── docker-compose.yml       optional local Docker (API + MySQL)
```

The old server-rendered pages (`login.php`, `admin/*.php`, …) are gone: the frontend now does all rendering and talks to `api.php` over JSON.
The database is new (default name `studious`); it is **not** compatible with the old `studyhub` schema.

## Local development with XAMPP

1. Copy the `studious` folder into `C:\xampp\htdocs\` (so you have `htdocs\studious\backend` and `htdocs\studious\frontend`).
2. Start **Apache** and **MySQL** in the XAMPP control panel (PHP 8.1+; `mod_rewrite` is on by default and `.htaccess` is honoured in `htdocs`).
3. In `backend/`, copy `.env.example` to `.env` and set at least `APP_KEY`, `PAYSTACK_SECRET_KEY` (a `sk_test_…` key) and the `ADMIN_*` values.
4. Open `http://localhost/studious/backend/health`. The first request **creates the database and all tables**, seeds plans (and demo content if `SEED_DEMO_DATA=true`) and creates the admin account from `ADMIN_EMAIL`/`ADMIN_PASSWORD`.
5. Open `http://localhost/studious/frontend/` and sign in with the admin account, or register as a student.

If your XAMPP URL differs, change `API_BASE_URL` in `frontend/config.js` and `FRONTEND_URL` / `ALLOWED_ORIGINS` in `backend/.env`.

## Configuration (`backend/.env`)

Every secret and setting lives in `.env` (see `.env.example` for the full, commented list). Real environment variables override `.env`, which is how Render works. Highlights:

| Variable | Purpose |
|---|---|
| `APP_KEY` | Signs download links. Long random string. |
| `FRONTEND_URL`, `ALLOWED_ORIGINS` | Netlify URL; CORS allow-list; where Paystack sends customers back |
| `API_URL` | Public URL of the API (used in download links) |
| `DB_*` | Database. If empty, Clever Cloud's `MYSQL_ADDON_*` variables are used automatically |
| `AUTO_SETUP` | Create database/tables/seed on first request (default `true`) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | First administrator (created once, never overwritten) |
| `PAYSTACK_SECRET_KEY`, `PAYSTACK_CURRENCY` | Paystack |
| `GOOGLE_SERVICE_ACCOUNT_JSON`, `GOOGLE_DRIVE_FOLDER_ID` | Private file storage for production |

The frontend contains **no secrets**; its only setting is `API_BASE_URL` (`frontend/.env.example`).

## Paystack

Flow: student picks a plan → `POST /payments/initialize` creates a pending payment and a Paystack transaction → student pays on Paystack → Paystack redirects to `FRONTEND_URL/?reference=…` → the app calls `GET /payments/verify`, and the **server verifies the transaction with Paystack** (status, amount in kobo, currency) before activating the subscription. The webhook does the same independently, so access is granted even if the student closes the tab. Activation is idempotent, and buying again while subscribed adds time to the running plan.

Setup:
1. Paystack Dashboard → Settings → API Keys & Webhooks. Put the secret key in `PAYSTACK_SECRET_KEY`.
2. Set the **Webhook URL** to `https://YOUR-API/payments/webhook` (locally you need a tunnel such as ngrok; the redirect verification works without one).
3. Test with Paystack test cards before switching to live keys. Plan prices/durations are managed in **Admin → Plans**.

Subscriptions are one-time passes with a fixed duration (not auto-renewing).

## Production

**1. Database — Clever Cloud MySQL.** Create the add-on. Either leave `DB_*` empty on Render and paste the add-on's `MYSQL_ADDON_*` variables, or copy their values into `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`. The database already exists on Clever Cloud, so the API only creates the tables. Small Clever Cloud plans have a low connection limit; the API uses one connection per request.

**2. API — GitHub → Render.** Push the repo (without `.env`). In Render choose *New → Blueprint* (uses `render.yaml`) or create a Docker web service with Dockerfile path `./backend/Dockerfile` and context `./backend`. Fill in the environment variables (or upload a Secret File named `.env`). Health check: `/health`. Use `SEED_DEMO_DATA=false` in production. Because Render's disk is ephemeral, configure Google Drive for uploads (share the Drive folder with the service-account email as Editor; paste the JSON on one line in single quotes).

**3. Frontend — Netlify.** New site from the same repo, base directory `frontend` (settings are in `frontend/netlify.toml`). Add the environment variable `API_BASE_URL=https://YOUR-API.onrender.com`; the build writes `config.js` from it.

**4. Wire the URLs together.** On Render set `FRONTEND_URL` and `ALLOWED_ORIGINS` to the Netlify URL and `API_URL` to the Render URL. Add the Paystack webhook URL.

## API reference

Base URL = the backend folder (XAMPP) or the Render URL. Authenticated calls send `Authorization: Bearer <token>`.

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/health`, `/meta` | public | status / app info |
| POST | `/auth/register`, `/auth/login` | public | returns `{token, user}` |
| POST | `/auth/logout` · GET `/auth/me` | user | |
| GET | `/schools`, `/departments?school_id=`, `/plans`, `/catalog` | public | lookups, question previews |
| GET | `/dashboard`, `/questions`, `/materials` | user | answers/explanations/download links only with an active subscription |
| GET | `/download?…` | signed link | streams a file (links are issued by `/questions` and `/materials`) |
| GET | `/payments` · POST `/payments/initialize` · GET `/payments/verify?reference=` | user | Paystack |
| POST | `/payments/webhook` | Paystack signature | `charge.success` |
| GET/POST | `/admin/questions`, `/admin/questions/import`, `/admin/materials`, `/admin/plans`, `/admin/schools`, `/admin/departments` | admin | create / list |
| POST | `/admin/{questions,materials,plans,schools,departments,users}/{id}/toggle` | admin | publish / activate |
| GET | `/admin/overview`, `/admin/payments`, `/admin/taxonomy`, `/admin/users` | admin | |
| POST | `/admin/payments/{id}/verify` | admin | re-check a pending payment with Paystack |

## Notes

- Routing in the frontend uses `#/hash` URLs, so it works from any folder (XAMPP) and on Netlify without rewrite rules.
- CSV import accepts institution/department **names** (or ids). See `frontend/assets/samples/sample-questions.csv`.
- Sign-in is rate-limited (8 failures per email / 30 per IP in 15 minutes).
- Still to add before launch: email verification and password reset, audit logs, and confirmation that you hold the rights to the past papers you upload.
