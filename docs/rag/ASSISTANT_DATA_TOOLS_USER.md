---
module: ai
audience: user
cross_cutting_user: true
---
# Working with your data through the assistant — user guide

## What the assistant can do

When your administrator turns them on for a given entity, the in-app assistant can work with your data on your behalf: find and organize records, summarize them, export them, propose table filters, and — with confirmation — change records. Ask in plain language ("show last week's orders", "how many articles per author", "export these as PDF") and the assistant picks the right action and fills in the details.

Every action runs **as you**. The assistant can never see or change anything your own account is not allowed to see or change: it applies exactly the same permission and row-level access rules as the rest of the application. If you are not allowed to do something, the assistant will not offer to do it — there is no way to use it to bypass your permissions.

## Reading and finding records

| You ask for | What happens |
|-------------|--------------|
| A list of records, optionally filtered and sorted | The assistant loads the records you may read and shows them, together with the filters it applied. |
| A single record by identifier | The assistant fetches that one record. |
| A text search | The assistant searches records that match your text. |

The filters the assistant applies are returned with the answer, so the on-screen table can show and reuse exactly the same filters.

## Filtering the on-screen table (without loading through chat)

Sometimes you want the assistant to **set up** a table view and let the page load it — for example "filter this table to open invoices from March". In that case the assistant proposes the filters and sort order, and your table applies them and loads the data itself. Nothing is fetched into the chat; the table stays the single source of truth.

## Organizing and summarizing

Ask for totals and breakdowns — "count articles per author", "total amount per customer this quarter". The assistant groups the records and returns a per-group **count** plus any **sum, average, minimum or maximum** you asked for. For very large sets the summary is based on a capped sample and the answer says so, so you know when a number is exact and when it is an estimate.

## Exporting

Ask to export ("download these as CSV", "give me a PDF") and the assistant produces a **CSV** or **PDF** file of the records you may read, using the filters you asked for. You can then save the file. Large exports are capped and the answer tells you when not every matching row was included.

## Changing records

The assistant can create, update, or delete a record when you have permission to do so. On entities that require **approval**, your change is not applied immediately — it is captured as a pending change for a reviewer, exactly as if you had made it yourself in the application.

### Bulk changes always preview first

When you ask to change many records at once ("mark all of these as archived", "delete last year's drafts"):

1. The assistant **previews** first: it tells you how many records match and shows a sample, and **changes nothing**.
2. Only when you **confirm** does it apply the change, one record at a time, so each change still respects your permissions and any approval rules.
3. There is a hard limit on how many records one bulk action may touch. If your filter matches more than the limit, the assistant refuses and asks you to narrow it — so a bulk action can never run away.

A bulk change always needs at least one filter; the assistant will not act on "everything".

## Approvals

If you are an approver, you can ask the assistant to list the changes waiting for approval — optionally for a specific author ("what is waiting from Marco?") — and to approve or reject a specific pending change. You only see and act on approvals you are entitled to.

## Common questions

- **Can the assistant see data I can't?** No. It runs as you and applies the same permission and access rules everywhere.
- **Will a bulk change happen by accident?** No. Bulk actions preview first, require an explicit confirmation, need a filter, and are capped.
- **My edit didn't take effect immediately — why?** On entities that require approval, your change is queued for a reviewer instead of being applied at once.
- **Why did an export or summary say it was capped?** Very large result sets are limited for performance; the answer flags when not every matching row was included.
