# Arkisto Reporter

Minimal WordPress plugin that registers a site with Arkisto and reports installed plugins daily.

## Setup

1. Install via Composer (`composer require arkisto/arkisto-reporter`) or copy this plugin into `wp-content/plugins/`.
2. Activate **Arkisto Reporter**.
3. In Arkisto, open **Sites → Add site** and create an entry for this WordPress install.
4. Open that site in Arkisto and copy the **API base URL** and **registration token**.
5. Open **Settings → Arkisto Reporter** in WordPress and paste both values.
6. Click **Register site**, then **Send heartbeat now**.

Each WordPress site needs its own registration token. Rotate a token from the site page in Arkisto if it is compromised.
