# Magic Descriptions – Bulk Generation Modal (v1)

This document describes the **happy-path** UX, data‐flow and backend contracts for the *Magic Descriptions* bulk generation modal.  It focuses on two modes:

1. **Review & Approve** (`mode = "review"`)
2. **Auto-Apply / YOLO** (`mode = "auto-apply"`)

---

## High-level Lifecycle

```
┌───────┐   1   ┌──────────┐  2   ┌──────────────┐ 3a  ┌──────────────┐
│Modal  │──────▶│ /bulk API│─────▶│  Queue items │────▶│Poll for status│
└───────┘       └──────────┘      └──────────────┘     └─────┬────────┘
                                                            3b│Webhook
                                                               ▼
                                                      ┌────────────────┐
                                                      │/webhooks?tool= │
                                                      │magic-descriptions│
                                                      └────────┬───────┘
                                                               │
                                                4 (draft)      │
                                                               ▼
                                                      ┌────────────────┐
                                                      │description-draft│
                                                      │meta updated     │
                                                      └───────┬────────┘
                                                               │
      Review mode: 5 ──────────────────────────────────────────┘
      Auto-apply: 5′ (applied automatically)
```

Steps explained:

1. **Confirm** – User selects products, chooses mode and hits *Start Generation*.
2. **/magic-descriptions/bulk** – Returns `batchId`, `results = { productId: statusId }` and echoes back `mode`.
3. **Processing** – Front-end starts two parallel activities:
   3a. *Polling* `/magic-descriptions/status?status_ids=…` every **5 s** to fetch completed items.
   3b. *Webhook* delivers results server-side.  Polling endpoint simply surfaces what the webhook already stored in post-meta.
4. **Draft storage** – Webhook saves HTML in `'_productbird_magic_descriptions_draft'`.  If `mode = auto-apply` it also writes directly to `$product->set_description()`.
5. **Review UI (review mode only)** – As `completed` items stream in, the modal switches to *Review* step.  Each card shows:
   * Product image, name & SKU
   * AI description (rendered HTML)
   * Actions: **Apply**, **Regenerate**, **Skip**
   * Bulk-apply all approved.

`remaining` count from polling endpoint lets us drive a progress bar.

---

## Front-end State Shape

```ts
interface BulkSession {
  batchId: string;
  mode: "review" | "auto-apply";
  queued: Record<ProductId, StatusId>; // as returned by /bulk
  completed: Array<{
    productId: ProductId;
    descriptionHtml: string;
    productName: string;
  }>;
  errors: Record<ProductId, string>; // optional, from status/error polling later
}
```

*Session* lives in a local `writable()` store that the modal reads from.  On `open = false` we `reset()` the store so a new run starts fresh.

Multiple runs are trivially supported because each invocation of the modal creates a *new* store instance isolated by `batchId` (we can keep them in a `Map<batchId, BulkSession>` if we ever need persistence).

---

## Polling/Streaming Strategy

* While **processing** → call `GET /description-completed` with all outstanding IDs.
* Response shape:
  ```json
  {
    "completed": [ { "productId": 1, "descriptionHtml": "…", "productName": "T-Shirt" } ],
    "remaining": 42
  }
  ```
* Append `completed` to session, update progress = `1 – remaining / total`.
* When `remaining === 0` →
  * `auto-apply` → close modal + toast success.
  * `review` → switch to *Review* step.

---

## Review Step Actions

1. **Apply** – `POST /productbird/v1/apply-description` (already exists) with `productId` & `html`.
2. **Regenerate** – `POST /productbird/v1/regenerate-description` with `productId` (keeps same tone etc.).
3. **Skip** – Mark locally; user can revisit later.
4. **Apply all** – Iterate over remaining approved items with the *Apply* endpoint, then close modal.

---

## Error Handling

* `/bulk` may return `insufficient_credits` (402) → modal shows purchase CTA.
* Individual items can fail → expose them in a collapsible *Errors* panel with regenerate option.
* Polling endpoint returns `remaining` so if it keeps bouncing after N retries (e.g. 2 min) we stop and surface timeout.

---

## Touch-points with PHP

1. **ToolMagicDescriptionsEndpoints** – now appends `?tool=magic-descriptions` to `callback_url`.
2. **WebhookCallbackEndpoint** – infers tool via that query-param and uses tool-scoped meta keys (see refactor).
3. **ProductStatusCheckEndpoint** – can be updated later to use the same meta keys & `delivered` flag.

---

## TODO Summary

* [x] Add `?tool=magic-descriptions` to callback URL (server side).
* [x] Make webhook endpoint tool-aware & use meta constants.
* [ ] Update status-polling endpoint to honour new meta keys.
* [ ] Implement polling & review UI in `magic-descriptions-bulk-modal.svelte`.
* [ ] Add UX polish: progress bar, skeletons, bulk apply all.
