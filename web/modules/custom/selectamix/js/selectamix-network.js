/**
 * @file
 * Mix network explorer.
 *
 * Ports MixNetworkSelectionController from the 2001 Java client: a mix route
 * is built by selecting a first tune, then only tunes reachable by an
 * OUTGOING connection from the last selected tune are selectable. Only the
 * last tune in the route can be deselected.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const COLOURS = {
    node: '#e4e4e7',
    nodeBorder: '#a1a1aa',
    selectable: '#dbeafe',
    selectableBorder: '#2563eb',
    selected: '#dcfce7',
    selectedBorder: '#16a34a',
    unselectable: '#f4f4f5',
    unselectableBorder: '#e4e4e7',
    textMuted: '#a1a1aa',
    text: '#18181b',
    edge: '#a1a1aa',
    edgeCandidate: '#2563eb',
    edgeSelected: '#16a34a',
  };

  const STYLE = [
    {
      selector: 'node',
      style: {
        label: 'data(label)',
        'text-wrap': 'wrap',
        'text-max-width': '120px',
        'text-valign': 'center',
        'text-halign': 'center',
        'font-size': '11px',
        color: COLOURS.text,
        width: '130px',
        height: '60px',
        shape: 'round-rectangle',
        'background-color': COLOURS.node,
        'border-width': 2,
        'border-color': COLOURS.nodeBorder,
      },
    },
    {
      selector: 'node.selectable',
      style: {
        'background-color': COLOURS.selectable,
        'border-color': COLOURS.selectableBorder,
      },
    },
    {
      selector: 'node.selected',
      style: {
        'background-color': COLOURS.selected,
        'border-color': COLOURS.selectedBorder,
        'border-width': 3,
      },
    },
    {
      selector: 'node.tail',
      style: {
        'border-width': 5,
      },
    },
    {
      selector: 'node.unselectable',
      style: {
        'background-color': COLOURS.unselectable,
        'border-color': COLOURS.unselectableBorder,
        color: COLOURS.textMuted,
      },
    },
    {
      selector: 'edge',
      style: {
        width: 2,
        'curve-style': 'bezier',
        'line-color': COLOURS.edge,
        'target-arrow-shape': 'triangle',
        'target-arrow-color': COLOURS.edge,
        'arrow-scale': 1.2,
      },
    },
    {
      selector: 'edge.candidate',
      style: {
        width: 3,
        'line-style': 'dashed',
        'line-color': COLOURS.edgeCandidate,
        'target-arrow-color': COLOURS.edgeCandidate,
      },
    },
    {
      selector: 'edge.selected',
      style: {
        width: 5,
        'line-color': COLOURS.edgeSelected,
        'target-arrow-color': COLOURS.edgeSelected,
      },
    },
  ];

  /**
   * The selection rules, independent of any rendering.
   */
  class MixRoute {
    constructor(network, limits) {
      this.tunes = new Map(network.tunes.map((t) => [t.id, t]));
      this.connections = new Map(network.connections.map((c) => [c.id, c]));
      this.outgoing = new Map();
      network.connections.forEach((c) => {
        if (!this.outgoing.has(c.from)) {
          this.outgoing.set(c.from, []);
        }
        this.outgoing.get(c.from).push(c);
      });
      this.maxTunes = limits.maxTunes || 0;
      this.minTunes = limits.minTunes || 0;
      this.route = [];
      this.edges = [];
    }

    get last() {
      return this.route.length ? this.route[this.route.length - 1] : null;
    }

    isSelected(tuneId) {
      return this.route.includes(tuneId);
    }

    maxReached() {
      return this.maxTunes > 0 && this.route.length >= this.maxTunes;
    }

    isComplete() {
      return this.route.length >= Math.max(1, this.minTunes);
    }

    connectionBetween(fromId, toId) {
      return (this.outgoing.get(fromId) || []).find((c) => c.to === toId) || null;
    }

    canSelect(tuneId) {
      if (this.isSelected(tuneId)) {
        return false;
      }
      if (!this.last) {
        return true;
      }
      return !this.maxReached() && this.connectionBetween(this.last, tuneId) !== null;
    }

    select(tuneId) {
      if (!this.canSelect(tuneId)) {
        return false;
      }
      if (this.last) {
        this.edges.push(this.connectionBetween(this.last, tuneId).id);
      }
      this.route.push(tuneId);
      return true;
    }

    deselect(tuneId) {
      if (this.last !== tuneId) {
        return false;
      }
      this.route.pop();
      this.edges.pop();
      return true;
    }

    clear() {
      this.route = [];
      this.edges = [];
    }
  }

  function escapeHtml(value) {
    return Drupal.checkPlain(value == null ? '' : String(value));
  }

  function tuneLabel(tune) {
    const parts = [tune.artist, tune.title].filter(Boolean).join(' – ');
    return tune.version ? `${parts} (${tune.version})` : parts;
  }

  function tuneMeta(tune) {
    const bits = [];
    if (tune.bpm) {
      bits.push(`${tune.bpm} BPM`);
    }
    if (tune.key) {
      bits.push(tune.key);
    }
    return bits.join(' · ');
  }

  function audioTag(url) {
    return url ? `<audio controls preload="none" src="${escapeHtml(url)}"></audio>` : '';
  }

  function segueText(c) {
    const bits = [];
    if (c.outCueBar != null) {
      bits.push(Drupal.t('out at bar @bar', { '@bar': c.outCueBar }));
    }
    if (c.inCueBar != null) {
      bits.push(Drupal.t('in at bar @bar', { '@bar': c.inCueBar }));
    }
    if (c.blendBars != null) {
      bits.push(Drupal.t('@bars bar blend', { '@bars': c.blendBars }));
    }
    return bits.length ? bits.join(', ') : Drupal.t('cue points not set yet');
  }

  function init(root, network, limits) {
    const graphEl = root.querySelector('[data-selectamix-graph]');
    const routeEl = root.querySelector('[data-selectamix-route]');
    const detailEl = root.querySelector('[data-selectamix-detail]');
    const clearEl = root.querySelector('[data-selectamix-clear]');
    const playEl = root.querySelector('[data-selectamix-play]');
    const stopEl = root.querySelector('[data-selectamix-stop]');
    const statusEl = root.querySelector('[data-selectamix-status]');
    const model = new MixRoute(network, limits);
    const player = Drupal.selectamix && Drupal.selectamix.Player
      ? new Drupal.selectamix.Player({ onStatus: showStatus })
      : null;

    function showStatus(status) {
      if (status.state === 'playing' && status.elapsed != null) {
        const now = status.current.map((t) => t.title).join(' + ');
        const next = status.secondsToBlend != null
          ? Drupal.t('next blend in @s s', { '@s': Math.ceil(status.secondsToBlend) })
          : Drupal.t('last tune');
        statusEl.textContent = `${Drupal.selectamix.formatClock(status.elapsed)} · ${now} · ${next}`;
      }
      else if (status.message) {
        statusEl.textContent = status.message;
      }
      const busy = status.state === 'playing' || status.state === 'loading';
      stopEl.disabled = !busy;
      playEl.disabled = busy || !routeIsPlayable();
    }

    function routeIsPlayable() {
      return model.route.length > 1 && model.route.every((id) => {
        const t = model.tunes.get(id);
        return t.audioUrl && t.bpm;
      });
    }

    function programmeFromRoute() {
      return {
        id: 'adhoc',
        title: Drupal.t('Explorer route'),
        tunes: model.route.map((id) => model.tunes.get(id)),
        connections: model.edges.map((id) => model.connections.get(id)),
      };
    }

    const cy = cytoscape({
      container: graphEl,
      elements: [
        ...network.tunes.map((t) => ({
          data: { id: t.id, label: `${t.artist || ''}\n${t.title}` },
        })),
        ...network.connections.map((c) => ({
          data: { id: c.id, source: c.from, target: c.to },
        })),
      ],
      style: STYLE,
      layout: { name: 'circle', padding: 40 },
      boxSelectionEnabled: false,
      autounselectify: true,
      wheelSensitivity: 0.2,
    });

    function renderGraph() {
      cy.batch(() => {
        cy.elements().removeClass('selected selectable unselectable tail candidate');
        const last = model.last;
        cy.nodes().forEach((n) => {
          const id = n.id();
          if (model.isSelected(id)) {
            n.addClass(id === last ? 'selected tail' : 'selected');
          }
          else if (model.canSelect(id)) {
            n.addClass('selectable');
          }
          else {
            n.addClass('unselectable');
          }
        });
        cy.edges().forEach((e) => {
          if (model.edges.includes(e.id())) {
            e.addClass('selected');
          }
          else if (last && e.data('source') === last && model.canSelect(e.data('target'))) {
            e.addClass('candidate');
          }
        });
      });
    }

    function renderRoute() {
      const items = [];
      model.route.forEach((tuneId, i) => {
        const tune = model.tunes.get(tuneId);
        if (i > 0) {
          const c = model.connections.get(model.edges[i - 1]);
          items.push(`<li class="selectamix__segue">↓ ${escapeHtml(segueText(c))}</li>`);
        }
        items.push(
          `<li><strong>${escapeHtml(tuneLabel(tune))}</strong>` +
          `<span class="selectamix__meta">${escapeHtml(tuneMeta(tune))}</span>` +
          audioTag(tune.previewUrl) +
          '</li>',
        );
      });
      routeEl.innerHTML = items.join('');
      clearEl.disabled = model.route.length === 0;
      playEl.disabled = !player || !routeIsPlayable();
      if (model.route.length > 1 && !routeIsPlayable()) {
        statusEl.textContent = Drupal.t('Every tune in the route needs an audio file and a BPM to play.');
      }
      else if (!stopEl.disabled === false && model.route.length <= 1) {
        statusEl.textContent = '';
      }
    }

    function showConnection(connectionId) {
      const c = model.connections.get(connectionId);
      const from = model.tunes.get(c.from);
      const to = model.tunes.get(c.to);
      const rows = [
        [Drupal.t('Outgoing'), tuneLabel(from)],
        [Drupal.t('Incoming'), tuneLabel(to)],
        [Drupal.t('Out cue bar'), c.outCueBar ?? '—'],
        [Drupal.t('In cue bar'), c.inCueBar ?? '—'],
        [Drupal.t('Blend'), c.blendBars != null ? Drupal.t('@bars bars', { '@bars': c.blendBars }) : '—'],
        [Drupal.t('Tempo master'), c.tempoMaster || '—'],
        [Drupal.t('Fader curve'), c.faderCurve || '—'],
      ];
      detailEl.innerHTML =
        `<h3>${Drupal.t('Mix connection')}</h3>` +
        '<dl>' + rows.map(([k, v]) => `<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd>`).join('') + '</dl>' +
        (c.notes ? `<p>${escapeHtml(c.notes)}</p>` : '') +
        audioTag(c.clipUrl) +
        `<p><a href="${escapeHtml(c.url)}">${Drupal.t('Edit connection')}</a></p>`;
    }

    function render() {
      renderGraph();
      renderRoute();
    }

    cy.on('tap', 'node', (evt) => {
      const id = evt.target.id();
      if (model.isSelected(id)) {
        model.deselect(id);
      }
      else {
        model.select(id);
      }
      render();
    });

    cy.on('tap', 'edge', (evt) => showConnection(evt.target.id()));

    clearEl.addEventListener('click', () => {
      if (player) {
        player.stop();
      }
      model.clear();
      detailEl.innerHTML = '';
      statusEl.textContent = '';
      render();
    });

    if (player) {
      playEl.addEventListener('click', () => {
        player.play(programmeFromRoute()).catch((e) => {
          statusEl.textContent = e.message;
          playEl.disabled = !routeIsPlayable();
          stopEl.disabled = true;
        });
      });
      stopEl.addEventListener('click', () => player.stop());
    }

    render();
  }

  Drupal.behaviors.selectamixNetwork = {
    attach(context) {
      once('selectamix-network', '[data-selectamix-network]', context).forEach((root) => {
        const settings = drupalSettings.selectamix || {};
        if (typeof cytoscape !== 'function' || !settings.network) {
          return;
        }
        init(root, settings.network, settings.limits || {});
      });
    },
  };
})(Drupal, drupalSettings, once);
