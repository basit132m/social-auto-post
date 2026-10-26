# Social Auto Post

A WordPress plugin that shares every post you publish to **X (Twitter)**, then
lists the resulting tweet back on the post itself under a "Socials" heading.

No third-party service sits in the middle. Your site talks to X directly, using
your own X app and your own credentials. There is no subscription, no relay
server, and nothing to sign up for beyond X's own free developer account.

---

## What it does

1. You publish a post in WordPress, as normal.
2. The plugin composes a short tweet from a template you control — by default
   the title and the permalink — and posts it to the connected X account.
3. The tweet's URL is saved against the post, and rendered in a **Socials**
   block on that post, alongside any other profile links you configure.

Everything in between — retries, token refresh, rate limits, not posting
twice — is handled for you.

---

## What this costs

**The plugin is free. Posting to X is not, and has not been since February 2026.**

X retired its free and subscription API tiers and moved to **pay-per-use
credits**, bought in the X Developer Console. Published third-party rates:

| | Approx. rate |
|---|---|
| Create a post | $0.015 |
| **Create a post containing a link** | **$0.20** |
| Read a post | $0.005 |

The second row is the one that matters here. **Every post this plugin sends
contains a link**, because sharing the permalink is the entire point — so
budget around **$0.20 per article shared**, not the headline $0.015.

In practice: 10 posts a month is about $2. A 50-article back catalogue is a
one-off ~$10. The Existing posts tab estimates the bill before you commit to a
batch, and the Connection tab shows a running estimate for the month.

If your developer account has no credits, X rejects every post with
**HTTP 402, "credits depleted"**. The plugin detects this, stops retrying
(retrying cannot fix a billing problem), and raises a warning across the whole
WordPress admin rather than burying it in a log.

Rates above are from third-party trackers, not read from your account — your
Developer Console is the authority on what you are actually charged. Override
the estimate with the `sap_cost_per_post` filter if your rates differ.

### If you would rather not pay

X is now the expensive option. **Mastodon and Bluesky have open APIs with no
credits, no approval process and no per-post cost** — genuinely free, with the
same automation. The plugin's structure already anticipates this: `SAP_X_Client`
is the only class that knows what X is.

## Setup

### 1. Install

Copy this folder into `wp-content/plugins/social-auto-post/` and activate
**Social Auto Post** in the Plugins screen.

### 2. Create your X app

Go to the [X Developer Portal](https://developer.x.com/en/portal/dashboard) and
create a project and an app (the Free tier is enough). Then, in the app's
settings:

- Under **User authentication settings**, turn authentication **on**.
- Set **App permissions** to **Read and write**. This is the step people miss —
  with read-only permissions the connection succeeds and then every post fails
  with a 403.
- Set **Type of App** to **Web App, Automated App or Bot** (this is what makes
  it a confidential client, which is what the plugin uses).
- Set the **Callback URI** to the exact URL shown on the plugin's Connection
  tab. It will look like:

  ```
  https://your-site.com/wp-admin/admin-post.php?action=sap_x_callback
  ```

  It must match character for character, including `https`.
- Set the **Website URL** to your site's home page.

Then copy the **OAuth 2.0 Client ID** and **Client Secret** from the app's
*Keys and tokens* screen.

### 3. Connect

In WordPress, go to **Social Auto Post → Connection**, paste the Client ID and
Secret, save, then click **Connect X account** and approve the request on X.

### 4. Turn it on

On the **Posting rules** tab, tick *Share new posts to X automatically*, and
set whichever rules you want. Nothing is shared until this is on.

---

## Posts you published before installing

Posts that were already live never saw a "published" moment while the plugin
was running, so nothing shares them automatically. **Editing one does not share
it either, and that is deliberate** — otherwise fixing a typo on a 2019 article
would tweet it as though it were new, and a bulk edit across a back catalogue
would empty your monthly allowance in one go.

To share them on purpose there are three routes:

| Route | Good for |
|---|---|
| **Social Auto Post → Existing posts** | The whole back catalogue at once |
| **Posts list → bulk action "Share to X"** | A hand-picked selection |
| **Posts list → row link "Share to X"** | One article |

The **Existing posts** tab counts everything published but never shared, shows
the next ten so you can sanity check the selection, and queues them **spaced
apart** — 10 minutes by default, set on the Posting rules tab. Firing fifty
tweets in one minute is the quickest way to trip X's rate limiter and look like
a bot; spreading them out costs nothing.

It also compares the number waiting against what is left of your 500/month
allowance, and warns you before you queue more than you can actually send.

Posts already shared, posts marked *Do not share this post*, drafts, and posts
your category rules exclude are all left out of the count and skipped if
selected anyway.

---

## Settings

### Posting rules

| Setting | What it does |
|---|---|
| Auto-posting | The master switch. Off by default. |
| Post types | Which types trigger a share. Defaults to Posts only. |
| Only these categories | Leave empty to share everything. |
| Never these categories | Exclusions beat inclusions. |
| Delay | Minutes to wait before sharing, so you have a window to catch a typo. |
| Retry attempts | How many times to retry a failure that looks temporary. |
| Spacing for existing posts | Gap between posts when sharing a batch of older articles. |
| Template | The tweet text. See placeholders below. |
| Hashtags | Used by `{hashtags}`. Commas or spaces, `#` optional. |

**Template placeholders:** `{title}`, `{url}`, `{excerpt}`, `{author}`,
`{site}`, `{categories}`, `{hashtags}`.

The default is:

```
{title}

{url}
```

### Socials section

| Setting | What it does |
|---|---|
| Show the section | Whether to render the block at all. |
| Heading | The heading text. Defaults to "Socials". |
| Position | Before content, after content, or shortcode-only. |
| This post's tweet | Link to the X post created for this article. |
| Other profiles | Standing links shown on every post. |

Other profiles are one per line, `Label | URL | handle`:

```
Instagram | https://instagram.com/andrew.amns | @andrew.amns
YouTube   | https://youtube.com/@amns.andrew  | @amns.andrew
```

Which renders as:

> **Socials**
> - X: [@andrew](#)
> - Instagram: [@andrew.amns](#)
> - YouTube: [@amns.andrew](#)

The shortcode `[sap_socials]` works anywhere, whatever the Position setting is,
so a theme can place the block exactly.

### Per post

Each post gets a **Share to X** box in the editor sidebar with:

- the current status, and a link to the tweet once it exists
- **Do not share this post** — a per-post opt out
- **Custom text** — overrides the template for that post only
- **Share now** — post immediately, for a post that has not gone out yet
- a live preview with X's own character count

---

## How it avoids posting twice

Double-posting is the one failure that is genuinely embarrassing, so it is
defended in three independent places:

1. **The right hook.** It listens to `transition_post_status` and acts only on
   `not-publish → publish`. Editing a post that is already live is a
   `publish → publish` transition and is ignored. Hooks like `publish_post`
   cannot tell those apart, which is why plugins that use them re-tweet on every
   edit.
2. **A recorded tweet id.** Once X returns an id, it is stored on the post. Any
   later attempt — a stray cron run, a manual *Share now*, an unpublish and
   republish — sees it and stops. This is the backstop, and it holds even if the
   other two fail.
3. **An atomic lock.** Two cron processes firing at once both try to `add_option`
   the same row; the options table has a unique index on the name, so exactly one
   wins and the other backs off. Stale locks are stolen after five minutes so a
   crashed run cannot wedge a post forever.

Every route into publishing goes through the same checks — automatic sharing,
*Share now*, the bulk action, and the Existing posts tab all end up in the same
method, and each queued post is checked **again** at the moment it runs, not
just when it was queued. Queueing the same post twice is a no-op: the second
attempt finds either a recorded tweet id or a job already scheduled, and stops.

There remains one window nothing can fully close: if the site dies *after* X
accepts a tweet but *before* the id is written down, the retry will post again.
That window is milliseconds wide, and the alternative — never retrying — loses
posts for real.

## How it handles failures

Each error is classified as retryable or not, because that is the only question
worth answering:

| Response from X | Treated as |
|---|---|
| 429 rate limited | retry, with backoff |
| 5xx, timeout, connection reset | retry — the request may never have landed |
| 403 duplicate content | permanent; the post is already out there |
| 401 unauthorized | permanent; needs a human to reconnect |
| other 4xx | permanent; the request itself is wrong |

Retries back off at 2, 10, 30, then 120 minutes. Everything that happens is
written to the **Activity log** tab, because auto-posting fails at 3am when
nobody is watching, and "my posts stopped going out" needs to become a line that
says which post, when, and what X said.

---

## Security notes

- The Client Secret and both OAuth tokens are encrypted at rest with AES-256-GCM,
  using a key derived from your site's WordPress salts. This is not a vault —
  anything that can read `wp-config.php` *and* the database can recover them —
  but a leaked database dump or a careless backup does not hand over a working
  token.
- OAuth uses Authorization Code + **PKCE**, with a one-shot `state` value held in
  a 10-minute transient.
- Every admin action is capability-checked (`manage_options`) and nonce-protected.
- X rotates the refresh token on each refresh. The save path never overwrites a
  good refresh token with an empty one — losing it silently kills the connection.

---

## Testing

The publishing rules run under plain PHP, against a hand-rolled slice of the
WordPress API and a mocked X, so there is no database or WordPress install to
set up:

```bash
./tests/run.sh
```

Four files, ~100 checks, covering the character fitting, the publish / retry /
give-up cycle, token refresh, the eligibility rules, the Socials block, which
status transitions share a post, back-catalogue queueing and spacing, and — most
importantly — that the same post cannot be tweeted twice by any route.

---

## Adding another platform later

The structure is already shaped for it. `SAP_X_Client` is the only class that
knows anything about X; `SAP_Publisher` just asks something to post text and
tells it whether to retry. A second platform means a sibling client and a
destination loop in the publisher — the rules, retries, locking and logging are
all platform-agnostic already.

Worth knowing before you plan for it: X is one of the harder ones. Mastodon and
Bluesky have open APIs with no approval process and no monthly post cap, so they
are much easier to add than Instagram or LinkedIn, which both need app review.

## Hooks for developers

```php
// Change the tweet text before it is trimmed to length.
add_filter( 'sap_tweet_text', function ( $text, $post ) { return $text; }, 10, 2 );

// Block sharing for your own reasons; return a string to block, null to allow.
add_filter( 'sap_ineligible_reason', function ( $reason, $post ) { return $reason; }, 10, 2 );

// React to a successful share.
add_action( 'sap_post_shared', function ( $post_id, $result, $post ) {}, 10, 3 );

// React to a post that will not be retried again.
add_action( 'sap_post_failed', function ( $post_id, $message ) {}, 10, 2 );

// Change the Socials rows, or the rendered markup.
add_filter( 'sap_socials_items', function ( $items, $post_id ) { return $items; }, 10, 2 );
add_filter( 'sap_socials_html',  function ( $html, $post_id, $items ) { return $html; }, 10, 3 );
```

---

## Troubleshooting

**Nothing posts at all.** Check that auto-posting is ticked on the Posting rules
tab, and that the Connection tab shows a connected account. Then look at the
Activity log — a skipped post records its reason.

**Posts go out late.** WordPress's scheduler only runs when someone visits the
site. On a quiet site, set up a real cron job hitting `wp-cron.php` and add
`define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

**403 on every post.** Your X app is read-only. Set App permissions to *Read and
write* — then regenerate the connection, because permissions are baked into the
token at authorization time.

**402 "credits depleted" on every post.** Your X developer account has no API
credits. Posting is pay-per-use; add credits in the Developer Console under
Billing. Connecting the account and reading your profile can succeed while
posting fails, because they are billed separately — so "Connected as @you" is
not evidence that you can post.

**"This authorization link has expired."** The 10-minute window lapsed, or the
callback came back to a different hostname than it left from (`www` vs bare
domain is the usual culprit). Start again from the Connection tab.

---

## Licence

GPL-2.0-or-later.
