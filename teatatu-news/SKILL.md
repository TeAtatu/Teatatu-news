# Teatatu News — Agent Skill

## Purpose

This skill lets an AI agent draft **News** posts (title, excerpt, source,
category, read-more URL, image URL) on a Teatatu News-enabled WordPress site
for a human to review and publish. The agent works entirely through the
standard WordPress REST API.

**The agent must never attempt to publish directly.** Every item the agent
creates or edits is saved as a draft (or pending) for human review. The
agent's WordPress role has no publish capability, and the REST API enforces
this at the permissions layer — it isn't just a instruction the agent has to
remember to follow.

## Authentication

Authenticate as a WordPress user with the `teatatu_news_agent` role using
[HTTP Basic Auth with a WordPress Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
(a core feature since WordPress 5.6):

1. In wp-admin, go to **Users > Profile** for the agent's user account.
2. Under **Application Passwords**, enter a name (e.g. "News Agent") and
   click **Add New Application Password**.
3. Copy the generated password immediately — it is shown only once.
4. Send it as HTTP Basic Auth on every request:
   `Authorization: Basic base64(username:application_password)`

**Multisite reminder:** on a multisite network, the agent's user account and
its Application Password exist on ONE specific site, not the network as a
whole. Always call that site's own REST base URL —
`https://<that-site-domain>/wp-json/wp/v2/` — never the network's primary
site URL, even if the primary site happens to be the network's Master Site
for the aggregate shortcodes/endpoint. Publishing content on the wrong site
is a common agent mistake; double-check the hostname before every request.

## Workflow

1. **Look up existing terms first.**
   - `GET /wp-json/wp/v2/news-sources` — existing Source terms
   - `GET /wp-json/wp/v2/news-categories` — existing Category terms

   Reuse an existing term ID where one reasonably matches the news item
   you're drafting. Don't create duplicate Sources or Categories.

2. **Only create a new term if nothing matches.**
   - `POST /wp-json/wp/v2/news-sources` with `{"name": "..."}`
   - `POST /wp-json/wp/v2/news-categories` with `{"name": "..."}`

   Both require the agent role's `manage_teatatu_news_sources` /
   `manage_teatatu_news_categories` capability, which it has.

3. **Create the News item as a draft.**
   `POST /wp-json/wp/v2/news` — **always** include `"status": "draft"`.
   Include `title`, `excerpt`, the resolved `teatatu_news_source` /
   `teatatu_news_category` term IDs, and a `meta` object with
   `_teatatu_news_read_more_url` (required) and `_teatatu_news_image_url`
   (optional — omit it to fall back to the Source's default image). Images
   are link-only: the plugin stores and serves exactly the URL you submit
   and never downloads, copies, or uploads it anywhere — just supply a
   working, publicly-reachable image URL.

4. **Confirm and hand off.**
   Check the response's `status` came back as `"draft"` (if you requested
   anything else, WordPress silently downgrades or rejects it — see
   Constraints below). Surface the item's `link` field (or construct
   `.../wp-admin/post.php?post=<id>&action=edit`) so the human reviewer can
   find and publish it quickly.

## Example request

```json
POST /wp-json/wp/v2/news
Content-Type: application/json
Authorization: Basic <base64 username:app_password>

{
  "title": "Regional council approves new cycleway funding",
  "excerpt": "A short summary of the article for display in listings.",
  "status": "draft",
  "teatatu_news_source": [12],
  "teatatu_news_category": [7],
  "meta": {
    "_teatatu_news_read_more_url": "https://example.com/original-article",
    "_teatatu_news_image_url": "https://example.com/images/cycleway.jpg"
  }
}
```

```bash
curl -u "news-agent:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -X POST "https://example.com/wp-json/wp/v2/news" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Regional council approves new cycleway funding",
    "excerpt": "A short summary of the article for display in listings.",
    "status": "draft",
    "teatatu_news_source": [12],
    "teatatu_news_category": [7],
    "meta": {
      "_teatatu_news_read_more_url": "https://example.com/original-article",
      "_teatatu_news_image_url": "https://example.com/images/cycleway.jpg"
    }
  }'
```

## Constraints reminder

- The agent's role does **not** have `publish_teatatu_news_items`. Any
  request with `"status": "publish"` is rejected with a `403`.
- The agent can only edit or delete items **it created itself**, and only
  **while they remain unpublished**. The moment a human publishes an item —
  even one the agent drafted — the agent permanently loses edit access to
  that item. This is enforced by WordPress's capability system.
- Never ask a human to grant the agent publishing rights. Draft-and-hand-off
  is the entire intended workflow, by design.

## Error handling

| Status | Meaning | What to do |
|---|---|---|
| `401 Unauthorized` | Bad username or application password. | Don't retry with the same credentials — surface to the human operator to re-check/regenerate the Application Password. |
| `403 Forbidden` | Insufficient capability — e.g. tried to set `status: publish`, tried to edit another user's or an already-published item, or hit the wrong site on a multisite network. | Don't retry as-is. If it was a `status: publish` attempt, resubmit as `draft`. Otherwise surface the error to a human — this usually means the workflow assumption (own item, still unpublished, correct site) was wrong. |
| `400 Bad Request` | Validation error — e.g. malformed URL in `meta`, invalid term ID. | Fix the payload (check the URL is well-formed, confirm the term ID exists via the lookup endpoints) and retry once. If it fails again, surface to a human. |

## Reference

- Endpoint documentation and a curl example also live in
  [`readme.txt`](readme.txt) in this plugin's root.

## When Teatatu Data manages a list

If the site uses Teatatu Data to manage sources or categories, creating or changing those terms here fails with `teatatu_news_managed_by_data` (HTTP 403). Look the item up in Teatatu Data instead (its `SKILL.md`) and propose any change there; once a steward approves it, it is copied here. Assigning existing terms to your drafts works as before.
