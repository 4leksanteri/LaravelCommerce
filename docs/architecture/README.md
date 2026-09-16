# Architecture decisions

An ADR records **why** a decision was made, for decisions that are expensive to
reverse or surprising to encounter. It is read once, by somebody about to
change the thing it describes.

It is not a runbook. What an operator has to _do_, with exact commands, belongs
in the README and the Makefile.

## Index

| ADR                                              | Subject                                                            |
| ------------------------------------------------ | ------------------------------------------------------------------ |
| [0001](0001-foundations.md)                      | Runtime versions, repository shape, why three services             |
| [0002](0002-authentication.md)                   | Session authentication with Sanctum, and its traps                 |
| [0003](0003-the-proxy-boundary.md)               | How the browser reaches the API, and what must not change          |
| [0004](0004-money-and-currency.md)               | Integer minor units, and why currencies are never summed           |
| [0005](0005-api-versioning.md)                   | One route file per version, and why `apiPrefix` was wrong          |
| [0006](0006-the-generated-api-contract.md)       | OpenAPI as the single source for types and Postman                 |
| [0007](0007-sellers-and-shop-approval.md)        | What a seller is, one shop per account, approval                   |
| [0008](0008-authorization.md)                    | Policies not conditionals, and what 403 is not                     |
| [0009](0009-products-and-variants.md)            | Where a price lives, and why lists need named collections          |
| [0010](0010-the-cart.md)                         | One per account, grouped by shop, and why a line holds no price    |
| [0011](0011-checkout-and-orders.md)              | One order per shop, what is frozen onto it, all or nothing         |
| [0012](0012-the-order-lifecycle.md)              | The states, who may move them, and what cancelling returns         |
| [0013](0013-scheduled-work.md)                   | Where the timing lives, and what a scheduled command must be       |
| [0014](0014-completing-an-order.md)              | Only the buyer or the deadline, and the seller's escape hatch      |
| [0015](0015-payments-and-connect.md)             | Which Connect, money held on the platform, test mode only          |
| [0016](0016-product-images.md)                   | One WebP per photograph, EXIF stripped, served through the API     |
| [0017](0017-categories.md)                       | A staff-owned tree, and the first browse that needs no shop        |
| [0018](0018-design-direction.md)                 | Superseded by 0019 within a day - kept for why, not for what       |
| [0019](0019-positioning-and-design-direction.md) | The design export, the tokens it produced, and light only          |
| [0020](0020-search.md)                           | Full text over a generated tsvector, ranked and weighted           |
| [0021](0021-addresses.md)                        | A buyer's book, and the copy an order freezes at checkout          |
| [0022](0022-what-a-page-looks-like.md)           | Four numbers and no URLs, on every endpoint that paginates         |
| [0023](0023-the-auth-screens.md)                 | Forms that post through `apiFetch`, and the primitives they left   |
| [0024](0024-the-shell-and-the-front-door.md)     | Header, footer, and a home page fed by the API                     |
| [0025](0025-testing-the-frontend.md)             | What Vitest covers, what Playwright does, and what neither should  |
| [0026](0026-the-search-page.md)                  | Results, category filters, pages, and the box that keeps the term  |
| [0027](0027-the-category-page.md)                | A breadcrumb, subcategories either side, and search within         |
| [0028](0028-the-product-page.md)                 | Photographs, choosing an option, and adding it to the cart         |
| [0029](0029-the-cart.md)                         | The first page that is nothing without a session                   |
| [0030](0030-checkout.md)                         | An address, one order per shop, a confirmation to come back to     |
| [0031](0031-payout-accounts.md)                  | A Stripe connected account, verified here and stored nowhere       |
| [0032](0032-the-orders-pages.md)                 | A buyer's list, one order's page, and the three things they may do |
| [0033](0033-the-account-and-the-shop.md)         | One sidebar for both, not a separate seller application            |
| [0034](0034-account-settings.md)                 | Name, email and password, and the book checkout chooses from       |
| [0035](0035-attribution-and-notifications.md)    | Who ended an order, and mail queued until after the commit         |
| [0036](0036-the-shops-orders.md)                 | A queue narrowed by status: accept, mark sent, cancel why          |
| [0037](0037-the-review-queue.md)                 | Staff approve or turn down an application, on its own page         |
| [0038](0038-the-shops-listings.md)               | Drafts, options, photographs, and putting one on sale              |
| [0039](0039-the-payouts-page.md)                 | Opening the Stripe account, and what it still asks for             |
| [0040](0040-taking-a-payment.md)                 | One card for a basket, charged per order and held here             |
| [0041](0041-moving-the-money.md)                 | Transferred to the shop on completion, refunded on cancelling      |
| [0042](0042-an-unpaid-order.md)                  | `pending` had quietly come to mean two things                      |
| [0043](0043-showing-the-money.md)                | Paid, refunded, the fee and the share, on both sides' pages        |
| [0044](0044-logs-go-to-the-stream.md)            | JSON on the container's stream, and why no file is written         |
| [0045](0045-a-refusal-is-not-a-failure.md)       | Domain refusals stay out of the log, and what still reports        |
| [0046](0046-an-order-nobody-paid-for.md)         | Told which clock ran out, and given the basket back                |
| [0047](0047-reviews.md)                          | Earned by a completed order, one per buyer, never deleted          |
| [0048](0048-object-storage.md)                   | Images off the container's disk, so the stack can scale            |
| [0049](0049-shipping-and-tracking.md)            | A carrier and a number, both optional, and the link API-built      |
| [0050](0050-messages.md)                         | One thread per order, either side may write, nothing closes it     |
| [0051](0051-disputes.md)                         | Open while the money is held, and the two places it can go         |
| [0052](0052-suspending-a-shop.md)                | One enum case, and what a suspension deliberately leaves alone     |
| [0053](0053-the-shops-page.md)                   | A shop as a place, reached from a listing rather than a card       |
| [0054](0054-moderation.md)                       | Anybody reports, and upholding one is the takedown                 |
| [0055](0055-triggering-scheduled-work.md)        | An opt-in ticker locally, and still nothing in production          |
| [0056](0056-buying-from-your-own-shop.md)        | A checkout rule rather than a cart rule, and why that order        |
| [0057](0057-shipping-cost.md)                    | Per listing, charged once per shop, no fee on the carriage         |
| [0058](0058-closing-an-account.md)               | Anonymised rather than deleted, because a receipt outlives it      |
| [0059](0059-appeals.md)                          | Answering back, and the only undo a takedown has                   |
| [0060](0060-a-shops-record.md)                   | What the platform decided, kept after the sanction is lifted       |

**0018 is the only superseded one**, and it is kept rather than deleted because
its own opening says why: it was replaced within a day, and the reasoning that
replaced it is worth more than the decision it made. An index that quietly
dropped it would hide the one case where this project got a decision wrong fast
enough to say so.

## Writing one

Number sequentially. Status and date at the top. State the decision, then the
reasoning, then the consequences somebody will actually trip over.

Record what was rejected and why. An ADR that only lists what was chosen leaves
the next person to re-run the same investigation.

Domain ADRs are written as each domain is built - sellers, orders, payments,
disputes - not in advance of it. A decision recorded before anything forces it
is a guess with a document number.

**Keep this index in step.** It is the first thing somebody reads to find the
decision they need, and it stopped at 0009 for fifty-one ADRs - which made it
worse than no index, because a list that looks complete is trusted.
