(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
      return;
    }
    document.addEventListener('DOMContentLoaded', fn);
  }

  function createSvg(width, height) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('tabindex', '0');
    return svg;
  }

  function attrs(el, values) {
    Object.keys(values).forEach((key) => el.setAttribute(key, values[key]));
  }

  function seedNodes(nodes, width, height) {
    const cx = width / 2;
    const cy = height / 2;
    const radius = Math.max(90, Math.min(width, height) / 3);
    return nodes.map((node, index) => {
      const angle = (Math.PI * 2 * index) / nodes.length - Math.PI / 2;
      return {
        ...node,
        x: cx + Math.cos(angle) * radius,
        y: cy + Math.sin(angle) * radius
      };
    });
  }

  function degreeMap(nodes, edges) {
    const degrees = new Map(nodes.map((node) => [Number(node.id), 0]));
    edges.forEach((edge) => {
      degrees.set(Number(edge.source), (degrees.get(Number(edge.source)) || 0) + 1);
      degrees.set(Number(edge.target), (degrees.get(Number(edge.target)) || 0) + 1);
    });
    return degrees;
  }

  function opacityFor(depth, values, fallback) {
    if (!Array.isArray(values) || !values.length) return fallback;
    return values[Math.min(depth || 0, values.length - 1)] ?? fallback;
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function resistant(value, min, max) {
    if (value < min) return min + (value - min) * 0.28;
    if (value > max) return max + (value - max) * 0.28;
    return value;
  }

  function createControls(actions) {
    const controls = document.createElement('div');
    controls.className = 'pkb-graph-controls';

    [
      ['화면 초기화', '⛶', actions.reset],
      ['확대', '+', actions.zoomIn],
      ['축소', '-', actions.zoomOut]
    ].forEach(([label, text, action]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('aria-label', label);
      button.title = label;
      button.textContent = text;
      button.addEventListener('click', action);
      controls.appendChild(button);
    });

    return controls;
  }

  function createInteractionOverlay(unlock) {
    const overlay = document.createElement('button');
    overlay.type = 'button';
    overlay.className = 'pkb-graph-lock-overlay';
    overlay.textContent = '클릭 또는 탭하여 연결된 글 확인';
    overlay.setAttribute('aria-label', '그래프 조작 활성화');
    overlay.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      unlock();
    });
    return overlay;
  }

  function renderGraph(container, graph) {
    container.innerHTML = '';
    if (!graph.nodes || !graph.nodes.length) {
      container.innerHTML = '<p class="pkb-graph-empty">표시할 연결 그래프가 없습니다.</p>';
      return;
    }

    const width = Math.max(container.clientWidth || 720, 320);
    const height = Math.max(container.clientHeight || 500, 320);
    const svg = createSvg(width, height);
    const viewport = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    const degrees = degreeMap(graph.nodes, graph.edges);
    const nodes = seedNodes(graph.nodes, width, height).map((node) => ({
      ...node,
      degree: degrees.get(Number(node.id)) || 0,
      radius: Math.min(14, 6 + Math.sqrt(degrees.get(Number(node.id)) || 0) * 2.2),
      labelWidth: Math.min(220, Math.max(56, String(node.title || '').length * 8))
    }));
    const links = graph.edges
      .filter((edge) => degrees.has(Number(edge.source)) && degrees.has(Number(edge.target)))
      .map((edge) => ({
        source: Number(edge.source),
        target: Number(edge.target)
      }));
    const byId = new Map(nodes.map((node) => [Number(node.id), node]));
    const state = {
      x: 0,
      y: 0,
      scale: 1,
      minScale: 0.65,
      maxScale: 2.8,
      dragging: false,
      moved: false,
      downNode: null,
      pointerId: null,
      startX: 0,
      startY: 0,
      baseX: 0,
      baseY: 0,
      locked: true
    };

    container.classList.add('is-locked');

    function lockInteraction() {
      if (state.dragging) {
        state.dragging = false;
        state.pointerId = null;
        svg.classList.remove('is-dragging');
      }
      state.locked = true;
      container.classList.add('is-locked');
      container.classList.remove('is-active');
    }

    function unlockInteraction() {
      state.locked = false;
      container.classList.remove('is-locked');
      container.classList.add('is-active');
      svg.focus({ preventScroll: true });
    }

    function bounds(scale = state.scale) {
      const extraX = width * 0.18;
      const extraY = height * 0.18;
      return {
        minX: width - width * scale - extraX,
        maxX: extraX,
        minY: height - height * scale - extraY,
        maxY: extraY
      };
    }

    function apply() {
      viewport.setAttribute('transform', `translate(${state.x} ${state.y}) scale(${state.scale})`);
    }

    function clearHover() {
      nodeEls.forEach(({ group, circle, label }) => {
        group.classList.remove('is-hovered', 'is-neighbor', 'is-dimmed');
        circle.setAttribute('fill', group.dataset.baseFill || '#1d1d1f');
        label.setAttribute('fill', '#1d1d1f');
      });
      lineEls.forEach(({ line }) => {
        line.classList.remove('is-hovered', 'is-dimmed');
        line.setAttribute('stroke', '#b8b4aa');
        line.setAttribute('stroke-width', '1');
      });
    }

    function setHover(node) {
      const nodeId = Number(node.id);
      const neighbors = new Set([nodeId]);
      lineEls.forEach(({ edge }) => {
        const source = Number(typeof edge.source === 'object' ? edge.source.id : edge.source);
        const target = Number(typeof edge.target === 'object' ? edge.target.id : edge.target);
        if (source === nodeId) neighbors.add(target);
        if (target === nodeId) neighbors.add(source);
      });

      nodeEls.forEach(({ node: item, group, circle, label }) => {
        const id = Number(item.id);
        group.classList.toggle('is-hovered', id === nodeId);
        group.classList.toggle('is-neighbor', id !== nodeId && neighbors.has(id));
        group.classList.toggle('is-dimmed', !neighbors.has(id));
        circle.setAttribute('fill', id === nodeId ? '#2f5f55' : (neighbors.has(id) ? '#546b63' : '#b8b4aa'));
        label.setAttribute('fill', id === nodeId ? '#2f5f55' : (neighbors.has(id) ? '#2d3a36' : '#9a968e'));
      });

      lineEls.forEach(({ edge, line }) => {
        const source = Number(typeof edge.source === 'object' ? edge.source.id : edge.source);
        const target = Number(typeof edge.target === 'object' ? edge.target.id : edge.target);
        const connected = source === nodeId || target === nodeId;
        line.classList.toggle('is-hovered', connected);
        line.classList.toggle('is-dimmed', !connected);
        line.setAttribute('stroke', connected ? '#2f5f55' : '#d8d4cb');
        line.setAttribute('stroke-width', connected ? '1.8' : '1');
      });
    }

    function settle() {
      const b = bounds();
      const targetX = clamp(state.x, b.minX, b.maxX);
      const targetY = clamp(state.y, b.minY, b.maxY);
      const fromX = state.x;
      const fromY = state.y;
      const start = performance.now();
      const duration = 260;

      function frame(now) {
        const t = clamp((now - start) / duration, 0, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        state.x = fromX + (targetX - fromX) * eased;
        state.y = fromY + (targetY - fromY) * eased;
        apply();
        if (t < 1) requestAnimationFrame(frame);
      }

      requestAnimationFrame(frame);
    }

    function setScale(nextScale, originX = width / 2, originY = height / 2) {
      const oldScale = state.scale;
      const scale = clamp(nextScale, state.minScale, state.maxScale);
      const graphX = (originX - state.x) / oldScale;
      const graphY = (originY - state.y) / oldScale;
      state.scale = scale;
      state.x = originX - graphX * scale;
      state.y = originY - graphY * scale;
      const b = bounds();
      state.x = clamp(state.x, b.minX, b.maxX);
      state.y = clamp(state.y, b.minY, b.maxY);
      apply();
    }

    function reset() {
      state.x = 0;
      state.y = 0;
      state.scale = 1;
      apply();
    }

    const lineEls = links.map((edge) => {
      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      attrs(line, {
        stroke: '#b8b4aa',
        'stroke-width': 1,
        opacity: 0.58
      });
      viewport.appendChild(line);
      return { edge, line };
    });

    const nodeEls = nodes.map((node) => {
      const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      group.classList.add('pkb-graph-node');
      group.dataset.nodeId = String(node.id);

      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      attrs(circle, {
        r: node.radius,
        fill: node.depth === 0 ? '#2f5f55' : '#1d1d1f',
        opacity: opacityFor(node.depth, window.PKB.graph.nodeOpacity, 0.8)
      });
      group.dataset.baseFill = node.depth === 0 ? '#2f5f55' : '#1d1d1f';

      const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      label.classList.add('pkb-graph-label');
      label.textContent = node.title;
      attrs(label, {
        y: 0,
        fill: '#1d1d1f',
        opacity: opacityFor(node.depth, window.PKB.graph.nodeOpacity, 0.8)
      });

      group.appendChild(circle);
      group.appendChild(label);
      viewport.appendChild(group);
      group.addEventListener('mouseenter', () => setHover(node));
      group.addEventListener('mouseleave', clearHover);
      return { node, group, circle, label };
    });

    svg.appendChild(viewport);
    container.appendChild(createControls({
      reset,
      zoomIn: () => setScale(state.scale * 1.18),
      zoomOut: () => setScale(state.scale / 1.18)
    }));
    container.appendChild(svg);
    container.appendChild(createInteractionOverlay(unlockInteraction));
    apply();

    function ticked() {
      lineEls.forEach(({ edge, line }) => {
        const source = typeof edge.source === 'object' ? edge.source : byId.get(Number(edge.source));
        const target = typeof edge.target === 'object' ? edge.target : byId.get(Number(edge.target));
        if (!source || !target) return;
        attrs(line, {
          x1: source.x,
          y1: source.y,
          x2: target.x,
          y2: target.y,
          opacity: opacityFor(Math.max(source.depth || 0, target.depth || 0), window.PKB.graph.edgeOpacity, 0.55)
        });
      });

      nodeEls.forEach(({ node, group }) => {
        const labelOnLeft = node.x > width / 2;
        const label = group.querySelector('text');
        if (label) {
          label.setAttribute('x', labelOnLeft ? -(node.radius + 8) : node.radius + 8);
          label.setAttribute('text-anchor', labelOnLeft ? 'end' : 'start');
        }
        group.setAttribute('transform', `translate(${node.x} ${node.y})`);
      });
    }

    if (window.d3 && window.d3.forceSimulation) {
      window.d3.forceSimulation(nodes)
        .force('link', window.d3.forceLink(links).id((node) => Number(node.id)).distance((link) => {
          const sourceDegree = link.source.degree || 1;
          const targetDegree = link.target.degree || 1;
          return 110 + Math.min(70, (sourceDegree + targetDegree) * 8);
        }).strength(0.55))
        .force('charge', window.d3.forceManyBody().strength((node) => -260 - node.degree * 70))
        .force('center', window.d3.forceCenter(width / 2, height / 2).strength(0.08))
        .force('collision', window.d3.forceCollide().radius((node) => node.radius + node.labelWidth * 0.45 + 28).strength(0.95))
        .force('x', window.d3.forceX(width / 2).strength(0.035))
        .force('y', window.d3.forceY(height / 2).strength(0.035))
        .alpha(1)
        .alphaDecay(0.035)
        .on('tick', ticked);
    } else {
      ticked();
    }

    svg.addEventListener('wheel', (event) => {
      if (state.locked) return;
      event.preventDefault();
      const rect = svg.getBoundingClientRect();
      const factor = event.deltaY < 0 ? 1.08 : 0.92;
      setScale(state.scale * factor, event.clientX - rect.left, event.clientY - rect.top);
    }, { passive: false });

    svg.addEventListener('pointerdown', (event) => {
      if (state.locked) return;
      const nodeEl = event.target.closest ? event.target.closest('.pkb-graph-node') : null;
      state.dragging = true;
      state.moved = false;
      state.downNode = nodeEl ? byId.get(Number(nodeEl.dataset.nodeId)) : null;
      state.pointerId = event.pointerId;
      state.startX = event.clientX;
      state.startY = event.clientY;
      state.baseX = state.x;
      state.baseY = state.y;
      svg.classList.add('is-dragging');
      svg.setPointerCapture(event.pointerId);
    });

    svg.addEventListener('pointermove', (event) => {
      if (state.locked) return;
      if (!state.dragging || state.pointerId !== event.pointerId) return;
      const dx = event.clientX - state.startX;
      const dy = event.clientY - state.startY;
      if (Math.abs(dx) + Math.abs(dy) > 4) state.moved = true;
      const b = bounds();
      state.x = resistant(state.baseX + dx, b.minX, b.maxX);
      state.y = resistant(state.baseY + dy, b.minY, b.maxY);
      apply();
    });

    function endDrag(event) {
      if (state.locked) return;
      if (!state.dragging || state.pointerId !== event.pointerId) return;
      state.dragging = false;
      state.pointerId = null;
      if (!state.moved && state.downNode) {
        const url = state.downNode.url;
        state.downNode = null;
        window.location.href = url;
        return;
      }
      state.downNode = null;
      svg.classList.remove('is-dragging');
      settle();
      window.setTimeout(() => {
        state.moved = false;
      }, 120);
    }

    svg.addEventListener('pointerup', endDrag);
    svg.addEventListener('pointercancel', endDrag);
    svg.addEventListener('lostpointercapture', () => {
      if (!state.dragging) return;
      state.dragging = false;
      state.pointerId = null;
      svg.classList.remove('is-dragging');
      settle();
    });

    document.addEventListener('pointerdown', (event) => {
      if (state.locked || container.contains(event.target)) return;
      lockInteraction();
    });

    document.addEventListener('keydown', (event) => {
      if (state.locked || event.key !== 'Escape') return;
      lockInteraction();
    });
  }

  async function loadGraph(container) {
    const root = container.dataset.root;
    const params = new URLSearchParams();
    if (root) {
      params.set('root', root);
      params.set('depth', window.PKB.graph.depth || 2);
    }

    const graph = await window.wp.apiFetch({
      path: `/pkb/v1/graph${params.toString() ? `?${params.toString()}` : ''}`
    });
    renderGraph(container, graph);
  }

  ready(function () {
    document.querySelectorAll('[data-pkb-graph]').forEach((container) => {
      loadGraph(container).catch((error) => {
        window.console.error(error);
        container.innerHTML = '<p class="pkb-graph-empty">Graph View를 불러오지 못했습니다.</p>';
      });
    });
  });
})();
