## Quick orientation

This repository is a small PHP + client-side JavaScript (Firebase) CRM. Use these notes to be productive without guessing where behavior lives.

### Languages & runtime
- Server-side: PHP (plain PHP pages, session-based auth). See `index.php`, `login.php`, `criar_usuario.php`.
- Client-side: modern JavaScript modules in `index.php` / `assets/js/app.js` (uses CDN imports for Firebase and Chart.js).
- Styles: Bootstrap (in `assets/css/`) and some Tailwind-style classes in templates.

## High-level architecture
- PHP renders pages and handles user authentication via sessions and a MySQL database (`includes/config.php` configures PDO).
- App data (leads, projects, pos-venda) is stored in Firebase Firestore and accessed from the client using Firebase JS SDKs (see embedded module script in `index.php` and `assets/js/app.js`).
- The flow: PHP authenticates users (local DB) → serves SPA shell (`index.php`) → client signs into Firebase (anonymous or with a custom token) → client reads/writes Firestore under `/artifacts/{appId}/users/{userId}/...`.

## Key files and why they matter (examples)
- `includes/config.php` – PDO MySQL connection and DB credentials. Update this for local dev (DB name `crm`).
- `login.php` – session-based login; queries `users` table, uses `password_verify()`.
- `criar_usuario.php` – helper that inserts a test user using `password_hash()` (useful to bootstrap a local user).
- `index.php` – main app shell; includes `includes/sidebar.php` and contains the client-side module that wires Firebase, listeners and UI rendering.
- `assets/js/app.js` – contains SPA logic: real-time listeners, render functions, drag/drop; the client expects variables like `__firebase_config`, `__app_id` or `__initial_auth_token` to be injected server-side if needed.

## Developer workflows (how to run & debug)
- Run locally using XAMPP / Apache + MySQL: place repo under `htdocs`, start Apache and MySQL.
- Create the `crm` MySQL database and a `users` table with at least `id, username, password, email`. The app expects `password` to be a password_hash() value.
- Use `criar_usuario.php` to create a seeded user locally (it calls `password_hash()` and inserts into `users`).
- Edit DB credentials in `includes/config.php` (defaults in repo: `root` / `1234`) to match your environment.
- No npm build step: frontend uses CDN imports so debugging is mostly browser devtools + PHP logs.

## Project-specific conventions & patterns
- File layout: top-level PHP files are pages (`login.php`, `leads.php`, `customers.php`); shared PHP fragments live in `includes/`; partial page fragments in `pages/`.
- Auth: mix of server-side session auth (MySQL) and client-side Firebase auth. Don't remove either without updating the other.
- Firestore paths: client code expects collections under `/artifacts/{appId}/users/{userId}/leads|projects|pos-venda`.
- Important business rule: when a project is moved to status `Fechado` (drag-and-drop), the client sets `closedDate` on the Firestore document — look for this in `index.php` / `assets/js/app.js` drag/drop handlers.

## Integration points & gotchas
- Firebase config and tokens are not hard-coded. The client checks for `__firebase_config` and `__initial_auth_token` globals before falling back to anonymous sign-in. If you want persistent user mapping, inject these values from PHP templates.
- User data separation: the app scopes data by `appId` and `userId` — tests or local debugging without proper `appId` may result in reading different Firestore paths.
- Password security: login uses `password_verify()` with a bcrypt or default PHP hash. When creating users programmatically, use `password_hash()` (see `criar_usuario.php`).

## Safe edit guidance (do / don't)
- Do: edit `includes/config.php` for DB changes, edit `index.php` or `assets/js/app.js` for UI/Firestore logic, and keep Bootstrap vendor files in `assets/css`/`assets/js` untouched.
- Don't: store Firebase secrets in the repo. If you need to inject config for dev, use environment injection from PHP templates (set `__firebase_config` JSON only in local/dev).

## Small examples for the agent
- To find where auth occurs: open `login.php` (server) and the top of `index.php` where Firebase is initialized (client).
- To change the “closedDate on close” business rule: edit the drag/drop handler in `assets/js/app.js` or the inline module script in `index.php` where `if (newStage === 'Fechado') { updateData.closedDate = new Date().toISOString(); }` is set.

If any of these notes are unclear or you want additional sections (DB schema, suggested SQL to create `users` table, or environment snippets), tell me which part to expand and I will update this file.
