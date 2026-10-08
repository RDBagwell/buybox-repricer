# Other marketplaces: TikTok Shop, Facebook and beyond

The demo simulates an Amazon-style marketplace. The repricer itself is not Amazon-specific, and
the dashboard now shows a second kind of marketplace side by side. This page answers the
questions that usually come next. The facts about TikTok Shop and Meta were checked against
public sources on 2026-10-07 (listed at the end). Platforms change, so re-check before relying
on any of them.

## The short answer

- **The pricing engine is marketplace-agnostic.** Rules, guardrails (floor, ceiling, margin),
  the audit trail, the circuit breaker, idempotent and rate-limited price pushes: none of it
  knows which marketplace it is talking to. Everything goes through one interface
  (`MarketAdapter`), and a contract test suite (`tests/Contract`) says what any marketplace
  connection must do.
- **What changes between marketplaces is what "winning" means**, and where competitor prices
  come from.
    - On **Amazon-style marketplaces**, sellers share one product listing and compete for a
      single featured offer: the Buy Box.
    - On **open-listing marketplaces**, which is how TikTok Shop and Facebook work as far as the
      sources show, every seller lists separately. There is no box to win; what matters is our
      price against comparable listings, alongside things like reviews, delivery speed and, on
      TikTok, creator promotion.
- **The demo now shows both.**
    - The _LED Ring Light_ sells on an open-listing marketplace. Its row shows a purple **Open
      listings** badge and our **price rank** ("Cheapest of 3") instead of a Buy Box holder.
    - "Beat the Buy Box holder" isn't offered there.
    - Same engine, same guardrails, same audit trail.
    - New products can be added on either kind (**Add product → Marketplace type**).

## Questions you can expect

**Can this reprice our TikTok Shop and Facebook listings?**
The engine can. What's missing is the connection to each platform: one adapter per
marketplace, built and tested against that platform's sandbox. Both platforms let a seller
update their own prices through an API.

- **TikTok Shop:** a product price-update call in the Partner API, which needs a Partner Center
  app and approved permissions. One integration guide notes that a price can be **locked while
  a promotion is running**, which the repricer would have to respect.
- **Meta (Facebook):** price is a field on your product catalog. Meta's docs recommend syncing
  "highly volatile" fields like price and availability through the Batch API, at least every
  15 minutes.

**Is there a Buy Box on TikTok Shop or Facebook?**
I found no official documentation of one on either.

- On **TikTok Shop**, several shops can sell the same product, each with its own page. Ranking
  appears to depend on price, fulfilment, reviews and creator activity. That last claim comes
  from a third-party vendor and isn't confirmed by TikTok.
- **Meta** moved Shops checkout to sellers' own websites in 2025. Sources disagree on how
  businesses can sell on Marketplace itself, so check Meta's Business Help Center for your
  account type.

**Where would competitor prices come from?**
This is the biggest difference from Amazon, and the first thing to settle. Amazon's API sends
the offers on a listing to the seller. On TikTok Shop, the official API covers your own shop,
not competitors'. Third-party scrapers exist, but they are unofficial and may conflict with the
platform's terms. Options, best first:

1. A licensed price-intelligence feed.
2. Your own monitoring within the platform's terms.
3. No competitor data at all: price on rules such as margin targets, stock levels and
   promotions. The engine supports that today, with "hold" when there's no competition.

**Does one product sold on several marketplaces work?**
Not yet. Today each product lives on one marketplace. Selling the same product on Amazon,
TikTok and Facebook at once needs a listing per marketplace, each with its own rule, fees and
connection, and money with a currency attached. That's a well-understood change to the data
model (Option B below), but it is real work.

**How fast can prices change, and is it safe?**

- **Speed:** every price push is rate-limited to the marketplace's published quota and retried
  with backoff. On Meta, catalog updates are recommended at least every 15 minutes, which is
  slower than Amazon's notifications.
- **Safety:** these hold on every marketplace:
    - never below the floor or the margin floor;
    - a per-product circuit breaker that pauses runaway repricing;
    - a kill switch and dry-run mode;
    - an append-only audit of every decision.

## Options from here

| Option                              | What it is                                                                                                                                      | Effort               | Needs                                                            |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | -------------------- | ---------------------------------------------------------------- |
| **A. Done in this PR**              | A second, _simulated_ marketplace type (open listings, no Buy Box) in the demo, side by side with the Buy Box one                               | Done                 | —                                                                |
| **B. Multi-marketplace data model** | One product listed on several marketplaces: a listing per marketplace with its own rule and fees, currency on money, an adapter per marketplace | About one or two PRs | A decision that it's worth doing                                 |
| **C. Real connections**             | A TikTok Shop adapter and a Meta catalog adapter, each passing the contract tests                                                               | Per platform         | Partner/app access, sandbox shops, and a competitor-price source |

The honest order is: settle the competitor-price question first, then B, then C, one platform
at a time.

## Sources

- TikTok Shop Partner Center developer docs: https://partner.tiktokshop.com/docv2/page/6697960798b0a502f89e3d00
- TikTok Shop price update (third-party integration doc): https://help.useresponse.com/hemi/knowledge-base/article/marketplaces-tiktok-marketplace-integration-v2-tiktok-product-management-tiktok-update-price_1
- TikTok Shop API access requirements (third-party comparison): https://www.socialcrawl.dev/blog/best-tiktok-shop-apis-2026
- TikTok Shop seller guide, Combined Listings: https://seller-us.tiktok.com/university/essay?knowledge_id=6102275286288183
- Third-party view on TikTok Shop ranking (vendor blog, unverified): https://bemomentiq.com/blog/how-win-tiktok-shop-buy-box-when-15-sellers-offer-same-product-so
- Meta developer docs, catalog integration: https://developers.facebook.com/documentation/ads-commerce/commerce-platform/partners/catalog-integration
- Facebook Shops checkout move (2025): https://outfeed.ai/blog/facebook-commerce-manager/ and https://sproutsocial.com/insights/facebook-shops/
- Facebook Shop vs Catalog vs Marketplace: https://www.nembol.com/blog/facebook-shop-vs-catalog-vs-marketplace
