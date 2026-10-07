# Security

This is a portfolio project. It runs against a simulated marketplace and holds no real seller
credentials. The same controls are written as if it did not, and each one below names the
code and the test that enforce it.

## Reporting

Please open a private security advisory on GitHub (_Security → Report a vulnerability_) rather
than a public issue.

## Two modes

|                                                  | Operator mode (`DEMO_MODE=false`, the default) | Public demo (`DEMO_MODE=true`)                                   |
| ------------------------------------------------ | ---------------------------------------------- | ---------------------------------------------------------------- |
| Dashboard and API reads                          | logged-in users                                | anyone                                                           |
| Mutations (kill switch, pause, rules, simulator) | logged-in users                                | anyone, rate-limited, capped                                     |
| Broadcast channel                                | `private-dashboard`, authorised per user       | public `dashboard` (it carries only what the page already shows) |
| Registration                                     | on                                             | off (`ALLOW_REGISTRATION=false` in the demo image)               |

## Authentication and authorisation on every mutating route

- Every mutating endpoint sits behind `can:operate` and a rate limiter (`routes/web.php`).
  Reads sit behind `can:view-dashboard`.
- Both gates are defined in `AppServiceProvider::configureDashboardAccess()`. They let in guests
  only in demo mode, and otherwise require a logged-in user.
- `tests/Feature/Dashboard/AuthorizationTest.php` runs **every** mutating route three ways:
    - a guest in operator mode gets 403;
    - a logged-in operator succeeds;
    - an anonymous visitor in demo mode succeeds.

    It also checks every read endpoint and the heartbeat.

- Dangerous actions need explicit intent from the server, not just the UI. The kill switch
  requires `confirm=true`, and resuming a paused or breaker-tripped product requires
  `acknowledge=true`. Both are tested.
- Horizon's dashboard uses its own gate, `viewHorizon`. Outside the local environment it allows
  only an allow-list of emails, which is empty, so it is closed. The demo container does not
  run Horizon.

## Validation and mass assignment

- Rule edits go through `UpdatePricingRuleRequest`, a FormRequest:
    - types and bounds on every field;
    - `floor ≤ ceiling`;
    - `floor ≥ cost + fees + minimum margin`;
    - sane percentages.

    The client mirrors these rules in `resources/js/repricer/lib/rule-validation.ts` (Vitest)
    for instant feedback, but the server is the authority (`RuleEditorTest`).

- Simulator and switch endpoints validate inline. Out-of-range input is a 422, never a 500.
- Models use `$guarded = []`, so protection lives at the boundary instead. No controller passes
  request input to a model wholesale. The rule update writes only an explicit list of rule
  fields, taken from validated data. `RuleEditorTest` "cannot be used to write fields outside
  the rule" proves that `product_id`, `id` and similar keys are ignored.
- Route parameters are constrained (`Route::pattern('product', '[0-9]+')`), so a malformed id
  is a 404 and never reaches SQL.

## Rate limits and demo caps

- `dashboard-mutations` allows 30 per minute (`DEMO_MUTATIONS_PER_MINUTE`), keyed by user, or by
  IP for anonymous visitors. `dashboard-reset` allows 2 per minute. Both are tested.
- Demo caps, enforced on the server:
    - speed is at most 50x;
    - injected faults are at most 30% each;
    - at most 6 offers per listing.
- The whole demo world is rebuilt from its seed every 30 minutes and on every boot
  (`demo:reset`), so nothing a visitor does persists.
- Behind a proxy, client IPs come from `X-Forwarded-For` only when `TRUSTED_PROXIES` is set.
  The demo image sets it, because Render terminates TLS. See `docs/hosting.md` for the
  unverified part.

## Channel authorisation and broadcast contents

- In operator mode the dashboard channel is private. `routes/channels.php` authorises it with
  the same `view-dashboard` gate (`BroadcastPayloadTest`).
- Every broadcast payload is built by a presenter (`App\Repricer\Dashboard\*Presenter`), never by
  serialising a model. `BroadcastPayloadTest` pins the exact key set of a decision payload and
  walks every payload, nested keys included, to assert that none carries:
    - delivery internals: `event_id`, `receipt_handle`, `idempotency_key`;
    - raw marketplace responses: `api_response`, `submission_id`;
    - user fields: `email`, `password`, `remember_token`;
    - timestamps: `created_at`, `updated_at`;
    - the simulator's hidden bot state: `bot_params`, `bot_memory`, competitor ratings and
      handling days.

    Prices are always integer cents.

- Broadcasts are queued on their own `broadcasts` queue, so a Reverb outage cannot fail a
  reprice.

## Errors never show stack traces or SQL

- Production runs with `APP_DEBUG=false`, which the demo image sets. API errors render as JSON
  with a generic message.
- `AuthorizationTest` "never shows a stack trace or SQL to users" checks this with debug off.
- The CI smoke test checks the built container for the same thing.
- The UI shows the server's `message` only; the client never builds error text from
  exceptions.

## Secrets

- Only `.env.example` is committed, with placeholder values. The real `.env` is gitignored.
- The hosted demo takes `APP_KEY`, `DB_URL` and the Reverb key and secret from the host's
  environment. Render generates the Reverb values. Nothing secret is baked into the image:
  the build generates a throwaway key for the asset build, then deletes `.env`.
- The marketplace adapter holds no real credentials. The SP-API adapter skeleton
  (`app/Repricer/Market/SpApi`) is untested, unbound and holds no credentials. Its docblock
  says the real credentials must come from the environment only.

## Dependency audits

Last run 2026-10-07:

- `composer audit`: **no advisories**.
- `npm audit`: **0 vulnerabilities**. Five critical advisories came through dev tooling, and
  none of them reaches the browser bundle:
    - `concurrently` → `shell-quote` 1.9.0;
    - `vite-plus` → `oxfmt` → `tinypool` 2.1.0.

    They were fixed by pinning the patched releases with npm `overrides` (`shell-quote@1.12.0`,
    `tinypool@2.2.0`). No packages were added. Formatting, linting, Vitest and the dev server
    script were re-run on the overridden versions.

Re-run both before relying on this list: `composer audit && npm audit`.
