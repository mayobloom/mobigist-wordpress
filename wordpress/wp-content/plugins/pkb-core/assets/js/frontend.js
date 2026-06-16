(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
      return;
    }
    document.addEventListener('DOMContentLoaded', fn);
  }

  async function toggleLike(button) {
    const type = button.dataset.pkbLike;
    const id = button.dataset.id;
    const path = type === 'comment' ? `/comment-likes/${id}` : `/likes/post/${id}`;

    button.disabled = true;
    try {
      const response = await window.wp.apiFetch({
        path: `/pkb/v1${path}`,
        method: 'POST',
        headers: { 'X-WP-Nonce': window.PKB.nonce }
      });
      button.classList.toggle('is-liked', !!response.liked);
      const count = button.querySelector('span');
      if (count) count.textContent = response.count;
    } catch (error) {
      window.console.error(error);
    } finally {
      button.disabled = false;
    }
  }

  function initTableViewer() {
    const figures = Array.from(document.querySelectorAll('figure.wp-block-table'));
    if (!figures.length) return;

    let activeFigure = null;
    let modal = null;
    let stage = null;
    let content = null;
    let state = {
      scale: 1,
      fitScale: 1,
      x: 0,
      y: 0,
      rotation: 0,
      dragging: false,
      dragStartX: 0,
      dragStartY: 0,
      startX: 0,
      startY: 0,
      moved: false,
      pointers: new Map(),
      pinchStartDistance: 0,
      pinchStartScale: 1,
      renderToken: 0
    };

    function iconExpand() {
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 3H3v5"/><path d="M3 3l7 7"/><path d="M16 3h5v5"/><path d="M21 3l-7 7"/><path d="M8 21H3v-5"/><path d="M3 21l7-7"/><path d="M16 21h5v-5"/><path d="M21 21l-7-7"/></svg>';
    }

    function iconReset() {
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 5h18v14H3z"/><path d="M8 9h8"/><path d="M8 15h8"/></svg>';
    }

    function iconRotate() {
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
    }

    function iconClose() {
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }

    function ensureModal() {
      if (modal) return;

      modal = document.createElement('div');
      modal.className = 'pkb-table-modal';
      modal.hidden = true;
      modal.innerHTML = `
        <div class="pkb-table-modal-backdrop" data-pkb-table-close></div>
        <div class="pkb-table-modal-toolbar" aria-label="Table viewer controls">
          <button type="button" class="pkb-table-modal-button" data-pkb-table-reset aria-label="Reset table view">${iconReset()}</button>
          <button type="button" class="pkb-table-modal-button" data-pkb-table-rotate aria-label="Rotate table view">${iconRotate()}</button>
          <button type="button" class="pkb-table-modal-button" data-pkb-table-close aria-label="Close table view">${iconClose()}</button>
        </div>
        <div class="pkb-table-modal-stage" role="dialog" aria-modal="true" aria-label="Expanded table viewer">
          <div class="pkb-table-modal-content"></div>
        </div>
      `;
      document.body.appendChild(modal);
      stage = modal.querySelector('.pkb-table-modal-stage');
      content = modal.querySelector('.pkb-table-modal-content');

      modal.addEventListener('click', function (event) {
        if (event.target.closest('[data-pkb-table-close]')) closeModal();
        if (event.target.closest('[data-pkb-table-reset]')) fitTable();
        if (event.target.closest('[data-pkb-table-rotate]')) rotateTable();
      });

      stage.addEventListener('wheel', onWheel, { passive: false });
      stage.addEventListener('pointerdown', onPointerDown);
      stage.addEventListener('pointermove', onPointerMove);
      stage.addEventListener('pointerup', onPointerUp);
      stage.addEventListener('pointercancel', onPointerUp);
      stage.addEventListener('click', function (event) {
        if (event.target === stage && !state.moved) closeModal();
      });
      window.addEventListener('resize', function () {
        if (!modal.hidden) fitTable();
      });
      document.addEventListener('keydown', function (event) {
        if (!modal || modal.hidden || event.key !== 'Escape') return;
        closeModal();
      });
    }

    function transform() {
      if (!content) return;
      content.style.transform = `translate(-50%, -50%) translate(${state.x}px, ${state.y}px) rotate(${state.rotation}deg) scale(${state.scale})`;
    }

    function availableStageSize() {
      const stageRect = stage.getBoundingClientRect();
      const padding = 32;
      return {
        width: Math.max(stageRect.width - padding, 1),
        height: Math.max(stageRect.height - padding, 1)
      };
    }

    function fitMedia(element) {
      state.x = 0;
      state.y = 0;
      content.classList.remove('is-fit-layout');
      content.style.transform = 'translate(-50%, -50%)';

      const available = availableStageSize();
      const rect = element.getBoundingClientRect();
      const mediaWidth = rect.width || element.width || 1;
      const mediaHeight = rect.height || element.height || 1;
      const visualWidth = state.rotation % 180 === 0 ? mediaWidth : mediaHeight;
      const visualHeight = state.rotation % 180 === 0 ? mediaHeight : mediaWidth;

      state.fitScale = Math.min(
        available.width / visualWidth,
        available.height / visualHeight,
        1
      );
      state.scale = Math.max(state.fitScale, 0.08);
      transform();
    }

    function fitTable() {
      if (!stage || !content) return;

      const media = content.querySelector('.pkb-table-modal-canvas');
      if (media) {
        fitMedia(media);
        return;
      }

      state.x = 0;
      state.y = 0;
      const table = content.querySelector('table');
      if (!table) return;

      const available = availableStageSize();
      const availableWidth = available.width;
      const availableHeight = available.height;
      const layoutWidth = state.rotation % 180 === 0 ? availableWidth : availableHeight;
      let reflowRatio = 1;

      content.classList.add('is-fit-layout');
      content.style.setProperty('--pkb-table-viewer-width', `${layoutWidth}px`);
      content.style.setProperty('--pkb-table-viewer-ratio', reflowRatio);
      content.style.transform = 'translate(-50%, -50%)';

      for (let i = 0; i < 8; i += 1) {
        const rect = table.getBoundingClientRect();
        const visualWidth = state.rotation % 180 === 0 ? rect.width : rect.height;
        const visualHeight = state.rotation % 180 === 0 ? rect.height : rect.width;
        const nextRatio = Math.min(
          reflowRatio,
          reflowRatio * (availableWidth / Math.max(visualWidth, 1)),
          reflowRatio * (availableHeight / Math.max(visualHeight, 1)),
          1
        );

        if (nextRatio >= reflowRatio * 0.985) break;
        reflowRatio = Math.max(nextRatio, 0.12);
        content.style.setProperty('--pkb-table-viewer-ratio', reflowRatio.toFixed(4));
      }

      const reflowedRect = table.getBoundingClientRect();
      const reflowedWidth = reflowedRect.width || 1;
      const reflowedHeight = reflowedRect.height || 1;
      const reflowedVisualWidth = state.rotation % 180 === 0 ? reflowedWidth : reflowedHeight;
      const reflowedVisualHeight = state.rotation % 180 === 0 ? reflowedHeight : reflowedWidth;

      state.fitScale = Math.min(
        availableWidth / reflowedVisualWidth,
        availableHeight / reflowedVisualHeight,
        1
      );
      state.scale = Math.max(state.fitScale, 0.08);
      transform();
    }

    function captureScaleFor(width, height) {
      const desiredScale = 3;
      const maxSide = 3500;
      const maxPixels = 10000000;
      const sideScale = maxSide / Math.max(width, height, 1);
      const pixelScale = Math.sqrt(maxPixels / Math.max(width * height, 1));
      const scale = Math.min(desiredScale, sideScale, pixelScale);
      return scale >= 1 ? scale : null;
    }

    function tableDimensions(figure) {
      const rect = figure.getBoundingClientRect();
      const table = figure.querySelector('table');
      const tableRect = table ? table.getBoundingClientRect() : rect;
      return {
        width: Math.ceil(Math.max(figure.scrollWidth, table ? table.scrollWidth : 0, tableRect.width, rect.width, 1)),
        height: Math.ceil(Math.max(figure.scrollHeight, table ? table.scrollHeight : 0, tableRect.height, rect.height, 1))
      };
    }

    function waitForImages(root) {
      const images = Array.from(root.querySelectorAll('img')).filter(function (image) {
        return !image.complete;
      });
      if (!images.length) return Promise.resolve();

      return Promise.all(images.map(function (image) {
        return new Promise(function (resolve) {
          image.addEventListener('load', resolve, { once: true });
          image.addEventListener('error', resolve, { once: true });
        });
      }));
    }

    function annotationText(container) {
      const annotation = container.querySelector('annotation[encoding="application/x-tex"]');
      return annotation ? annotation.textContent : '';
    }

    function mathItemsForFigure(figure) {
      const containers = Array.from(figure.querySelectorAll('mjx-container'));
      const mathDocument = window.MathJax && window.MathJax.startup && window.MathJax.startup.document;
      if (!containers.length || !mathDocument || !mathDocument.math) return [];

      const items = Array.from(mathDocument.math);
      return containers.map(function (container) {
        const item = items.find(function (candidate) {
          return candidate.typesetRoot === container ||
            (candidate.start && candidate.start.node === container) ||
            (candidate.end && candidate.end.node === container);
        });
        return item ? { math: item.math, display: !!item.display } : null;
      });
    }

    function replaceMathForCapture(root, mathItems) {
      if (!window.katex || typeof window.katex.renderToString !== 'function') return;

      root.querySelectorAll('mjx-container').forEach(function (container, index) {
        const item = mathItems[index] || {};
        const tex = (item.math || annotationText(container)).trim();
        if (!tex) return;

        const displayMode = typeof item.display === 'boolean' ? item.display : container.getAttribute('display') === 'true';
        const wrapper = document.createElement(displayMode ? 'div' : 'span');
        wrapper.className = displayMode ? 'pkb-table-capture-math is-display' : 'pkb-table-capture-math';
        wrapper.innerHTML = window.katex.renderToString(tex, {
          displayMode,
          throwOnError: false,
          strict: 'ignore',
          output: 'html'
        });
        container.replaceWith(wrapper);
      });

      root.querySelectorAll('math').forEach(function (math) {
        const tex = (math.dataset.latex || annotationText(math)).trim();
        if (!tex) return;

        const wrapper = document.createElement('span');
        wrapper.className = 'pkb-table-capture-math';
        wrapper.innerHTML = window.katex.renderToString(tex, {
          displayMode: false,
          throwOnError: false,
          strict: 'ignore',
          output: 'html'
        });
        math.replaceWith(wrapper);
      });
    }

    async function createCaptureClone(figure) {
      const host = document.createElement('div');
      host.className = 'pkb-table-capture-host';
      host.setAttribute('aria-hidden', 'true');
      const mathItems = mathItemsForFigure(figure);

      const clone = figure.cloneNode(true);
      clone.querySelectorAll('.pkb-table-expand-button').forEach(function (button) {
        button.remove();
      });
      clone.removeAttribute('id');
      clone.classList.add('pkb-table-capture-figure');

      host.appendChild(clone);
      document.body.appendChild(host);

      if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
        await window.MathJax.typesetPromise([clone]).catch(function () {});
      }
      replaceMathForCapture(clone, mathItems);
      await waitForImages(clone);

      return { host, clone };
    }

    function renderLoading() {
      content.classList.remove('is-fit-layout', 'is-canvas-view');
      content.style.removeProperty('--pkb-table-viewer-width');
      content.style.removeProperty('--pkb-table-viewer-ratio');
      content.style.transform = 'translate(-50%, -50%)';
      content.replaceChildren();

      const loading = document.createElement('div');
      loading.className = 'pkb-table-modal-loading';
      loading.textContent = '고해상도 보기 생성 중';
      content.appendChild(loading);
    }

    function renderDomFallback(figure) {
      const clone = figure.cloneNode(true);
      clone.querySelectorAll('.pkb-table-expand-button').forEach(function (button) {
        button.remove();
      });
      clone.removeAttribute('id');
      clone.classList.add('pkb-table-modal-figure');
      content.classList.remove('is-canvas-view');
      content.replaceChildren(clone);
      fitTable();
    }

    async function renderCanvasView(figure, token) {
      if (typeof window.html2canvas !== 'function') {
        throw new Error('html2canvas is not available.');
      }

      if (document.fonts && document.fonts.ready) {
        await document.fonts.ready;
      }

      const capture = await createCaptureClone(figure);
      if (token !== state.renderToken || !modal || modal.hidden) {
        capture.host.remove();
        return;
      }

      const dimensions = tableDimensions(capture.clone);
      const scale = captureScaleFor(dimensions.width, dimensions.height);
      if (!scale) {
        capture.host.remove();
        throw new Error('Table is too large for high-resolution capture.');
      }

      let canvas;
      try {
        canvas = await window.html2canvas(capture.clone, {
          scale,
          backgroundColor: '#ffffff',
          useCORS: true,
          allowTaint: true,
          logging: false,
          imageTimeout: 8000,
          ignoreElements: function (element) {
            return element.classList && element.classList.contains('pkb-table-expand-button');
          },
          onclone: function (clonedDocument) {
            clonedDocument.querySelectorAll('.pkb-table-expand-button').forEach(function (button) {
              button.remove();
            });
            clonedDocument.querySelectorAll('figure.wp-block-table').forEach(function (clonedFigure) {
              clonedFigure.style.background = '#ffffff';
            });
          }
        });
      } finally {
        capture.host.remove();
      }

      if (token !== state.renderToken || !modal || modal.hidden) return;

      canvas.className = 'pkb-table-modal-canvas';
      canvas.setAttribute('aria-label', '고해상도 표 보기');
      content.classList.remove('is-fit-layout');
      content.classList.add('is-canvas-view');
      content.replaceChildren(canvas);
      fitTable();
    }

    function zoomAt(nextScale, clientX, clientY) {
      const stageRect = stage.getBoundingClientRect();
      const centerX = stageRect.left + stageRect.width / 2;
      const centerY = stageRect.top + stageRect.height / 2;
      const before = state.scale;
      const after = Math.min(Math.max(nextScale, state.fitScale * 0.6), 5);
      const ratio = after / before;
      state.x = clientX - centerX - (clientX - centerX - state.x) * ratio;
      state.y = clientY - centerY - (clientY - centerY - state.y) * ratio;
      state.scale = after;
      transform();
    }

    function onWheel(event) {
      event.preventDefault();
      const factor = event.deltaY > 0 ? 0.9 : 1.1;
      zoomAt(state.scale * factor, event.clientX, event.clientY);
    }

    function distance(points) {
      const [a, b] = points;
      return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    }

    function midpoint(points) {
      const [a, b] = points;
      return {
        x: (a.clientX + b.clientX) / 2,
        y: (a.clientY + b.clientY) / 2
      };
    }

    function onPointerDown(event) {
      event.preventDefault();
      stage.setPointerCapture(event.pointerId);
      state.pointers.set(event.pointerId, event);
      if (state.pointers.size === 1) {
        state.moved = false;
        state.dragging = true;
        state.dragStartX = event.clientX;
        state.dragStartY = event.clientY;
        state.startX = state.x;
        state.startY = state.y;
        stage.classList.add('is-dragging');
      } else if (state.pointers.size === 2) {
        const points = Array.from(state.pointers.values());
        state.pinchStartDistance = distance(points);
        state.pinchStartScale = state.scale;
        state.dragging = false;
      }
    }

    function onPointerMove(event) {
      if (!state.pointers.has(event.pointerId)) return;
      state.pointers.set(event.pointerId, event);
      if (state.pointers.size === 2) {
        event.preventDefault();
        state.moved = true;
        const points = Array.from(state.pointers.values());
        const nextDistance = distance(points);
        const mid = midpoint(points);
        if (state.pinchStartDistance > 0) {
          zoomAt(state.pinchStartScale * (nextDistance / state.pinchStartDistance), mid.x, mid.y);
        }
        return;
      }

      if (!state.dragging || state.pointers.size !== 1) return;
      event.preventDefault();
      if (Math.abs(event.clientX - state.dragStartX) > 3 || Math.abs(event.clientY - state.dragStartY) > 3) {
        state.moved = true;
      }
      state.x = state.startX + event.clientX - state.dragStartX;
      state.y = state.startY + event.clientY - state.dragStartY;
      transform();
    }

    function onPointerUp(event) {
      state.pointers.delete(event.pointerId);
      if (stage.hasPointerCapture(event.pointerId)) {
        stage.releasePointerCapture(event.pointerId);
      }
      if (state.pointers.size === 0) {
        state.dragging = false;
        stage.classList.remove('is-dragging');
      }
    }

    function rotateTable() {
      state.rotation = state.rotation === 0 ? 90 : 0;
      fitTable();
    }

    function openModal(figure) {
      ensureModal();
      activeFigure = figure;
      const token = state.renderToken + 1;
      state.renderToken = token;
      state.rotation = 0;
      state.pointers.clear();
      modal.hidden = false;
      document.body.classList.add('pkb-table-modal-open');
      renderLoading();
      renderCanvasView(figure, token).catch(function (error) {
        if (token !== state.renderToken || !modal || modal.hidden) return;
        window.console.warn('PKB table high-resolution viewer failed. Falling back to DOM viewer.', error);
        renderDomFallback(figure);
      });
      const close = modal.querySelector('[data-pkb-table-close]');
      if (close) close.focus({ preventScroll: true });
    }

    function closeModal() {
      if (!modal || modal.hidden) return;
      state.renderToken += 1;
      modal.hidden = true;
      content.replaceChildren();
      content.classList.remove('is-fit-layout', 'is-canvas-view');
      document.body.classList.remove('pkb-table-modal-open');
      if (activeFigure) {
        const wrapper = activeFigure.closest('.pkb-table-viewer-wrap');
        const button = wrapper ? wrapper.querySelector('.pkb-table-expand-button') : null;
        if (button) button.focus({ preventScroll: true });
      }
      activeFigure = null;
    }

    figures.forEach(function (figure, index) {
      if (figure.closest('.pkb-table-viewer-wrap')) return;

      const wrapper = document.createElement('div');
      wrapper.className = 'pkb-table-viewer-wrap pkb-table-viewer-ready';
      figure.parentNode.insertBefore(wrapper, figure);
      wrapper.appendChild(figure);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'pkb-table-expand-button';
      button.innerHTML = iconExpand();
      button.setAttribute('aria-label', `표 ${index + 1} 크게 보기`);
      button.addEventListener('click', function () {
        openModal(figure);
      });
      wrapper.appendChild(button);
    });
  }

  ready(function () {
    initTableViewer();

    document.querySelectorAll('.pkb-search-form').forEach(function (form) {
      const select = form.querySelector('.pkb-search-tag-select');
      const selected = form.querySelector('.pkb-search-selected-tags');
      const mode = form.querySelector('.pkb-search-tag-mode');
      if (!select || !selected || !mode) return;

      function updateModeVisibility() {
        const count = selected.querySelectorAll('.pkb-search-tag-chip').length;
        mode.hidden = count < 2;
      }

      function addTag(slug, label) {
        if (!slug) return;
        const exists = Array.from(selected.querySelectorAll('.pkb-search-tag-chip')).some(function (chip) {
          return chip.dataset.tag === slug;
        });
        if (exists) return;

        const chip = document.createElement('span');
        chip.className = 'pkb-search-tag-chip';
        chip.dataset.tag = slug;

        const text = document.createElement('span');
        text.textContent = `#${label}`;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('aria-label', `${label} 태그 제거`);
        remove.textContent = '×';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'tags[]';
        input.value = slug;

        chip.append(text, remove, input);
        selected.appendChild(chip);
        updateModeVisibility();
      }

      select.addEventListener('change', function () {
        const option = select.selectedOptions[0];
        if (!option || !option.value) return;
        addTag(option.value, option.textContent.trim());
        select.value = '';
      });

      selected.addEventListener('click', function (event) {
        const remove = event.target.closest('button');
        if (!remove) return;
        const chip = remove.closest('.pkb-search-tag-chip');
        if (chip) chip.remove();
        updateModeVisibility();
      });

      updateModeVisibility();
    });

    document.addEventListener('click', function (event) {
      const button = event.target.closest('[data-pkb-like]');
      if (!button) return;
      event.preventDefault();
      if (button.dataset.loginRequired === '1') {
        const config = window.PKB || {};
        if (window.confirm(config.loginRequiredMessage || '좋아요를 누르려면 로그인이 필요합니다. 로그인하시겠습니까?')) {
          window.location.href = config.loginUrl || '/login/';
        }
        return;
      }
      if (button.disabled) return;
      toggleLike(button);
    });

    function highlightHashTarget() {
      if (!window.location.hash) return;
      const rawId = window.location.hash.slice(1);
      const decodedId = decodeURIComponent(rawId);
      if (!rawId) return;
      const target = document.getElementById(rawId) || document.getElementById(decodedId);
      if (!target) return;
      target.classList.remove('pkb-heading-highlight');
      void target.offsetWidth;
      window.setTimeout(function () {
        target.classList.add('pkb-heading-highlight');
      }, 60);
      window.setTimeout(function () {
        target.classList.remove('pkb-heading-highlight');
      }, 1800);
    }

    highlightHashTarget();
    window.addEventListener('hashchange', highlightHashTarget);
  });
})();
