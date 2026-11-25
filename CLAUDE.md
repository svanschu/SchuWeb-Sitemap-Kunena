# SchuWeb Sitemap – Kunena plugin

Plugin `plg_schuweb_sitemap_kunena` for SchuWeb Sitemap. It adds the content of **Kunena forum (categories and topics)** to the sitemap tree.

**Read `../SchuWeb-Sitemap/CLAUDE.md` first.** It describes the plugin event API (`onGetMenus`, `onGetTree`),
the node structure, the J4/J5/J6 compatibility rules, build placeholders, the dev containers and the release routine.
All of that applies here.

## Layout

- `src/kunena/`: the plugin (manifest `kunena.xml`, `services/provider.php`, `src/Extension/`, `language/`)
- `builder.xml`: Phing build, run with `../SchuWeb-Sitemap/dev/build.sh X.Y.Z` (Phing 3 in a container)
- `update.xml` / `changelog.xml`: update and changelog feeds (released from `main`)
- Dev symlinks into the Joomla containers: created by `../SchuWeb-Sitemap/dev/setup-dev-container.sh` (links all plugin repos)

## Notes

- Namespace: `SchuWeb\Plugin\SchuWeb_Sitemap\<Name>`.
- Kunena forum (categories and topics) must be installed in the dev Joomla to test this plugin. Its source is in the dev web roots
  (`~/container/sitemap-dev/{j4,j5,j6}/html`), so look up its tables and routing helpers there.
- `update.xml` currently has targetplatform entries for Joomla 4 and 5 only. Add a Joomla 6 entry once the plugin has been verified on J6.
