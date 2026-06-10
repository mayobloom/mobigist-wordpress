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

  ready(function () {
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
