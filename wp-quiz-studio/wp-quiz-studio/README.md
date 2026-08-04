# Quiz Atelier 1.0.0

Quiz Atelier is a WordPress quiz platform with a front-end Studio, Organizations, Workspaces, editorial workflow, analytics, reusable templates and portable embeds.

## Main capabilities

- Complete question engine: single choice, multiple answers, true/false, image choice, poll, open text, numeric, slider, rating, ordering, ranking and matching.
- Playable draft preview and public player.
- Server-side scoring and answer feedback.
- Organizations, roles, seats and Workspace-specific access.
- Private, Organization and Universal quizzes.
- Approval workflow and version history.
- Analytics dashboards and CSV export.
- Iframe, JavaScript, WordPress and Drupal embeds.
- Workspace-based embed domain whitelist.
- Question Library, Templates and Categories.

## 1.0.0 stability changes

The release adds conflict-safe editing, offline recovery, debounced autosave and an Administrator-only System Status page. It also refreshes the production asset filenames to prevent old cached JavaScript and CSS from remaining active after an upgrade.

## Installation

Upload the ZIP from **Plugins → Add New Plugin → Upload Plugin** and replace the existing Quiz Atelier installation. Take a database backup first.

After upgrading, clear page/cache plugins and Cloudflare, then use **Quiz Atelier → System Status** to run a full check.
