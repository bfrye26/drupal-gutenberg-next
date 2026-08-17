# GitHub repository setup

## Repository

- **URL:** `https://github.com/bfrye26/drupal-gutenberg-next`
- **Default branch:** `main`
- **Visibility:** public
- **Description:** Modern Gutenberg editor integration for Drupal with Drupal-native entity, media, workflow and revision adapters.
- **License:** GPL-2.0-or-later
- **Topics:** `drupal`, `gutenberg`, `block-editor`, `cms`, `react`, `wordpress-gutenberg`

## Repository protections to enable

Once CI is running reliably:

- Require a pull request before merging to `main`.
- Require the `syntax` CI job.
- Block force pushes and branch deletion on `main`.
- Enable Dependabot alerts and security updates.
- Prefer squash merges for feature branches.

## Upstream remotes after the full source import

The repository should eventually keep both of these concepts explicit:

- `origin`: the GitHub project.
- `drupal-upstream`: `https://git.drupalcode.org/project/gutenberg.git`.

WordPress Gutenberg should normally be consumed through `@wordpress/*` packages rather than added as a Git remote for routine development.
