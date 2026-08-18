# Roadmap

## 0.1: Usable integration shell

- [x] Drupal 10.3+/11 module metadata
- [x] Drupal Gutenberg 4.x dependency
- [x] Responsive content/wide editor widths
- [x] Iframe and non-iframe editor canvas support
- [x] Gutenberg header/Drupal toolbar coexistence
- [x] Drupal field catalog
- [x] Gutenberg-native Drupal field panel with DOM fallback
- [x] Runtime package capability detection
- [x] Admin settings and status page
- [x] CI syntax baseline

## 0.2: Data adapter

- [x] Replace field DOM jumps with a real Drupal entity data store
- [x] Read/write field values through editor state
- [x] Map supported Drupal fields into Block Bindings sources
- [x] Validation/error synchronization
- [x] Dirty-state and autosave synchronization
- [x] Entity-reference autocomplete adapter

## 0.3: Publishing parity

- [x] Drupal status in Gutenberg pre-publish flow
- [x] Scheduled publishing
- [x] Author/entity-reference controls
- [x] Taxonomy controls
- [x] URL alias/permalink integration
- [x] Featured media integration
- [x] Content Moderation workflow states

## 0.4: Revision parity

- [ ] Drupal entity revision browser
- [ ] Gutenberg visual change comparison
- [ ] Restore revision
- [ ] Revision author/message metadata

## 0.5: Theme and design parity

- [ ] Global Styles adapter
- [ ] Drupal design-token/theme settings bridge
- [ ] Style Book compatibility
- [ ] Font library adapter
- [ ] Responsive preview regression suite

## 0.6: Editor parity and polish

- [ ] Command Palette Drupal commands
- [ ] Preferences persistence in Drupal user data
- [ ] Patterns/synced patterns Drupal-native persistence audit
- [ ] Notes/comments adapter
- [ ] Accessibility regression suite
- [ ] Keyboard/navigation parity suite

## 0.7+: Collaboration

- [ ] Presence
- [ ] Conflict detection
- [ ] Notes/mentions integration
- [ ] Evaluate upstream realtime collaboration APIs

## Stable criteria

A stable 1.0 requires Drupal 11, a supported Drupal Gutenberg/upstream package baseline, no known data-loss bugs, migration tests for Gutenberg content, editor/browser regression coverage, and a documented upgrade policy.
