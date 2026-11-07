# Selectamix web explorer

A Drupal 11 rebuild of the 2000–2001 Java Selectamix client
(https://github.com/selectamixmusic/selectamix-java-client): tunes are nodes,
human-curated mixes between them are directed edges, and a route through the
network is a playable mix.

## Local setup (ddev)

```sh
limactl start docker        # if Docker (Lima) is not running
ddev start
ddev composer install
ddev drush site:install standard --account-name=admin --account-pass=admin -y
ddev drush en selectamix jsonapi -y
ddev drush php:script scripts/create-content-model.php
ddev drush php:script scripts/seed-test-network.php
python3 scripts/generate-test-audio.py     # synthetic tunes, needs ffmpeg
ddev drush php:script scripts/seed-test-audio.php
```

Then open https://selectamix.ddev.site/mix-network to explore, or
https://selectamix.ddev.site/mix-routes to play a saved route. The data is
also served as JSON at `/selectamix/network.json` and
`/selectamix/route/{nid}/programme.json`, and through core JSON:API.

## Content model

| Type | Purpose | Key fields |
| --- | --- | --- |
| `tune` | One specific recording | artist, version, BPM, key, duration, first downbeat, ISRC, MusicBrainz ID, audio file, preview clip |
| `mix_connection` | A directed mix from an outgoing tune into an incoming tune | from tune, to tune, out cue bar, in cue bar, blend bars, tempo master, fader curve, mix clip, notes |
| `mix_route` | An ordered chain of connections: a shareable mix | connections, notes |

Mix connection titles are generated on save from the two tunes.

Cue points are authored in bars. The player resolves them to seconds with the
tune's BPM and first-downbeat offset, so the same route works against any copy
of the recording that shares the ISRC.

## Code

- `web/modules/custom/selectamix` — the module: content model config,
  the `/mix-network` page, the JSON endpoint, and the Cytoscape explorer in
  `js/selectamix-network.js`, which ports the Java selection rules
  (`MixNetworkSelectionController`): only tunes reachable by an outgoing
  connection from the last selected tune are selectable, and only the tail of
  the route can be deselected.
- `js/selectamix-player.js` — the automated varispeed player. It takes a
  programme (tunes in order plus the connection between each pair), computes a
  clock schedule from BPM, first downbeat and cue bars, then plays it with the
  Web Audio API: one buffer source per tune, `playbackRate` for tempo matching
  (pitch follows speed, as on a varispeed deck), gain curves for the blend, and
  rate ramps ahead of a cue when the incoming tune sets the tempo. Everything
  is scheduled on the audio clock up front, so cues align to the sample.
  The same player drives `/mix-route/{nid}/play` and the explorer's Play
  button.
- `scripts/` — idempotent Drush PHP scripts for the content model and the
  six-tune, eight-connection test network from the Java client, plus a Python
  generator for synthetic test tunes (steady beat at a known BPM, own bass
  note, chord change every eight bars) so blends can be heard before real
  audio is attached. Generated audio lands in `web/sites/default/files` and
  is not committed.
- `config/sync` — exported site configuration.
