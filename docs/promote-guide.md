# Panic Promote — Setup & User Guide

A complete walkthrough for configuring platform credentials, connecting social and event channels, and broadcasting posts through the Promote module.

---

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Platform Connectors — Setup & Credentials](#platform-connectors)
   - [Facebook Page](#facebook-page)
   - [Instagram Business](#instagram-business)
   - [TikTok](#tiktok)
   - [Twitter / X](#twitter--x)
   - [Threads](#threads)
   - [Bluesky](#bluesky)
   - [Eventbrite](#eventbrite)
   - [Luma](#luma)
   - [Email (Mailchimp)](#email--mailchimp)
   - [Email (SendGrid)](#email--sendgrid)
   - [Editorial & Listing Sites](#editorial--listing-sites)
4. [Saving Credentials in Backstage](#saving-credentials-in-backstage)
5. [Creating a Campaign & Posts](#creating-a-campaign--posts)
6. [Broadcasting a Post](#broadcasting-a-post)
7. [Promotion Health Checklist](#promotion-health-checklist)
8. [Broadcast History & Analytics](#broadcast-history--analytics)
9. [Troubleshooting](#troubleshooting)
10. [Things to Watch Out For](#things-to-watch-out-for)

---

## Overview

Panic Promote is the marketing command center inside Mabuhay Backstage. It lets you turn any event into a structured promotion campaign — write posts, generate platform-specific copy, and broadcast to social media, event listing platforms, and email lists from a single screen.

**Supported channels:**

| Type | Platforms |
|---|---|
| Direct social post | Facebook Page, Instagram, TikTok, Twitter/X, Threads, Bluesky |
| Event platforms | Eventbrite, Luma |
| Email | Mailchimp, SendGrid |
| Manual / editorial | Funcheap, Foopee, SF Chronicle, SF Station, DoTheBay, Dice, Resident Advisor, SongKick, JamBase |

---

## Quick Start

1. **Open an event** in Backstage and navigate to the **Promote** tab.
2. Click **Start Campaign** if no campaign exists for this event yet.
3. Click **New Post** → enter a title and master copy text.
4. Click **Generate Variants** — the system produces platform-specific copy for every channel.
5. Review and edit any variant in the editor. Fix warnings (character limits, missing images, etc.).
6. Go to **Destinations** and ensure the platforms you want are marked **Connected** (green).
7. Click **Broadcast** → choose destinations → choose *Send Now* or *Schedule* → confirm.
8. Check **Broadcast History** to see per-platform delivery status.

If any destinations show **Needs Setup**, follow the relevant section below to add credentials.

---

## Platform Connectors

> **Permission:** Only venue admins (`manage_users` capability) can save or update API credentials. Staff with `edit_event` can create posts and trigger broadcasts using already-connected platforms.

---

### Facebook Page

Facebook posts are made to your **Facebook Page** (not a personal profile) via the Graph API. Posts include a caption and, when a flyer is attached to the event, a photo.

#### What you need

- A Facebook Developer account
- A Facebook App with **Pages API** enabled
- A **Page Access Token** (long-lived, 60+ days)
- Your **numeric Page ID**

#### Step-by-step setup

1. Go to [developers.facebook.com](https://developers.facebook.com) and log in.
2. Click **My Apps → Create App**. Select **Business** as the app type.
3. Under **Products**, add **Facebook Login** and **Pages API**.
4. In your app dashboard, go to **Tools → Graph API Explorer**.
5. Under **User or Page**, select your Facebook Page from the dropdown.
6. Click **Generate Access Token** and grant the `pages_manage_posts` and `pages_read_engagement` permissions.
7. Copy the short-lived token, then exchange it for a **long-lived token**:
   ```
   GET https://graph.facebook.com/oauth/access_token
     ?grant_type=fb_exchange_token
     &client_id={app-id}
     &client_secret={app-secret}
     &fb_exchange_token={short-lived-token}
   ```
8. Use the long-lived token to fetch your **Page Access Token**:
   ```
   GET https://graph.facebook.com/me/accounts?access_token={long-lived-user-token}
   ```
   Find your page in the response and copy its `access_token` and `id`.
9. In Backstage → **Promote → Settings → Credentials**, select **Facebook Page** and enter:
   - **Access Token** — the page access token from step 8
   - **Config → page_id** — the numeric page `id` from step 8

#### Things to watch out for

- **App Review:** Your app must go through Facebook's App Review before you can post to a real public page. In development mode, you can only post to pages you own/admin.
- **Token expiry:** Page access tokens can be made "never-expiring" if generated from a long-lived user token. If posts start failing, regenerate the token.
- **Link previews:** Text-only posts get a link preview card; photo posts show the flyer image.
- **Post visibility:** Posts go live immediately unless you schedule them.

---

### Instagram Business

Instagram uses the same **Facebook Graph API** as Facebook posts, but targets your **Instagram Business Account** rather than a Facebook Page. Posts require an image — text-only posts are not supported by the API.

#### What you need

- A Facebook Developer App (same app as Facebook if you like)
- `instagram_content_publish` permission (requires App Review)
- An **Instagram Business Account** linked to your Facebook Page
- A **User Access Token** with Instagram permissions
- Your **numeric Instagram Business Account ID**

#### Step-by-step setup

1. In your Facebook Developer App, add the **Instagram Graph API** product.
2. Under **App Review**, request the `instagram_content_publish` and `instagram_basic` permissions.
3. In Graph API Explorer, generate a user token that includes `instagram_content_publish`.
4. Get your Instagram Business Account ID:
   ```
   GET https://graph.facebook.com/me/accounts?access_token={token}
   ```
   Then for the page that has the linked Instagram account:
   ```
   GET https://graph.facebook.com/{page-id}?fields=instagram_business_account&access_token={token}
   ```
   Copy the `id` value from `instagram_business_account`.
5. In Backstage → **Promote → Settings → Credentials**, select **Instagram** and enter:
   - **Access Token** — user token with `instagram_content_publish`
   - **Config → ig_user_id** — the numeric Instagram Business Account ID

#### Things to watch out for

- **Image is required.** If the event has no approved flyer, the Instagram broadcast will fail. Always upload a flyer before broadcasting.
- **Image must be publicly accessible.** The Instagram API fetches your image from its URL. Make sure `APP_URL` in your `.env` is correct and the uploads folder is public.
- **Two-step publish:** The adapter creates a media container first, then publishes it. There is a brief wait between steps — if you see a timeout, the image may still appear on Instagram after a delay.
- **App Review is mandatory** for posting to real accounts. Test mode only works with accounts that are testers/developers on the app.
- **Links in captions are not clickable.** Instagram does not make URLs in captions tappable. Use a bio link tool (Linktree, etc.) and reference "link in bio" in the caption — the variant copy already does this.

---

### TikTok

TikTok posts are photo posts made via the **Content Posting API v2**. Like Instagram, an image is required. TikTok uses an OAuth 2.0 flow and the user token expires periodically.

#### What you need

- A TikTok Developer account at [developers.tiktok.com](https://developers.tiktok.com)
- An app with **Content Posting API** enabled
- App Review approval
- A **User Access Token** with `video.publish` scope (or `photo.publish` for photo posts)

#### Step-by-step setup

1. Log in to [developers.tiktok.com](https://developers.tiktok.com) → **Manage Apps → Create App**.
2. Under **Products**, add **Content Posting API**.
3. In **Login Kit**, add the scopes: `user.info.basic`, `video.publish` (or `photo.publish`).
4. Set your OAuth redirect URI.
5. Submit the app for **App Review** (required before live posting).
6. After approval, run the OAuth flow from your redirect URI to obtain a user access token.
   - The access token is tied to the TikTok user who authorizes the app — this should be your venue's TikTok account.
7. In Backstage → **Promote → Settings → Credentials**, select **TikTok** and enter:
   - **Access Token** — user access token from the OAuth flow
   - **Config → privacy_level** — one of `PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, or `SELF_ONLY` (default: `PUBLIC_TO_EVERYONE`)

#### Things to watch out for

- **Image is required** — same as Instagram.
- **Image URL must be allowlisted** in TikTok Developer settings under **Content Posting API → Media Hosting Domains**. Add your `APP_URL` domain there or posts will be rejected.
- **Token expiration:** TikTok tokens can expire. If posting fails with an auth error, re-run the OAuth flow.
- **Daily rate limits** exist per creator account. Avoid broadcasting the same post multiple times.
- **Publish status polling:** After initiating a post, TikTok processes it asynchronously. The adapter polls up to 6 times waiting for confirmation. A `queued` status in broadcast history means it was submitted but confirmation timed out — check TikTok directly.

---

### Twitter / X

Tweets are sent via the **X API v2** as text-only posts (no image). The variant copy is automatically truncated to 280 characters.

#### What you need

- A Twitter/X Developer account at [developer.x.com](https://developer.x.com)
- A Project + App with **Read and Write** permissions
- **OAuth 2.0 PKCE** configured (with `tweet.write` scope and optionally `offline.access`)
- A **User Access Token** from a completed OAuth flow

#### Step-by-step setup

1. Go to [developer.twitter.com](https://developer.twitter.com) → **Developer Portal → Projects & Apps → New App**.
2. Under **User Authentication Settings**, enable **OAuth 2.0** with:
   - **App type:** Web App (Confidential Client) *or* Native App (Public Client for PKCE)
   - **Callback URL:** your redirect URI
   - **Permissions:** Read and Write
3. Add the scope `tweet.write` (and `offline.access` if you want refresh token support).
4. Run the OAuth 2.0 PKCE flow to generate a **user access token** on behalf of your venue's Twitter account.
5. In Backstage → **Promote → Settings → Credentials**, select **Twitter** and enter:
   - **Access Token** — user access token
   - **Refresh Token** — (optional but recommended) for token renewal without re-auth

#### Things to watch out for

- **Free tier rate limits:** The free X API plan allows approximately 17 tweets per 24-hour window per user. Exceed this and posts will fail with a 429 error. If you broadcast to multiple events frequently, consider upgrading to a Basic plan.
- **280 character limit is strict.** The variant generator truncates text automatically, but always review the preview to make sure it reads well after truncation.
- **No images on v2 text endpoint:** Twitter/X text tweets do not include the flyer image. Image attachment requires a separate media upload step that is not yet implemented.
- **Token refresh:** Tokens without `offline.access` expire in ~2 hours. With `offline.access`, you get a refresh token — add it to the credentials so the adapter can renew automatically in a future update.
- **URL character count:** URLs count as 23 characters regardless of length.

---

### Threads

Threads posts use the **Threads Graph API** at `graph.threads.net`. The API supports text posts and optionally a single image. Tokens expire every 60 days and must be refreshed.

#### What you need

- A Meta Developer App (same or separate from your Facebook/Instagram app)
- The **Threads API** product added to the app
- `threads_basic` and `threads_content_publish` permissions (App Review required)
- A **User Access Token** and your **Threads User ID**

#### Step-by-step setup

1. In [developers.facebook.com](https://developers.facebook.com), open your app (or create a new one).
2. Add the **Threads API** product.
3. Under **App Review**, request `threads_basic` and `threads_content_publish`.
4. Generate a user access token through Graph API Explorer with those permissions.
5. Fetch your Threads User ID:
   ```
   GET https://graph.threads.net/me?access_token={token}
   ```
   Copy the `id` value.
6. Exchange for a **long-lived token** (valid 60 days):
   ```
   GET https://graph.threads.net/access_token
     ?grant_type=th_exchange_token
     &client_id={app-id}
     &client_secret={app-secret}
     &access_token={short-lived-token}
   ```
7. In Backstage → **Promote → Settings → Credentials**, select **Threads** and enter:
   - **Access Token** — long-lived token
   - **Config → threads_user_id** — numeric user ID from step 5

#### Things to watch out for

- **60-day token expiry.** Set a reminder to refresh your Threads token every ~50 days. After expiry, you'll need to re-authorize. The token can be refreshed before it expires using the Threads token refresh endpoint.
- **Two-step publish:** Like Instagram, the adapter creates a container then publishes it with a 0.5s delay. This is normal.
- **500 character limit** on post text.
- **App Review required** for production use.

---

### Bluesky

Bluesky uses the **AT Protocol** and authenticates with your Bluesky **handle** and an **App Password** — not your main account password. No OAuth, no token storage; the adapter authenticates fresh on every post.

#### What you need

- A Bluesky account at [bsky.app](https://bsky.app)
- An **App Password** created in your account settings
- Your Bluesky **handle** (e.g. `yourvenue.bsky.social`)

#### Step-by-step setup

1. Log in to [bsky.app](https://bsky.app).
2. Go to **Settings → Privacy and Security → App Passwords**.
3. Click **Add App Password**. Give it a name like `Mabuhay Backstage`.
4. Copy the generated password immediately — it won't be shown again.
5. In Backstage → **Promote → Settings → Credentials**, select **Bluesky** and enter:
   - **Access Token** — the App Password (not your login password!)
   - **Config → handle** — your full Bluesky handle (e.g. `yourvenue.bsky.social`)

#### Things to watch out for

- **This is an App Password, not your account password.** Using your real password here is a security risk. Always create a dedicated App Password for Backstage.
- **300 character limit** — shorter than Twitter. The variant generator enforces this.
- **URLs in text are clickable** because the adapter automatically inserts AT Protocol "facets" (rich-text annotations). You do not need to shorten URLs.
- **No stored session.** Bluesky authenticates on every post using handle + App Password. There is nothing to "expire" or refresh. If you change your Bluesky password, delete the App Password and generate a new one.
- **Rate limits** apply per handle. Avoid spamming Bluesky with repeated test posts.

---

### Eventbrite

The Eventbrite adapter creates a **draft event** in your Eventbrite organization and adds a General Admission ticket class. If broadcast mode is *Send Now*, the event is published immediately.

#### What you need

- An Eventbrite account at [eventbrite.com](https://eventbrite.com) with an Organizer profile
- A **Private API Key** from Eventbrite
- Your **Organization ID**
- (Optional) A pre-created **Venue ID** in Eventbrite

#### Step-by-step setup

1. Log in to Eventbrite → go to [eventbrite.com/account-settings/apps](https://www.eventbrite.com/account-settings/apps).
2. Click **Create API Key**. Fill in your app info. Copy the **Private token**.
3. Find your **Organization ID** — use the Backstage helper endpoint:
   ```
   GET /api/promote/eventbrite/org
   ```
   This returns a list of organizations associated with your API key. Copy the `id` of the correct organization.
4. (Optional) Pre-create your venue in Eventbrite's UI and note the **Venue ID** from the URL.
5. In Backstage → **Promote → Settings → Credentials**, select **Eventbrite** and enter:
   - **Access Token** — your Eventbrite Private API token
   - **Config → org_id** — your Organization ID from step 3
   - **Config → eb_venue_id** — (optional) Eventbrite Venue ID

#### Things to watch out for

- **Send Now publishes immediately.** Choosing *Send Now* in the broadcast dialog creates and publishes the Eventbrite listing in one step. Choose *Schedule* if you want to review the draft in Eventbrite before it goes live.
- **Cover image is not uploaded automatically.** After broadcasting, log in to Eventbrite and manually add your flyer image to the event.
- **Event times** are pulled from the event's `doors_time` and `show_time` fields. Make sure these are set correctly on the event before broadcasting.
- **Ticket price** defaults to free ($0.00). Edit the ticket class in Eventbrite if you need paid tickets.

---

### Luma

The Luma adapter creates an event at [lu.ma](https://lu.ma) via their public API. Events are published immediately on creation — there is no draft mode.

#### What you need

- A Luma account at [lu.ma](https://lu.ma)
- A **Luma API key** from your account settings

#### Step-by-step setup

1. Log in to [lu.ma](https://lu.ma).
2. Go to **Settings → API**.
3. Generate an API key. Copy it.
4. In Backstage → **Promote → Settings → Credentials**, select **Luma** and enter:
   - **Access Token** — your Luma API key

#### Things to watch out for

- **Events go live immediately.** Unlike Eventbrite, Luma has no concept of a draft event — the moment you broadcast, the event is live and public. Plan accordingly.
- **Cover image is not uploaded automatically.** After the event is created, log in to Luma and upload the flyer manually from the event editor.
- **Venue address** is constructed from your event's venue fields. Make sure address, city, state, and zip code are filled in on the event.
- **Timezone** is hardcoded to `America/Los_Angeles`. If you ever run events in a different timezone, this will need to be updated.
- **Capacity** is set from the event's `capacity` field. If that field is empty, capacity is left unlimited.

---

### Email — Mailchimp

The Mailchimp adapter creates a **Regular Campaign**, sets the HTML and plain-text content, then either sends it immediately or schedules it.

#### What you need

- A Mailchimp account at [mailchimp.com](https://mailchimp.com)
- An **API Key**
- An existing **Audience (List) ID**
- Your sender name and email address (must be verified in Mailchimp)

#### Step-by-step setup

1. Log in to Mailchimp → **Account → Extras → API Keys**.
2. Click **Create A Key**. Copy it.
3. Find your **Audience ID** → **Audience → All Contacts → Settings → Audience name and defaults**. The List ID is shown in the "Audience ID" section.
4. In Backstage → **Promote → Settings → Credentials**, select **Email (General)** or **Email (Press)** and enter:
   - **Access Token** — Mailchimp API key
   - **Config → provider** — `mailchimp`
   - **Config → list_id** — your Audience ID
   - **Config → from_name** — e.g. `Your Venue Name`
   - **Config → from_email** — a verified sender address in your Mailchimp account

#### Things to watch out for

- **Audience must already exist.** The adapter does not create audiences. The list you specify must already have subscribers.
- **From address must be verified.** Mailchimp rejects campaigns with unverified sender domains. Verify your domain or use Mailchimp's approved sender address.
- **Unsubscribe footer is automatic.** Mailchimp injects a `*|UNSUB|*` unsubscribe link. This is required by CAN-SPAM and cannot be removed.
- **Scheduled sends** use Mailchimp's own scheduling system. The send time is passed as an ISO 8601 datetime. Mailchimp requires the scheduled time to be at least 15 minutes in the future.

---

### Email — SendGrid

The SendGrid adapter creates a **Single Send** (a one-time email blast), sets the content, and either sends it or schedules it.

#### What you need

- A SendGrid account at [sendgrid.com](https://sendgrid.com)
- An **API Key** with `Mail Send` permissions
- An existing **Contact List ID**
- A verified **Sender Identity** (Sender ID)
- Your sender name and email address

#### Step-by-step setup

1. Log in to [app.sendgrid.com](https://app.sendgrid.com) → **Settings → API Keys → Create API Key**.
2. Give it **Full Access** or at minimum `Mail Send` and `Marketing` permissions.
3. Copy the API key immediately — it won't be shown again.
4. Go to **Marketing → Contacts** and note the **List ID** of the list you want to send to (it's in the URL when you click a list).
5. Go to **Marketing → Senders** and note the **Sender ID** (numeric, visible in the URL of a sender).
6. In Backstage → **Promote → Settings → Credentials**, select **Email (General)** or **Email (Press)** and enter:
   - **Access Token** — SendGrid API key
   - **Config → provider** — `sendgrid`
   - **Config → list_id** — your Contact List ID
   - **Config → from_name** — e.g. `Your Venue Name`
   - **Config → from_email** — verified sender address
   - **Config → sender_id** — numeric Sender ID from step 5

#### Things to watch out for

- **Sender verification required.** SendGrid requires domain authentication or single-sender verification before sending. Set this up under **Settings → Sender Authentication**.
- **Contact List must exist.** The adapter targets a pre-existing contact list. Build and manage lists in SendGrid's Marketing Contacts.
- **Unsubscribe links are required.** SendGrid injects `<%asm_global_unsubscribe_url%>` into the email template. This is required for compliance and cannot be removed.
- **Scheduled sends:** Like Mailchimp, the `send_at` time must be in the future. SendGrid requires an ISO 8601 UTC datetime.

---

### Editorial & Listing Sites

These platforms — **Funcheap, Foopee, SF Chronicle, SF Station, DoTheBay, Dice, Resident Advisor, SongKick, JamBase** — do not have APIs available for automated posting. Instead, the Promote module:

1. **Generates platform-specific copy** for each site (500-char descriptions, press pitches, etc.)
2. **Marks the broadcast as `manual_required`** — a prompt for you to take action
3. Optionally **sends a submission email** if a `contact_email` is configured for that destination

#### Setup for manual destinations

For each editorial platform, you can store helpful reference info in Backstage credentials:

- **Config → submission_url** — the URL of the platform's event submission form
- **Config → contact_email** — an editorial contact email (enables the *Send Email* action)
- **Config → partner_url** — (Dice/RA) your artist or promoter profile URL
- **Config → event_platform_url** — link to your existing listing if already created

These are stored but not used for automated posting — they simply pre-fill useful links in the broadcast result view.

#### Workflow for editorial submissions

1. After broadcasting, go to **Broadcast History**.
2. Platforms with `manual_required` status show a **View Submission** button.
3. Click it to open the generated copy for that platform.
4. Copy the text and paste it into the platform's submission form, or click **Send Email** to email an editorial contact.
5. Mark the item as submitted in the Promotion Health checklist.

#### Lead time warnings

The variant generator includes platform-specific warnings. Common ones:

| Platform | Lead time needed |
|---|---|
| Dice | Submit at least 7 days before the event |
| Resident Advisor | Submit 2–4 weeks in advance |
| SF Chronicle | 2–3 weeks for calendar listings |
| SongKick / JamBase | A few days; artist must have existing profile |

---

## Saving Credentials in Backstage

All credentials are stored **per venue** in Backstage. Only users with the `manage_users` capability (venue admins) can view or update them.

1. In Backstage, go to **Promote → Settings → Platform Credentials**.
2. Select a platform from the list.
3. Enter the required fields (access token + any config values described in the platform sections above).
4. Click **Save**. The platform status will update to **Connected** (green checkmark).
5. To disconnect a platform, click **Disconnect** — this deletes the stored credential.

**Credentials are stored in the database, not in `.env`.**  
The `.env` file may contain fallback values for `EVENTBRITE_API_KEY` and `EVENTBRITE_ORG_ID` from an older configuration, but database credentials take precedence.

---

## Creating a Campaign & Posts

### Starting a campaign

1. Open an event from the **Events** list.
2. Click the **Promote** tab.
3. If no campaign exists, click **Start Campaign**. This creates a campaign record linked to the event.
4. (Optional) Set a **ticket goal** for the campaign in the campaign settings panel.

### Creating a post

A *post* is a master piece of content (text + optional flyer image) that gets adapted into platform-specific variants.

1. Click **New Post**.
2. Enter a **title** (internal reference, not published) and **master copy** — write your core message here. Don't worry about character limits; the variant generator handles them.
3. If you want a flyer attached, make sure the event has an **approved asset** (flyer image) uploaded. Go to the **Assets** tab on the event to upload one.
4. Click **Save Post**.

### Generating variants

1. On the post, click **Generate Variants**.
2. The system produces channel-specific copy for all 20+ channels: Instagram, Facebook, TikTok, Twitter, Threads, Bluesky, Email, Eventbrite, Luma, and all editorial sites.
3. Each variant shows:
   - The generated copy (editable)
   - Character count vs. limit
   - Any **warnings** (e.g., "Links are not clickable on Instagram")
4. Edit any variant directly in the text area. Changes are saved automatically.
5. Variants marked with a warning are still broadcastable — warnings are informational, not blocking.

---

## Broadcasting a Post

1. From a post, click **Broadcast**.
2. The **Destinations** panel shows all available platforms with their status:
   - **Connected** (green) — ready to broadcast
   - **Needs Setup** (yellow) — no credentials stored; see setup sections above
   - **Manual Required** (blue) — no API available; will generate copy for manual submission
3. Select the destinations you want to include in this broadcast.
4. Choose **Send Now** or **Schedule** (enter a date/time for scheduled sends).
5. Click **Broadcast** to confirm.
6. The system dispatches to each selected platform in sequence.
7. Results appear immediately in the **Broadcast Results** panel:
   - **Sent** — posted successfully; link to the live post shown
   - **Queued** — submitted for scheduled delivery
   - **Manual Required** — no API; go to the result to copy/submit manually
   - **Needs Auth** — credential problem; check the platform setup
   - **Failed** — API error; the error message is shown for debugging

### Tips for a smooth broadcast

- **Run the Promotion Health check first** (see below) — it surfaces common issues before you broadcast.
- **Make sure there's an approved flyer** before broadcasting to Instagram and TikTok. Without one, those adapters will skip or fail.
- **Check character counts** in the Twitter and Bluesky variants before sending. The generator truncates automatically, but read it to confirm the message still makes sense.
- **Broadcast to a few platforms first** as a test before sending everything at once, especially when credentials are new.

---

## Promotion Health Checklist

The **Health** panel scores your campaign on a 20-point checklist. Items include:

| Item | Notes |
|---|---|
| Panic page published | Event is live on the public website |
| Approved flyer uploaded | Required for Instagram, TikTok, Threads |
| Instagram post sent | |
| Facebook post sent | |
| Eventbrite listing live | |
| Luma listing live | |
| Funcheap submitted | Manual |
| Foopee submitted | Manual |
| Press email prepared | |
| Email blast sent | |
| Posts created | At least one post in the campaign |
| Goal tickets set | Ticket goal defined for the campaign |
| SF Chronicle submitted | Manual |
| SF Station submitted | Manual |
| DoTheBay submitted | Manual |
| SongKick submitted | Manual |
| JamBase submitted | Manual |
| Email (ad-hoc) sent | One-off announcement email |
| Day-before reminder sent | |
| Band assets collected | Bios, press photos, set times |

Items marked **red** are high-priority. **Yellow** items are recommended. **Blue** items are informational.

The score is shown as a percentage. Aim for 80%+ for a well-promoted show.

---

## Broadcast History & Analytics

### History

**Broadcast History** shows every broadcast attempt for the campaign, grouped by broadcast run:

- Each run shows the timestamp, mode (now/scheduled), and the list of destinations.
- Expand a run to see per-platform results, external links, and error messages.

### Analytics

The **Analytics** panel summarizes campaign reach at a glance:

| Metric | Description |
|---|---|
| Broadcasts | Total broadcast runs |
| Destinations Reached | Count of platforms with status *sent* |
| Live Listings | Eventbrite + Luma events that are live |
| Manual Pending | Editorial submissions awaiting your action |
| Needs Setup | Platforms with missing credentials |
| Failed | Broadcast attempts that errored |

---

## Troubleshooting

### "Needs Auth" after broadcasting

The stored access token was rejected by the platform. Steps to fix:
1. Go to **Promote → Settings → Platform Credentials**.
2. Disconnect the platform and reconnect with a fresh token.
3. For Facebook/Instagram: regenerate the page/user token in Graph API Explorer.
4. For Twitter: re-run the OAuth 2.0 PKCE flow.
5. For Threads: check if the 60-day token has expired and refresh it.
6. For Bluesky: verify the App Password is still active in your Bluesky settings.

### Instagram / TikTok post fails with "image not accessible"

- Confirm `APP_URL` in `.env` matches the actual public URL of your Backstage installation.
- Check that the flyer file exists in the `public/uploads/` directory and is publicly readable.
- For TikTok: ensure your domain is allowlisted in the TikTok developer app's **Content Posting API → Media Hosting Domains** setting.

### Twitter posts are cut off unexpectedly

- Review the Twitter variant in the post editor before broadcasting.
- URLs count as 23 characters in Twitter's display but the API still enforces the 280-char total.
- The generator truncates at 280 chars — edit the variant manually if the cut falls in an awkward place.

### Eventbrite event not appearing on the Eventbrite site

- If you used *Schedule* mode instead of *Send Now*, the event was created as a **draft**. Log in to Eventbrite and publish it manually.
- Check that `org_id` is correct in the credentials config. An incorrect org ID may silently succeed but create the event under the wrong organization.

### Email campaign not sending

- **Mailchimp:** Confirm your from_email is a verified domain sender. Check the Mailchimp campaign was created under **Campaigns** in the dashboard.
- **SendGrid:** Confirm sender authentication is complete. Check that `sender_id` matches a verified sender in **Marketing → Senders**.
- Both providers require the list to have at least one subscriber.

### "Failed" on a manual editorial platform

Manual platforms should never fail — they always return `manual_required`. If you see a failure on an editorial destination, there may be a routing issue. Contact your Backstage administrator.

---

## Things to Watch Out For

1. **App Review takes time.** Facebook, Instagram, TikTok, and Threads all require App Review before you can post to live public accounts. This can take days to weeks. Start the review process well in advance of your first event.

2. **Tokens expire.** Twitter tokens (without `offline.access`) expire in ~2 hours. Threads tokens expire in 60 days. TikTok tokens expire periodically. When in doubt, reconnect the platform.

3. **Images must be publicly accessible.** Any platform that fetches your flyer image from a URL will fail if the URL is not publicly reachable. This includes staging/localhost environments — for testing, use a public staging domain or a direct image URL.

4. **Bluesky App Password ≠ your password.** Always create a dedicated App Password in Bluesky settings. Never put your real Bluesky account password in the credentials form.

5. **Luma events go live immediately.** There is no draft/preview mode in the Luma API. Only broadcast to Luma when you are ready for the event to be public.

6. **Editorial submissions are not automated.** Funcheap, Foopee, Dice, Resident Advisor, SF Chronicle, etc. require you to manually copy and paste content into their respective submission forms. The module generates the copy — you do the submitting.

7. **Email unsubscribe links are required.** Both Mailchimp and SendGrid inject unsubscribe links automatically. Do not attempt to remove them — this is a CAN-SPAM legal requirement.

8. **Free-tier rate limits on Twitter/X.** The free API plan is very limited (~17 tweets/day). If you post frequently across multiple events, upgrade to a paid plan.

9. **Eventbrite cover images must be added manually.** After the adapter creates the listing, log in to Eventbrite and upload your flyer from the event editor.

10. **Character limits enforced at broadcast time.** If you edited a variant and added too much text, the adapter may truncate it or the platform API may reject it. Always check warnings in the variant editor before broadcasting.
