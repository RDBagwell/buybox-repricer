# Screenshots and the GIF

Every image here was captured by Playwright (`tests/e2e/capture.spec.ts`) from a real run of
the app on 2026-10-07: the local processes (Reverb, a queue worker, `repricer:listen`,
`sim:daemon`, `php artisan serve`) against Postgres and Redis, seed 42, after `demo:reset`.
Nothing is mocked or edited. Desktop is 1440×900; phone is Playwright's Pixel 7 profile.

| File                | What it shows                                                                                                                                              |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `*-price-war.png`   | The dashboard a few seconds after load: the French Press price war, live decisions.                                                                        |
| `*-trace.png`       | A repriced decision expanded into its rule-by-rule trace.                                                                                                  |
| `*-rule-editor.png` | The rule editor refusing a floor below the margin floor (client-side check; the server returns the same message).                                          |
| `*-breaker.png`     | Products paused by the circuit breaker, captured with `REPRICER_BREAKER_MAX_PER_HOUR=4` so it trips within the demo's opening minutes (the default is 20). |
| `demo.gif`          | The desktop price-war test's video (Playwright `video: on`), from page load: ~14 s, 8 fps, 960 px.                                                         |

To reproduce, start the stack, then:

```bash
php artisan demo:reset
CAPTURE=1 npx playwright test capture --grep-invert @breaker
# restart the queue worker with REPRICER_BREAKER_MAX_PER_HOUR=4 and run demo:reset again, then:
CAPTURE=1 npx playwright test capture --grep @breaker

ffmpeg -i test-results/capture-price-war-mid-fight-desktop/video.webm \
  -vf "fps=8,scale=960:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle" \
  docs/screenshots/demo.gif
```
