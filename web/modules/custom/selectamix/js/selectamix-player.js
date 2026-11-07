/**
 * @file
 * Automated varispeed mix player built on the Web Audio API.
 *
 * Takes a programme — tunes in order, with the connection between each pair —
 * and plays it as one continuous mix. Each tune is an AudioBufferSourceNode;
 * tempo matching is done with playbackRate (pitch moves with speed, as on a
 * varispeed turntable), blends are gain crossfades, and every event is
 * scheduled on the AudioContext clock so cue points line up to the sample.
 *
 * Cue points are authored in bars. Bar 1 beat 1 is the tune's first downbeat,
 * so bar b starts at firstDownbeatSeconds + (b - 1) * 4 * 60 / bpm.
 */
(function (Drupal) {
  'use strict';

  const LEAD_IN = 0.25;

  function barSeconds(bpm) {
    return 4 * 60 / bpm;
  }

  function bufferTimeAtBar(tune, bar) {
    return (tune.firstDownbeatSeconds || 0) + (bar - 1) * barSeconds(tune.bpm);
  }

  /**
   * Works out the clock-time schedule for a programme.
   *
   * Pure: no audio involved, so it can be shown before anything is loaded.
   * Returns { tunes: [...], blends: [...], totalSeconds } where each tune entry
   * holds startClock, startOffset, rate, ramp (optional), endClock and each
   * blend holds outClock, seconds, curve, effectiveBpm.
   */
  function computeSchedule(programme, options = {}) {
    const startBar = options.startBar || 1;
    const tunes = [];
    const blends = [];
    let clock = 0;

    programme.tunes.forEach((tune, i) => {
      if (i === 0) {
        tunes.push({
          tune,
          rate: 1,
          startClock: 0,
          startOffset: bufferTimeAtBar(tune, startBar),
          ramp: null,
          endClock: null,
        });
        return;
      }
      const c = programme.connections[i - 1];
      const a = tunes[i - 1];
      const outBar = c.outCueBar || startBar + 16;
      const inBar = c.inCueBar || 1;
      const blendBars = c.blendBars == null ? 8 : c.blendBars;

      const sOutA = bufferTimeAtBar(a.tune, outBar);
      const sInB = bufferTimeAtBar(tune, inBar);
      const effA = a.tune.bpm * a.rate;
      let eff = effA;
      if (c.tempoMaster === 'to') {
        eff = tune.bpm;
      }
      else if (c.tempoMaster === 'midpoint') {
        eff = (effA + tune.bpm) / 2;
      }
      const rateABlend = eff / a.tune.bpm;
      const rateB = eff / tune.bpm;
      const blendSeconds = c.faderCurve === 'cut' ? 0 : blendBars * barSeconds(eff);

      let outClock;
      if (Math.abs(rateABlend - a.rate) > 1e-6) {
        // Ramp A to the blend tempo over one blend length, ending at the cue.
        let rampSeconds = Math.max(blendBars, 4) * barSeconds(eff);
        let advance = rampSeconds * (a.rate + rateABlend) / 2;
        let sRamp = sOutA - advance;
        if (sRamp < a.startOffset) {
          sRamp = a.startOffset;
          advance = sOutA - sRamp;
          rampSeconds = 2 * advance / (a.rate + rateABlend);
        }
        const rampStartClock = a.startClock + (sRamp - a.startOffset) / a.rate;
        a.ramp = { startClock: rampStartClock, seconds: rampSeconds, from: a.rate, to: rateABlend };
        outClock = rampStartClock + rampSeconds;
      }
      else {
        outClock = a.startClock + (sOutA - a.startOffset) / a.rate;
      }

      a.endClock = outClock + blendSeconds;
      blends.push({
        connection: c,
        outClock,
        seconds: blendSeconds,
        curve: c.faderCurve || 'equal_power',
        effectiveBpm: eff,
        fromIndex: i - 1,
        toIndex: i,
      });
      tunes.push({
        tune,
        rate: rateB,
        startClock: outClock,
        startOffset: sInB,
        ramp: null,
        endClock: null,
      });
      clock = outClock;
    });

    const last = tunes[tunes.length - 1];
    if (last.tune.durationSeconds) {
      last.endClock = last.startClock + (last.tune.durationSeconds - last.startOffset) / last.rate;
    }
    return { tunes, blends, totalSeconds: last.endClock, clock };
  }

  function equalPowerCurve(steps, fadeIn) {
    const curve = new Float32Array(steps);
    for (let i = 0; i < steps; i++) {
      const x = i / (steps - 1);
      curve[i] = fadeIn ? Math.sin(x * Math.PI / 2) : Math.cos(x * Math.PI / 2);
    }
    return curve;
  }

  class Player {
    constructor(options = {}) {
      this.onStatus = options.onStatus || (() => {});
      this.context = null;
      this.buffers = new Map();
      this.nodes = [];
      this.schedule = null;
      this.startedAt = null;
      this.programme = null;
      this.ticker = null;
    }

    ensureContext() {
      if (!this.context) {
        this.context = new (window.AudioContext || window.webkitAudioContext)();
      }
      return this.context;
    }

    async load(programme) {
      const ctx = this.ensureContext();
      const missing = programme.tunes.filter((t) => !t.audioUrl);
      if (missing.length) {
        throw new Error(Drupal.t('No audio file for @tune.', { '@tune': missing[0].title }));
      }
      for (const tune of programme.tunes) {
        if (this.buffers.has(tune.audioUrl)) {
          continue;
        }
        this.onStatus({ state: 'loading', message: Drupal.t('Loading @tune…', { '@tune': tune.title }) });
        const response = await fetch(tune.audioUrl);
        if (!response.ok) {
          throw new Error(Drupal.t('Could not fetch @tune (@status).', { '@tune': tune.title, '@status': response.status }));
        }
        const buffer = await ctx.decodeAudioData(await response.arrayBuffer());
        this.buffers.set(tune.audioUrl, buffer);
        if (!tune.durationSeconds) {
          tune.durationSeconds = buffer.duration;
        }
      }
      this.programme = programme;
      this.schedule = computeSchedule(programme);
      return this.schedule;
    }

    async play(programme) {
      this.stop();
      const ctx = this.ensureContext();
      if (ctx.state === 'suspended') {
        await ctx.resume();
      }
      await this.load(programme);
      const schedule = this.schedule;
      const t0 = ctx.currentTime + LEAD_IN;
      this.startedAt = t0;

      schedule.tunes.forEach((entry, i) => {
        const source = ctx.createBufferSource();
        source.buffer = this.buffers.get(entry.tune.audioUrl);
        const gain = ctx.createGain();
        source.connect(gain).connect(ctx.destination);

        source.playbackRate.setValueAtTime(entry.rate, t0 + entry.startClock);
        if (entry.ramp) {
          source.playbackRate.setValueAtTime(entry.ramp.from, t0 + entry.ramp.startClock);
          source.playbackRate.linearRampToValueAtTime(entry.ramp.to, t0 + entry.ramp.startClock + entry.ramp.seconds);
        }

        // Fade in.
        const blendIn = i > 0 ? schedule.blends[i - 1] : null;
        if (blendIn && blendIn.seconds > 0) {
          gain.gain.setValueAtTime(0, t0 + blendIn.outClock);
          this.applyFade(gain.gain, t0 + blendIn.outClock, blendIn.seconds, blendIn.curve, true);
        }
        else {
          gain.gain.setValueAtTime(1, t0 + entry.startClock);
        }

        // Fade out.
        const blendOut = i < schedule.blends.length ? schedule.blends[i] : null;
        if (blendOut && blendOut.seconds > 0) {
          gain.gain.setValueAtTime(1, t0 + blendOut.outClock);
          this.applyFade(gain.gain, t0 + blendOut.outClock, blendOut.seconds, blendOut.curve, false);
        }

        source.start(t0 + entry.startClock, entry.startOffset);
        if (entry.endClock != null) {
          source.stop(t0 + entry.endClock + 0.05);
        }
        this.nodes.push({ source, gain });
      });

      this.onStatus({ state: 'playing', message: Drupal.t('Playing'), schedule });
      this.tick();
    }

    applyFade(param, when, seconds, curve, fadeIn) {
      if (curve === 'linear') {
        param.linearRampToValueAtTime(fadeIn ? 1 : 0, when + seconds);
      }
      else {
        param.setValueCurveAtTime(equalPowerCurve(64, fadeIn), when, seconds);
      }
    }

    tick() {
      if (!this.startedAt || !this.schedule) {
        return;
      }
      const elapsed = this.context.currentTime - this.startedAt;
      const schedule = this.schedule;
      const current = schedule.tunes.filter((e) => elapsed >= e.startClock && (e.endClock == null || elapsed < e.endClock));
      const nextBlend = schedule.blends.find((b) => b.outClock > elapsed);
      if (schedule.totalSeconds != null && elapsed > schedule.totalSeconds) {
        this.stop();
        this.onStatus({ state: 'finished', message: Drupal.t('Finished') });
        return;
      }
      this.onStatus({
        state: 'playing',
        elapsed,
        current: current.map((e) => e.tune),
        nextBlend,
        secondsToBlend: nextBlend ? nextBlend.outClock - elapsed : null,
        schedule,
      });
      this.ticker = window.requestAnimationFrame(() => this.tick());
    }

    stop() {
      if (this.ticker) {
        window.cancelAnimationFrame(this.ticker);
        this.ticker = null;
      }
      this.nodes.forEach(({ source }) => {
        try {
          source.stop();
        }
        catch (e) {
          // Already stopped or never started.
        }
      });
      this.nodes = [];
      if (this.startedAt !== null) {
        this.startedAt = null;
        this.onStatus({ state: 'stopped', message: Drupal.t('Stopped') });
      }
    }
  }

  function formatClock(seconds) {
    const s = Math.max(0, Math.round(seconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }

  function describeRate(rate) {
    const pct = (rate - 1) * 100;
    const semis = 12 * Math.log2(rate);
    return `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}% (${semis >= 0 ? '+' : ''}${semis.toFixed(2)} st)`;
  }

  /**
   * Renders a computed schedule as an HTML table.
   */
  function scheduleHtml(schedule) {
    const esc = (v) => Drupal.checkPlain(v == null ? '' : String(v));
    const rows = [];
    schedule.tunes.forEach((e, i) => {
      const eff = (e.tune.bpm * e.rate).toFixed(1);
      rows.push(
        `<tr><th scope="row">${formatClock(e.startClock)}</th>` +
        `<td><strong>${esc(e.tune.artist)} – ${esc(e.tune.title)}</strong>` +
        `<span class="selectamix__meta">${esc(e.tune.bpm)} → ${eff} BPM, ${esc(describeRate(e.rate))}` +
        (e.ramp ? `, ${Drupal.t('ramping from @clock')}`.replace('@clock', formatClock(e.ramp.startClock)) : '') +
        `</span></td></tr>`,
      );
      const b = schedule.blends[i];
      if (b) {
        rows.push(
          `<tr class="selectamix__segue-row"><th scope="row">${formatClock(b.outClock)}</th>` +
          `<td>${esc(b.seconds ? Drupal.t('@s s blend at @bpm BPM, @curve', { '@s': b.seconds.toFixed(1), '@bpm': b.effectiveBpm.toFixed(1), '@curve': b.curve }) : Drupal.t('cut at @bpm BPM', { '@bpm': b.effectiveBpm.toFixed(1) }))}</td></tr>`,
        );
      }
    });
    if (schedule.totalSeconds != null) {
      rows.push(`<tr><th scope="row">${formatClock(schedule.totalSeconds)}</th><td>${Drupal.t('End')}</td></tr>`);
    }
    return `<table class="selectamix__schedule">${rows.join('')}</table>`;
  }

  Drupal.selectamix = Drupal.selectamix || {};
  Drupal.selectamix.Player = Player;
  Drupal.selectamix.computeSchedule = computeSchedule;
  Drupal.selectamix.scheduleHtml = scheduleHtml;
  Drupal.selectamix.formatClock = formatClock;
})(Drupal);
