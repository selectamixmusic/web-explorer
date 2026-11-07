/**
 * @file
 * Wires the route player page to Drupal.selectamix.Player.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.selectamixRoutePlayer = {
    attach(context) {
      once('selectamix-route-player', '[data-selectamix-route-player]', context).forEach((root) => {
        const programme = (drupalSettings.selectamix || {}).programme;
        if (!programme || !Drupal.selectamix) {
          return;
        }
        const playEl = root.querySelector('[data-selectamix-play]');
        const stopEl = root.querySelector('[data-selectamix-stop]');
        const statusEl = root.querySelector('[data-selectamix-status]');
        const scheduleEl = root.querySelector('[data-selectamix-schedule]');
        const { Player, computeSchedule, scheduleHtml, formatClock } = Drupal.selectamix;

        const playable = programme.tunes.every((t) => t.audioUrl && t.bpm);
        if (programme.tunes.every((t) => t.bpm)) {
          scheduleEl.innerHTML = scheduleHtml(computeSchedule(programme));
        }
        if (!playable) {
          playEl.disabled = true;
          statusEl.textContent = Drupal.t('Every tune needs an audio file and a BPM before this route can play.');
          return;
        }

        const player = new Player({
          onStatus(status) {
            if (status.state === 'playing' && status.elapsed != null) {
              const now = status.current.map((t) => t.title).join(' + ');
              const next = status.secondsToBlend != null
                ? Drupal.t('next blend in @s s', { '@s': Math.ceil(status.secondsToBlend) })
                : Drupal.t('last tune');
              statusEl.textContent = `${formatClock(status.elapsed)} · ${now} · ${next}`;
              if (status.schedule && !scheduleEl.dataset.live) {
                scheduleEl.innerHTML = scheduleHtml(status.schedule);
                scheduleEl.dataset.live = '1';
              }
            }
            else if (status.message) {
              statusEl.textContent = status.message;
            }
            const playing = status.state === 'playing' || status.state === 'loading';
            playEl.disabled = playing;
            stopEl.disabled = !playing;
          },
        });

        playEl.addEventListener('click', () => {
          player.play(programme).catch((e) => {
            statusEl.textContent = e.message;
            playEl.disabled = false;
            stopEl.disabled = true;
          });
        });
        stopEl.addEventListener('click', () => player.stop());
      });
    },
  };
})(Drupal, drupalSettings, once);
