# Nfourteen_InventoryAggregateProduct

Magento Inventory (MSI) integration for the `aggregate` product type from [`Nfourteen_AggregateProduct`](https://github.com/nfourteen/module-aggregate-product).

An aggregate holds no stock of its own - salability and inventory movements derive from its children. This module teaches MSI that rule so aggregate parents behave correctly wherever stock is read or changed. **It depends on MSI and does nothing without it**; on an installation running legacy `Magento_CatalogInventory` stock only, don't install it.

## What it does

| Area | Behavior |
| --- | --- |
| Source item management | Disabled for the `aggregate` type - parents never carry source items |
| Salability | An aggregate is salable only when every child is salable at the required quantity |
| Stock status | A stock status processor recalculates the parent from its children |
| Invoice | Source selection expands the parent SKU into its child SKUs |
| Shipment | Parent SKU deductions replaced by child SKU deductions |
| Cancellation | Reservation compensation issued against child SKUs, not the parent |
| Credit memo | Return-to-stock restores child SKUs, which core MSI skips for aggregate parents |

It is all plugins and DI arguments in `etc/di.xml`, which documents the non-obvious cases. The credit-memo one is worth knowing about: core's return-to-stock plugin is an `aroundExecute` that never calls `$proceed`, so nothing ordered after core in the chain ever runs. Ours is a `before`/`after` pair registered at `sortOrder="-1"`, below core's default `0`, which places it outside core's around: `beforeExecute` hides the aggregate children from core, and `afterExecute` restores them at the scaled qty once core has returned.

## Known issue: child SKUs also sold standalone

If an aggregate's child SKU is also sold as its own order line, refunding that line to stock over-credits the source by one unit per unshipped unit returned. The cause is in core MSI, not here: `GetShippedItemsPerSourceByPriority` never reads the `returnToStockItems` filter it is given, so the refunded line counts every shipment of that SKU as its own source deduction. It reproduces with a plain core bundle, and the tests covering it are skipped with the mechanism recorded in the skip message.

## Installation

Part of the Aggregate Product suite. Install it through [`nfourteen/aggregate-product-metapackage`](https://github.com/nfourteen/aggregate-product-metapackage), whose README covers the Composer repositories these packages need and the MSI requirement. Requires PHP >= 8.3 and Magento Open Source 2.4.x with MSI enabled.
