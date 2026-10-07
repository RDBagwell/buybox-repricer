# Hosting the public demo

**Choice: one free Render web service running a single Docker container, plus a free external
Postgres (Neon). Replay mode on GitHub Pages covers the cold start.** Expected cost: $0/month.

## What runs where

```
Render free web service (one container, docker/php/Dockerfile → stage "demo")
└── supervisord
    ├── nginx            :$PORT  → php-fpm, and /app + /apps → Reverb (WebSockets on the same origin)
    ├── php-fpm          127.0.0.1:9000
    ├── reverb:start     127.0.0.1:8090
    ├── queue:work       reprice, push, broadcasts, default   (database queue)
    ├── repricer:listen  marketplace notifications (Redis stream)
    ├── schedule:work    cooldown sweeps, push resumes, demo:reset every 30 min
    ├── sim:daemon       ticks the world while someone is watching (capped at 50x)
    ├── redis-server     127.0.0.1:6379, in memory only, 48 MB cap
    └── demo:reset       one-shot on boot: a fresh, primed world for the first visitor

Neon free Postgres (DB_URL)

GitHub Pages: the replay (dist-replay/), a recorded run that needs no server at all
```

### Why this shape

- **One container.** The brief allows it, and Render's free tier has free web services but no
  free background workers. Everything a worker would do runs under supervisord next to the
  web server instead.
- **Redis inside the container.** Redis carries the marketplace notification stream, the
  token bucket and the fault-injection counters. All three are disposable in a demo that
  resets anyway. A private `redis-server` avoids depending on a separate free Key Value
  instance (25 MB, also non-persistent).
- **Database fallbacks for the queue, cache and sessions** (`QUEUE_CONNECTION=database`,
  `CACHE_STORE=database`, `SESSION_DRIVER=database`). This keeps Redis small, and a Redis
  restart loses only in-flight notifications, which the next tick re-sends, never queued jobs.
  Horizon is not used in the container; a plain `queue:work` is lighter.
- **Reverb on the same origin.** nginx proxies `/app` (WebSocket) and `/apps` (Reverb's HTTP
  API) to Reverb, so the browser connects to `wss://<the demo host>/app/<key>`. There is no
  second port and no CORS to configure. `REVERB_CLIENT_*` stay empty, meaning "same origin".
- **External Postgres.** Render's free Postgres is deleted 30 days after creation. Neon's free
  tier does not expire. It suspends compute after 5 minutes idle, and the next connection
  wakes it in a few seconds.
- **Shared world + scheduled reset, not per-visitor sandboxes** (see "Demo safety" below).

## Limits you will notice

| Limit                                                                                                  | Effect on the demo                                                                                                                                                                                                        |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Free web services spin down after 15 minutes without inbound traffic, and spin-up takes about a minute | The first visitor after a quiet spell waits. The README links the GitHub Pages replay, which loads instantly and shows a live link once the server answers `/up`.                                                         |
| 750 free instance hours per workspace per month                                                        | One always-on service would need 744. Because it sleeps when unwatched, it stays well under.                                                                                                                              |
| 512 MB RAM, a fraction of a CPU (free instance)                                                        | Eight long-running processes fit (PHP is capped at 128 MB each, php-fpm at 4 children). The demo is capped at 50x speed and 6 offers per listing to stay inside the CPU. **Not load-tested on Render.**                   |
| Hobby plan bandwidth: 5 GB/month included, then $0.15/GB (Render's 2026 workspace plans)               | A dashboard session is small (a ~300 KB first load, then a few KB/s of WebSocket events). Watch the Render usage page if the link gets shared widely.                                                                     |
| Neon free: 0.5 GB storage, 100 compute-unit hours a month                                              | The world is reset every 30 minutes, so storage stays tiny. The scheduler touches the database every minute, so Neon stays awake exactly as long as Render does (at most about 400 h/month at the smallest compute size). |
| Render terminates TLS                                                                                  | `TRUSTED_PROXIES=*` is baked into the image so Laravel builds `https://` URLs.                                                                                                                                            |
| Spin-down kills the processes                                                                          | Nothing is lost that matters: the boot reset rebuilds the world anyway.                                                                                                                                                   |

The Render and Neon figures come from their own pages as of October 2026
([Render free tier](https://render.com/docs/free), [Render workspace plans](https://render.com/docs/new-workspace-plans),
[Neon](https://neon.com/docs/introduction/plans)). Render's pages were read through search
results, because the build sandbox could not reach render.com directly. Prices change, so check
them again before relying on them.

## Demo safety (why one shared world)

The options were a per-visitor sandbox (a world per session) or one shared world reset on a
schedule. The shared world won:

- Each world means a simulator, a decision history and queued work. Per-visitor worlds would
  multiply CPU and database load on a 512 MB free instance, and a crowd would sink it.
- The point of the demo is to watch a price war. A shared world that is always mid-war shows
  that better than an empty sandbox each visitor has to warm up.
- The blast radius stays small:
    - mutations are rate-limited per visitor IP (30/min; resets 2/min);
    - world size and speed are capped (6 offers per listing, 50x, faults at most 30%);
    - the kill switch and pauses need a confirmation;
    - `demo:reset` runs every 30 minutes and on every boot. It TRUNCATEs every repricer and
      simulator table, so nothing a visitor does survives a reset. Users, sessions and the
      migrations table are untouched.
- Open dashboards receive a `world.reset` broadcast and reload, so nobody stares at a stale
  world.

## Robert's manual steps

1. **Postgres.** Create a free project at [neon.tech](https://neon.tech) and copy its
   connection string. It looks like `postgres://user:pass@ep-xxx.region.aws.neon.tech/neondb?sslmode=require`.
   (Or use Render's free Postgres, but it is deleted after 30 days.)
2. **App key.** Run `php artisan key:generate --show` locally (or `make shell` then the same
   command) and copy the `base64:...` value. Never commit it.
3. **Render.** Go to _New → Blueprint_, pick this repository, and Render reads `render.yaml`.
   When prompted, fill in:
    - `APP_KEY`: from step 2.
    - `DB_URL`: from step 1.
    - `APP_URL`: `https://<service-name>.onrender.com`. You can fill this in after the first
      deploy tells you the URL, then redeploy.

    `REVERB_APP_KEY` and `REVERB_APP_SECRET` are generated by Render, and the image defaults
    everything else (`DEMO_MODE=true`, `APP_DEBUG=false`, and so on).

4. **Check it.** Open the URL. The first boot migrates and primes the world, which takes up to
   a couple of minutes on a free instance. `/up` should answer 200. The dashboard should show
   "Live" and the French Press price war moving.
5. **GitHub Pages (the replay).**
    - In _Settings → Pages → Build and deployment_, set _Source_ to "GitHub Actions".
    - Optionally add a repository variable `LIVE_DEMO_URL` (_Settings → Secrets and
      variables → Actions → Variables_) holding the Render URL, so the replay can offer
      "the live demo is ready".
    - Run the `pages` workflow, or push to `main`.
6. **Keep secrets out of git.** Only `.env.example` is committed. Every secret lives in
   Render's environment.

## Smoke test

CI builds exactly the image Render builds, boots it against a Postgres service and runs
`docker/demo/smoke.sh`. The script checks the health endpoint, the seeded world, the
rendered page, a WebSocket upgrade through nginx to Reverb, new decisions while a heartbeat
is sent, and error responses that leak no internals. To run it against your deploy:

```sh
docker/demo/smoke.sh https://<service-name>.onrender.com <REVERB_APP_KEY>
```

## Not verified

- The image has not been run on Render itself. This repository was built in a sandbox with
  no Docker daemon, so the only proof the container works is the CI job above, on a GitHub
  runner (more CPU than a free Render instance).
- Behaviour under Render's CPU limit at 50x speed, and how many simultaneous viewers it
  takes before ticks fall behind.
- Whether Render's proxy idles out quiet WebSocket connections. pusher-js pings every
  ~2 minutes, and the client reconnects and catches up if the socket drops.
- Per-visitor rate limiting relies on Render appending the real client IP to
  `X-Forwarded-For`. If every request appeared to come from one proxy address, all visitors
  would share one 30/min bucket. That is safe, but stingier than intended.
