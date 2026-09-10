# Architecture decisions

An ADR records **why** a decision was made, for decisions that are expensive to
reverse or surprising to encounter. It is read once, by somebody about to
change the thing it describes.

It is not a runbook. What an operator has to _do_, with exact commands, belongs
in the README and the Makefile.

## Index

| ADR                                        | Subject                                                   |
| ------------------------------------------ | --------------------------------------------------------- |
| [0001](0001-foundations.md)                | Runtime versions, repository shape, why three services    |
| [0002](0002-authentication.md)             | Session authentication with Sanctum, and its traps        |
| [0003](0003-the-proxy-boundary.md)         | How the browser reaches the API, and what must not change |
| [0004](0004-money-and-currency.md)         | Integer minor units, and why currencies are never summed  |
| [0005](0005-api-versioning.md)             | One route file per version, and why `apiPrefix` was wrong |
| [0006](0006-the-generated-api-contract.md) | OpenAPI as the single source for types and Postman        |
| [0007](0007-sellers-and-shop-approval.md)  | What a seller is, one shop per account, approval          |

## Writing one

Number sequentially. Status and date at the top. State the decision, then the
reasoning, then the consequences somebody will actually trip over.

Record what was rejected and why. An ADR that only lists what was chosen leaves
the next person to re-run the same investigation.

Domain ADRs are written as each domain is built - sellers, orders, payments,
disputes - not in advance of it. A decision recorded before anything forces it
is a guess with a document number.
