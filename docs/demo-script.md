# Five-minute demo script

A walkthrough for showing the dashboard to someone in a meeting. Each step lists what to click,
what to say, and what they should see. The numbers come from the seeded demo world; live prices
move, so read them off the screen rather than quoting this page.

## The day before

- Merge the open PRs, so the deploy runs the current `main`.
- **Stop the scheduled reset for the meeting.** In Render, set `DEMO_RESET_MINUTES=0` and
  redeploy. The world is still rebuilt on every boot, so the redeploy gives you a fresh one.
  Otherwise the world resets at :00 and :30 past each hour, and an open dashboard reloads,
  losing anything you added during the demo.
- **Avoid the cold start.** A free Render service sleeps after 15 minutes with no visitors and
  takes about a minute to wake. Either open the URL 5 minutes before the meeting and leave it
  open, or switch the service to a paid Starter instance for the week ($7/month, prorated by
  the second; paid instances don't sleep). See [Render's pricing](https://render.com/pricing).
- Run the smoke test against the deploy: `docker/demo/smoke.sh https://<your-service>.onrender.com <REVERB_APP_KEY>`.
- Have the replay open in a second tab as a fallback. It needs no server.
- Rehearse once on the screen you'll use, in the theme you'll use (the theme toggle sits next
  to Dry run).

## After the meeting

Set `DEMO_RESET_MINUTES` back to `30` and redeploy, so a public link keeps resetting.

## The script

### 1. The price war (about 1 minute)

**Show:** the dashboard opens on the _Stainless French Press_. Point at the chart and the
"Live" badge.

**Say:** "These are simulated competitors. Here, one undercuts whoever holds the featured offer by a
penny, and one holds a fixed price. Other products have competitors that match, move at random
or run out of stock. Our repricer answers every move
within seconds. The shading shows when we held the featured offer, the Buy Box."

### 2. Every decision is explained (about 1 minute)

**Click:** a row in the _Decisions_ feed.

**Say:** "Every price change comes with its reasons: who moved, what the strategy proposed, and
each check it passed: margin, floor, ceiling, how far it may move at once. It's all kept in an
audit log."

**Mention:** the feed hides "still cooling down" skips by default. The chips filter by kind.

### 3. Guardrails say no (about 1 minute)

**Click:** _Edit rules_ on the French Press. Type `20.00` into **Floor**.

**Say:** "If someone sets a floor that would sell below cost plus fees plus our minimum margin,
it's refused before it can be saved. This is enforced on the server too, not just in the form."

**Then:** click **Margin-first**. "Or pick a preset, computed from this product's own costs."
Point at the preview line: "It shows what the rules would set right now, before saving."
Click **Cancel**.

**Optional:** the **Kill switch** stops all repricing after a confirmation. Resume it afterwards.

### 4. TikTok and Facebook (about 1 minute)

**Show:** the _LED Ring Light_ row: the purple **Open listings** badge and "Cheapest of 3".

**Say:** "On TikTok Shop and Facebook there's no Buy Box; every seller lists separately. So
here we track our price rank instead. Same engine, same guardrails, same audit trail."

**Then:** open its rules and show that "Beat Buy Box holder" isn't offered.

### 5. Adding a product (about 1 minute)

**Click:** **Add product**. Fill in a title, SKU, cost and price, and set **Marketplace type**
to open listings. Click **Add product**.

**Say:** "Products can be added and archived here. A new one starts repricing straight away."
Point at it appearing in the feed, then archive it.

## Likely questions

The questions and answers on other marketplaces are in [marketplaces.md](marketplaces.md).
Two more that often come up:

- **"Is this connected to a real marketplace?"** No. It runs against a simulator so nothing real
  is at risk. Connecting a real marketplace means one adapter per platform, tested against that
  platform's sandbox.
- **"What happens if it goes wrong?"** Prices never go below the floor or margin floor. A product
  that reprices more than 20 times in an hour is paused automatically. The kill switch stops
  everything, and dry-run mode decides without pushing prices.
